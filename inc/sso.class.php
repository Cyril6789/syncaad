<?php

if (!defined('GLPI_ROOT')) {
   die("Cannot access directly");
}

/**
 * OpenID Connect / OAuth2 Authorization Code flow against Entra ID, used to log
 * GLPI users in via SSO. Connections are shared with the synchronisation
 * feature (PluginSyncaadConnection).
 */
class PluginSyncaadSso {

   private const SESSION_STATE = 'plugin_syncaad_sso_state';
   private const SESSION_CONN  = 'plugin_syncaad_sso_conn';

   /** Compute the redirect URI declared in Entra ID for a connection. */
   public static function getRedirectUri(array $conn): string {
      global $CFG_GLPI;

      if (!empty($conn['redirect_uri'])) {
         return $conn['redirect_uri'];
      }
      return rtrim($CFG_GLPI['url_base'], '/') . '/plugins/syncaad/front/sso.php';
   }

   /** Step 1: redirect the browser to the Entra ID authorization endpoint. */
   public static function startLogin(int $connection_id): void {
      $conn = self::getConnection($connection_id, true);
      if ($conn === null) {
         self::fail(__('Connexion SSO introuvable ou désactivée.', 'syncaad'));
      }

      $state = bin2hex(random_bytes(16));
      $_SESSION[self::SESSION_STATE] = $state;
      $_SESSION[self::SESSION_CONN]  = $connection_id;

      $params = [
         'client_id'     => $conn['client_id'],
         'response_type' => 'code',
         'redirect_uri'  => self::getRedirectUri($conn),
         'response_mode' => 'query',
         'scope'         => 'openid profile email User.Read',
         'state'         => $state,
      ];

      $url = 'https://login.microsoftonline.com/' . rawurlencode($conn['tenant_id'])
           . '/oauth2/v2.0/authorize?' . http_build_query($params);

      Html::redirect($url);
   }

   /** Step 2: handle the redirect back from Entra ID. */
   public static function handleCallback(): void {
      global $CFG_GLPI;

      if (!empty($_GET['error'])) {
         self::fail(
            sprintf(
               __('Erreur renvoyée par Entra ID : %s', 'syncaad'),
               (string) ($_GET['error_description'] ?? $_GET['error'])
            )
         );
      }

      $code  = (string) ($_GET['code'] ?? '');
      $state = (string) ($_GET['state'] ?? '');

      $expected_state = (string) ($_SESSION[self::SESSION_STATE] ?? '');
      $connection_id  = (int) ($_SESSION[self::SESSION_CONN] ?? 0);
      unset($_SESSION[self::SESSION_STATE], $_SESSION[self::SESSION_CONN]);

      if ($code === '' || $state === '' || $expected_state === '' || !hash_equals($expected_state, $state)) {
         self::fail(__('Requête SSO invalide (state).', 'syncaad'));
      }

      $conn = self::getConnection($connection_id, true);
      if ($conn === null) {
         self::fail(__('Connexion SSO introuvable ou désactivée.', 'syncaad'));
      }

      $token = self::exchangeCode($conn, $code);
      if (!$token || empty($token['access_token'])) {
         self::fail(__("Échec de l'échange du code d'autorisation.", 'syncaad'));
      }

      $me = self::fetchMe($token['access_token']);
      if (!$me) {
         self::fail(__('Impossible de récupérer le profil utilisateur depuis Microsoft Graph.', 'syncaad'));
      }

      $data = PluginSyncaadUser::normalize($me);
      if (!self::matchesDomain($conn, $data['email']) && !self::matchesDomain($conn, $data['login'])) {
         self::fail(__('Ce compte ne correspond pas au domaine autorisé pour cette connexion.', 'syncaad'));
      }

      $allow_create = !empty($conn['auto_register']);
      $user = PluginSyncaadUser::upsert($me, $conn, $allow_create);
      if ($user === null) {
         self::fail(__("Aucun compte GLPI ne correspond et la création automatique est désactivée.", 'syncaad'));
      }

      if (!$user->fields['is_active'] || $user->fields['is_deleted']) {
         self::fail(__('Ce compte est désactivé dans GLPI.', 'syncaad'));
      }

      if (!self::login($user)) {
         self::fail(__('La connexion à GLPI a échoué (aucune habilitation valide ?).', 'syncaad'));
      }

      Html::redirect($CFG_GLPI['root_doc'] . '/');
   }

