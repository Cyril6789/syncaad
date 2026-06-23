<?php

if (!defined('GLPI_ROOT')) {
   die("Cannot access directly");
}

/**
 * Synchronisation engine: pulls users from Entra ID (Microsoft Graph) using the
 * client-credentials flow and reflects them into GLPI.
 */
class PluginSsomicrosoftSync {

   /** Synchronise every active connection. Returns the number of users processed. */
   public static function syncAll(): int {
      global $DB;

      $total = 0;
      foreach ($DB->request(['FROM' => 'glpi_plugin_ssomicrosoft_connections', 'WHERE' => ['active' => 1]]) as $conn) {
         $total += self::syncConnection($conn);
      }
      return $total;
   }

   /** Synchronise a single connection (full pull). Returns the number of users processed. */
   public static function syncConnection(array $conn): int {
      return self::runConnection($conn)['scoped'];
   }

   /**
    * Synchronise a single connection and return diagnostic counts.
    *
    * @return array{fetched:int, scoped:int} fetched = users received from Entra,
    *         scoped = users kept after the domain filter (and processed).
    */
   public static function runConnection(array $conn): array {
      $users   = self::fetchUsersFromEntra($conn);
      $domains = PluginSsomicrosoftConnection::parseEmailFilters($conn['email_filter'] ?? '');
      $scoped  = self::filterByDomain($users, $domains);

      foreach ($scoped as $user) {
         PluginSsomicrosoftUser::upsert($user, $conn, true);
      }

      if (!empty($conn['delete_missing']) || !empty($conn['disable_if_disabled'])) {
         self::cleanupUsers($conn, $scoped);
      }

      // Key diagnostic line: "X reçus d'Entra, Y après filtre de domaine".
      // 0 reçus => problème d'authentification/permission (voir lignes GET/POST).
      // Beaucoup reçus mais 0 après filtre => filtre de domaine trop restrictif.
      self::log(sprintf(
         'Connexion "%s" (id %d) : %d compte(s) reçu(s) d\'Entra, %d après filtre de domaine [%s].',
         (string) ($conn['name'] ?? '?'),
         (int) ($conn['id'] ?? 0),
         count($users),
         count($scoped),
         implode(', ', $domains) ?: 'aucun filtre'
      ));

      return ['fetched' => count($users), 'scoped' => count($scoped)];
   }

   /**
    * Keep only the Entra users whose domain matches the connection filter.
    *
    * An empty filter keeps every user. A user matches if any of its e-mail
    * carrying attributes (mail, userPrincipalName, otherMails, proxyAddresses
    * aliases) ends with one of the configured domains.
    *
    * @param array<int, array> $users
    * @param string[]          $domains
    * @return array<int, array>
    */
   private static function filterByDomain(array $users, array $domains): array {
      if (empty($domains)) {
         return $users;
      }

      $scoped = [];
      foreach ($users as $user) {
         if (self::userMatchesDomains($user, $domains)) {
            $scoped[] = $user;
         }
      }
      return $scoped;
   }

   /** Does any e-mail-bearing attribute of the user end with one of the domains? */
   private static function userMatchesDomains(array $user, array $domains): bool {
      $candidates = [];

      foreach (['mail', 'userPrincipalName'] as $key) {
         if (!empty($user[$key])) {
            $candidates[] = strtolower((string) $user[$key]);
         }
      }
      foreach ((array) ($user['otherMails'] ?? []) as $address) {
         if ($address !== '') {
            $candidates[] = strtolower((string) $address);
         }
      }
      foreach ((array) ($user['proxyAddresses'] ?? []) as $address) {
         // Entries look like "SMTP:user@domain" (primary) or "smtp:alias@domain".
         $address = preg_replace('/^smtp:/i', '', (string) $address);
         if ($address !== '') {
            $candidates[] = strtolower($address);
         }
      }

      foreach ($candidates as $value) {
         foreach ($domains as $domain) {
            if (str_ends_with($value, $domain)) {
               return true;
            }
         }
      }
      return false;
   }

   /**
    * Description shown for the plugin's automatic actions (GLPI cron).
    *
    * @param string $name
    * @return array<string, string>
    */
   public static function cronInfo($name): array {
      if ($name === 'ssomicrosoft') {
         return ['description' => __('Synchronisation des comptes depuis Entra ID', 'ssomicrosoft')];
      }
      return [];
   }

