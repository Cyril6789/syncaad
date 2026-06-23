<?php

if (!defined('GLPI_ROOT')) {
   die("Cannot access directly");
}

/**
 * OpenID Connect / OAuth2 Authorization Code flow against Entra ID, used to log
 * GLPI users in via SSO. Connections are shared with the synchronisation
 * feature (PluginSsomicrosoftConnection).
 */
class PluginSsomicrosoftSso {

   private const SESSION_STATE = 'plugin_ssomicrosoft_sso_state';
   private const SESSION_CONN  = 'plugin_ssomicrosoft_sso_conn';

   // Name of the short-lived cookie used as a fallback for the OAuth state.
   // The PHP session is not always preserved across the cross-site redirect
   // back from Microsoft (e.g. a SameSite=Strict session cookie is withheld on
   // the first top-level navigation coming from login.microsoftonline.com),
   // which made the first SSO attempt fail with an "invalid state" error while
   // the second one succeeded. This cookie carries the same information with an
   // explicit SameSite=Lax policy so it always survives the round-trip.
   private const COOKIE_STATE  = 'ssomicrosoft_sso_state';

   /** Compute the redirect URI declared in Entra ID for a connection. */
   public static function getRedirectUri(array $conn): string {
      global $CFG_GLPI;

      if (!empty($conn['redirect_uri'])) {
         return $conn['redirect_uri'];
      }
      return rtrim($CFG_GLPI['url_base'], '/') . '/plugins/ssomicrosoft/front/sso.php';
   }