   /** Exchange the authorization code for tokens. */
   private static function exchangeCode(array $conn, string $code): ?array {
      $url = 'https://login.microsoftonline.com/' . rawurlencode($conn['tenant_id']) . '/oauth2/v2.0/token';

      $response = self::httpPost($url, [
         'client_id'     => $conn['client_id'],
         'client_secret' => $conn['client_secret'],
         'grant_type'    => 'authorization_code',
         'code'          => $code,
         'redirect_uri'  => self::getRedirectUri($conn),
         'scope'         => 'openid profile email User.Read',
      ]);
      if ($response === null) {
         return null;
      }

      $token = json_decode($response, true);
      return is_array($token) ? $token : null;
   }

   /** Fetch the signed-in user's profile from Microsoft Graph. */
   private static function fetchMe(string $access_token): ?array {
      $url = 'https://graph.microsoft.com/v1.0/me?$select=id,displayName,mail,userPrincipalName,givenName,surname';

      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
         'Authorization: Bearer ' . $access_token,
         'Content-Type: application/json',
      ]);
      curl_setopt($ch, CURLOPT_TIMEOUT, 30);
      $response = curl_exec($ch);
      $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);

      if ($response === false || $status < 200 || $status >= 300) {
         return null;
      }

      $data = json_decode((string) $response, true);
      return is_array($data) ? $data : null;
   }

   /**
    * Establish an authenticated GLPI session for the given user.
    *
    * The account (and at least one profile) is already provisioned by
    * PluginSyncaadUser::upsert(), so we initialise the session directly from the
    * user object, marking it as an external authentication.
    */
   private static function login(User $user): bool {
      $auth = new Auth();
      $auth->user          = $user;
      $auth->auth_succeded = true;
      $auth->extauth       = 1;
      $auth->user_present  = true;

      Session::init($auth);

      return (bool) Session::getLoginUserID();
   }

   /** Check that a value ends with the connection's domain filter. */
   private static function matchesDomain(array $conn, string $value): bool {
      $filter = trim((string) ($conn['email_filter'] ?? ''));
      if ($filter === '') {
         return true;
      }
      if ($value === '') {
         return false;
      }
      return str_ends_with(strtolower($value), strtolower($filter));
   }

   /** Load an Entra ID connection, optionally requiring SSO to be enabled. */
   private static function getConnection(int $id, bool $require_sso): ?array {
      global $DB;

      $where = ['id' => $id, 'active' => 1];
      if ($require_sso) {
         $where['sso_enabled'] = 1;
      }

      $row = $DB->request([
         'FROM'  => 'glpi_plugin_syncaad_connections',
         'WHERE' => $where,
         'LIMIT' => 1,
      ])->current();

      return $row ?: null;
   }

   /** Display an error on a minimal page and stop. */
   private static function fail(string $message): void {
      Html::nullHeader(__('Synchro AAD', 'syncaad'));
      echo '<div class="center b">' . htmlspecialchars($message) . '</div>';
      echo '<div class="center"><a href="' . htmlspecialchars($GLOBALS['CFG_GLPI']['root_doc'] . '/') . '">'
         . __('Retour', 'syncaad') . '</a></div>';
      Html::nullFooter();
      exit;
   }

   /** Perform an HTTP POST (form-encoded) request, returning the body or null. */
   private static function httpPost(string $url, array $params): ?string {
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
      curl_setopt($ch, CURLOPT_TIMEOUT, 30);
      $response = curl_exec($ch);
      $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);

      if ($response === false || $status < 200 || $status >= 300) {
         return null;
      }
      return (string) $response;
   }
}