   /**
    * GLPI automatic action: synchronise every active connection.
    *
    * Registered as a CronTask so it can be scheduled and monitored from
    * Configuration > Automatic actions, and driven by GLPI's own cron (handy
    * for dockerised setups where GLPI's cron already runs).
    *
    * @param CronTask $task
    * @return int 1 if users were processed, 0 if nothing to do, -1 on error.
    */
   public static function cronSsomicrosoft(CronTask $task): int {
      global $DB;

      $connections = 0;
      $fetched     = 0;
      $scoped      = 0;
      foreach ($DB->request(['FROM' => 'glpi_plugin_ssomicrosoft_connections', 'WHERE' => ['active' => 1]]) as $conn) {
         $result   = self::runConnection($conn);
         $fetched += $result['fetched'];
         $scoped  += $result['scoped'];
         $connections++;
         $task->addVolume($result['scoped']);

         $task->log(sprintf(
            'Connexion "%s" : %d reçus d\'Entra, %d après filtre.',
            (string) ($conn['name'] ?? '?'),
            $result['fetched'],
            $result['scoped']
         ));
      }

      $task->log(sprintf(
         '%d connexion(s) ; %d compte(s) reçus d\'Entra, %d traités après filtre. '
         . 'Si 0 reçu : vérifiez la permission Application "User.Read.All" (+ consentement admin). '
         . 'Détails dans files/_log/ssomicrosoft.log.',
         $connections,
         $fetched,
         $scoped
      ));

      return $connections > 0 ? 1 : 0;
   }

   /** Refresh a single GLPI user from Entra ID. */
   public static function syncSingleUser(int $user_id, int $connection_id = 0): void {
      global $DB;

      $crit = $connection_id ? ['id' => $connection_id] : ['active' => 1];
      $conn = $DB->request(['FROM' => 'glpi_plugin_ssomicrosoft_connections', 'WHERE' => $crit, 'LIMIT' => 1])->current();
      if (!$conn) {
         return;
      }

      $user = new User();
      if (!$user->getFromDB($user_id)) {
         return;
      }

      $email = self::getUserPrimaryEmail($user_id);
      if ($email === '') {
         return;
      }

      $aad = self::fetchUserByEmail($conn, $email);
      if ($aad) {
         PluginSsomicrosoftUser::upsert($aad, $conn, false);
      }
   }

   /**
    * Pull all users from Entra ID, following pagination (@odata.nextLink).
    *
    * @return array<int, array> List of Graph user objects.
    */
   private static function fetchUsersFromEntra(array $conn): array {
      $token = self::getAccessToken($conn);
      if (!$token) {
         self::log('Aucun jeton d\'accès : synchronisation impossible pour cette connexion.');
         return [];
      }

      // Select every attribute that can carry the account's domain, including
      // aliases (proxyAddresses) and secondary addresses (otherMails). The
      // domain filtering is then done client-side (see filterByDomain) so an
      // account is matched whatever attribute carries the domain — server-side
      // $filter on `mail`/UPN alone misses accounts whose domain is only an
      // alias/proxy address.
      $select = 'id,displayName,mail,userPrincipalName,givenName,surname,'
              . 'accountEnabled,otherMails,proxyAddresses';
      $url    = 'https://graph.microsoft.com/v1.0/users?$select=' . $select . '&$top=999';

      $users = [];
      $guard = 0;
      while ($url && $guard < 1000) {
         $guard++;
         $response = self::httpGet($url, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
         ]);
         if ($response === null) {
            break;
         }

         $data = json_decode($response, true);
         if (!is_array($data)) {
            break;
         }

         foreach (($data['value'] ?? []) as $user) {
            $users[] = $user;
         }

         $url = $data['@odata.nextLink'] ?? null;
      }