   /** Step 1: redirect the browser to the Entra ID authorization endpoint. */
   public static function startLogin(int $connection_id): void {
      $conn = self::getConnection($connection_id, true);
      if ($conn === null) {
         self::fail(__('Connexion SSO introuvable ou désactivée.', 'ssomicrosoft'));
      }

      $state = bin2hex(random_bytes(16));
      $_SESSION[self::SESSION_STATE] = $state;
      $_SESSION[self::SESSION_CONN]  = $connection_id;

      // Fallback copy in a dedicated cookie, in case the PHP session is not
      // carried over when Microsoft redirects the browser back to us.
      self::setStateCookie($connection_id . ':' . $state);

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
               __('Erreur renvoyée par Entra ID : %s', 'ssomicrosoft'),
               (string) ($_GET['error_description'] ?? $_GET['error'])
            )
         );
      }

      $code  = (string) ($_GET['code'] ?? '');
      $state = (string) ($_GET['state'] ?? '');

      $expected_state = (string) ($_SESSION[self::SESSION_STATE] ?? '');
      $connection_id  = (int) ($_SESSION[self::SESSION_CONN] ?? 0);
      unset($_SESSION[self::SESSION_STATE], $_SESSION[self::SESSION_CONN]);

      // When the session did not survive the redirect, recover the expected
      // state and connection id from the fallback cookie.
      if ($expected_state === '' && isset($_COOKIE[self::COOKIE_STATE])) {
         [$cookie_conn, $cookie_state] = array_pad(
            explode(':', (string) $_COOKIE[self::COOKIE_STATE], 2),
            2,
            ''
         );
         $expected_state = (string) $cookie_state;
         if ($connection_id === 0) {
            $connection_id = (int) $cookie_conn;
         }
      }
      self::clearStateCookie();

      if ($code === '' || $state === '' || $expected_state === '' || !hash_equals($expected_state, $state)) {
         self::fail(__('Requête SSO invalide (state).', 'ssomicrosoft'));
      }

      $conn = self::getConnection($connection_id, true);
      if ($conn === null) {
         self::fail(__('Connexion SSO introuvable ou désactivée.', 'ssomicrosoft'));
      }

      $token = self::exchangeCode($conn, $code);
      if (!$token || empty($token['access_token'])) {
         self::fail(__("Échec de l'échange du code d'autorisation.", 'ssomicrosoft'));
      }

      $me = self::fetchMe($token['access_token']);
      if (!$me) {
         self::fail(__('Impossible de récupérer le profil utilisateur depuis Microsoft Graph.', 'ssomicrosoft'));
      }

      $data = PluginSsomicrosoftUser::normalize($me);
      if (!self::matchesDomain($conn, $data['email']) && !self::matchesDomain($conn, $data['login'])) {
         self::fail(__('Ce compte ne correspond pas au domaine autorisé pour cette connexion.', 'ssomicrosoft'));
      }

      $allow_create = !empty($conn['auto_register']);
      $user = PluginSsomicrosoftUser::upsert($me, $conn, $allow_create);
      if ($user === null) {
         self::fail(__("Aucun compte GLPI ne correspond et la création automatique est désactivée.", 'ssomicrosoft'));
      }

      if (!$user->fields['is_active'] || $user->fields['is_deleted']) {
         self::fail(__('Ce compte est désactivé dans GLPI.', 'ssomicrosoft'));
      }

      if (!self::login($user)) {
         self::fail(__('La connexion à GLPI a échoué (aucune habilitation valide ?).', 'ssomicrosoft'));
      }

      self::redirectHome();
   }

   /**
    * Send the freshly authenticated user to the GLPI home page.
    *
    * We deliberately use a client-side redirect instead of an HTTP redirect.
    * The just-issued GLPI session cookie may carry a SameSite policy that the
    * browser would withhold on a server redirect chained from the cross-site
    * Microsoft callback, which left the user back on the login page on the
    * first attempt (a second attempt then "just worked"). Rendering an HTML
    * page first turns the subsequent navigation into a fresh same-site request,
    * so the authenticated session cookie is always sent.
    */
   private static function redirectHome(): void {
      global $CFG_GLPI;

      $url = $CFG_GLPI['root_doc'] . '/';

      Html::nullHeader(__('SSO Microsoft', 'ssomicrosoft'));
      echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url) . '">';
      echo '<script type="text/javascript">window.location.replace(' . json_encode($url) . ');</script>';
      echo '<div class="center">'
         . '<a href="' . htmlspecialchars($url) . '">' . __('Continuer', 'ssomicrosoft') . '</a>'
         . '</div>';
      Html::nullFooter();
      exit;
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
    * PluginSsomicrosoftUser::upsert(), so we initialise the session directly from the
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

   /**
    * Check that a value ends with one of the connection's domain filters.
    *
    * The filter may list several domains separated by a comma or a semicolon
    * (e.g. "@contoso.com, @fabrikam.com"); the value matches if it ends with
    * any of them. An empty filter accepts everything.
    */
   private static function matchesDomain(array $conn, string $value): bool {
      $filters = PluginSsomicrosoftConnection::parseEmailFilters($conn['email_filter'] ?? '');
      if (empty($filters)) {
         return true;
      }
      if ($value === '') {
         return false;
      }

      $value = strtolower($value);
      foreach ($filters as $domain) {
         if (str_ends_with($value, $domain)) {
            return true;
         }
      }
      return false;
   }

   /** Load an Entra ID connection, optionally requiring SSO to be enabled. */
   private static function getConnection(int $id, bool $require_sso): ?array {
      global $DB;

      $where = ['id' => $id, 'active' => 1];
      if ($require_sso) {
         $where['sso_enabled'] = 1;
      }

      $row = $DB->request([
         'FROM'  => 'glpi_plugin_ssomicrosoft_connections',
         'WHERE' => $where,
         'LIMIT' => 1,
      ])->current();

      return $row ?: null;
   }

   /** Display an error on a minimal page and stop. */
   private static function fail(string $message): void {
      Html::nullHeader(__('SSO Microsoft', 'ssomicrosoft'));
      echo '<div class="center b">' . htmlspecialchars($message) . '</div>';
      echo '<div class="center"><a href="' . htmlspecialchars($GLOBALS['CFG_GLPI']['root_doc'] . '/') . '">'
         . __('Retour', 'ssomicrosoft') . '</a></div>';
      Html::nullFooter();
      exit;
   }

   /** Path the SSO fallback cookie is scoped to. */
   private static function cookiePath(): string {
      global $CFG_GLPI;

      $root = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
      return $root . '/plugins/ssomicrosoft/front/';
   }

   /** Store the OAuth state in a short-lived, SameSite=Lax cookie. */
   private static function setStateCookie(string $value): void {
      global $CFG_GLPI;

      if (headers_sent()) {
         return;
      }

      $secure = str_starts_with((string) ($CFG_GLPI['url_base'] ?? ''), 'https');
      setcookie(self::COOKIE_STATE, $value, [
         'expires'  => time() + 600,
         'path'     => self::cookiePath(),
         'secure'   => $secure,
         'httponly' => true,
         'samesite' => 'Lax',
      ]);
   }

   /** Remove the SSO fallback cookie. */
   private static function clearStateCookie(): void {
      unset($_COOKIE[self::COOKIE_STATE]);

      if (headers_sent()) {
         return;
      }

      setcookie(self::COOKIE_STATE, '', [
         'expires'  => time() - 3600,
         'path'     => self::cookiePath(),
         'httponly' => true,
         'samesite' => 'Lax',
      ]);
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