      return $users;
   }

   /** Fetch a single Entra ID user by e-mail. */
   private static function fetchUserByEmail(array $conn, string $email): ?array {
      $token = self::getAccessToken($conn);
      if (!$token) {
         return null;
      }

      $select = 'id,displayName,mail,userPrincipalName,givenName,surname,accountEnabled';
      $filter = rawurlencode("mail eq '" . str_replace("'", "''", $email) . "'");
      $url    = 'https://graph.microsoft.com/v1.0/users?$filter=' . $filter . '&$select=' . $select;

      $response = self::httpGet($url, [
         'Authorization: Bearer ' . $token,
         'Content-Type: application/json',
      ]);
      if ($response === null) {
         return null;
      }

      $data = json_decode($response, true);
      return $data['value'][0] ?? null;
   }

   /** Obtain an application access token (client-credentials flow). */
   public static function getAccessToken(array $conn) {
      $url = 'https://login.microsoftonline.com/' . rawurlencode($conn['tenant_id']) . '/oauth2/v2.0/token';

      $response = self::httpPost($url, [
         'client_id'     => $conn['client_id'],
         'client_secret' => $conn['client_secret'],
         'scope'         => 'https://graph.microsoft.com/.default',
         'grant_type'    => 'client_credentials',
      ]);
      if ($response === null) {
         self::log('Échec de récupération du jeton (client credentials). Vérifiez tenant/client/secret.');
         return false;
      }

      $token = json_decode($response, true);
      if (empty($token['access_token'])) {
         self::log('Réponse du jeton sans access_token : ' . substr($response, 0, 500));
         return false;
      }
      return $token['access_token'];
   }

   /**
    * Disable or delete GLPI users that are no longer present in Entra ID for
    * the connection's domain filter.
    */
   private static function cleanupUsers(array $conn, array $aadUsers): void {
      global $DB;

      $aadMails = [];
      foreach ($aadUsers as $u) {
         $email = $u['mail'] ?? ($u['userPrincipalName'] ?? '');
         if ($email !== '') {
            $aadMails[strtolower($email)] = true;
         }
      }

      // Nothing fetched from Entra: do not touch local accounts (avoids mass
      // disabling on a transient API error).
      if (empty($aadMails)) {
         return;
      }

      $where = ['glpi_users.is_deleted' => 0];
      $domains = PluginSsomicrosoftConnection::parseEmailFilters($conn['email_filter'] ?? '');
      if (!empty($domains)) {
         $or = [];
         foreach ($domains as $domain) {
            $or[] = ['glpi_useremails.email' => ['LIKE', '%' . $domain]];
         }
         $where[] = ['OR' => $or];
      }

      $iterator = $DB->request([
         'SELECT'     => ['glpi_users.id AS uid', 'glpi_useremails.email AS email'],
         'FROM'       => 'glpi_useremails',
         'INNER JOIN' => [
            'glpi_users' => [
               'ON' => [
                  'glpi_useremails' => 'users_id',
                  'glpi_users'      => 'id',
               ],
            ],
         ],
         'WHERE'      => $where,
      ]);

      foreach ($iterator as $row) {
         if (isset($aadMails[strtolower($row['email'])])) {
            continue;
         }

         $user = new User();
         if (!$user->getFromDB((int) $row['uid'])) {
            continue;
         }

         if (!empty($conn['delete_missing'])) {
            $user->delete(['id' => $user->getID()]);
         } elseif (!empty($conn['disable_if_disabled'])) {
            $user->update(['id' => $user->getID(), 'is_active' => 0, '_no_history' => true]);
         }
      }
   }

   /** Return the primary e-mail of a GLPI user (or the first one found). */
   private static function getUserPrimaryEmail(int $users_id): string {
      global $DB;

      $row = $DB->request([
         'SELECT' => 'email',
         'FROM'   => 'glpi_useremails',
         'WHERE'  => ['users_id' => $users_id],
         'ORDER'  => 'is_default DESC',
         'LIMIT'  => 1,
      ])->current();

      return $row ? (string) $row['email'] : '';
   }

   /** Write a diagnostic line to the plugin log file (files/_log/ssomicrosoft.log). */
   private static function log(string $message): void {
      Toolbox::logInFile('ssomicrosoft', $message . "\n");
   }

   /** Perform an HTTP GET request, returning the body or null on failure. */
   private static function httpGet(string $url, array $headers = []): ?string {
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($ch, CURLOPT_TIMEOUT, 30);
      $response = curl_exec($ch);
      $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $error    = curl_error($ch);
      curl_close($ch);

      if ($response === false) {
         self::log("GET cURL error: {$error} — {$url}");
         return null;
      }
      if ($status < 200 || $status >= 300) {
         self::log("GET HTTP {$status} — {$url} — " . substr((string) $response, 0, 800));
         return null;
      }
      return (string) $response;
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
      $error    = curl_error($ch);
      curl_close($ch);

      if ($response === false) {
         self::log("POST cURL error: {$error} — {$url}");
         return null;
      }
      if ($status < 200 || $status >= 300) {
         self::log("POST HTTP {$status} — {$url} — " . substr((string) $response, 0, 800));
         return null;
      }
      return (string) $response;
   }
}
