<?php

if (!defined('GLPI_ROOT')) {
   die("Cannot access directly");
}

/**
 * Synchronisation engine: pulls users from Entra ID (Microsoft Graph) using the
 * client-credentials flow and reflects them into GLPI.
 */
class PluginSyncaadSync {

   /** Synchronise every active connection. */
   public static function syncAll(): void {
      global $DB;

      foreach ($DB->request(['FROM' => 'glpi_plugin_syncaad_connections', 'WHERE' => ['active' => 1]]) as $conn) {
         self::syncConnection($conn);
      }
   }

   /** Synchronise a single connection (full pull). */
   public static function syncConnection(array $conn): void {
      $users = self::fetchUsersFromEntra($conn);

      foreach ($users as $user) {
         PluginSyncaadUser::upsert($user, $conn, true);
      }

      if (!empty($conn['delete_missing']) || !empty($conn['disable_if_disabled'])) {
         self::cleanupUsers($conn, $users);
      }
   }

   /** Refresh a single GLPI user from Entra ID. */
   public static function syncSingleUser(int $user_id, int $connection_id = 0): void {
      global $DB;

      $crit = $connection_id ? ['id' => $connection_id] : ['active' => 1];
      $conn = $DB->request(['FROM' => 'glpi_plugin_syncaad_connections', 'WHERE' => $crit, 'LIMIT' => 1])->current();
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
         PluginSyncaadUser::upsert($aad, $conn, false);
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
         return [];
      }

      $select = 'id,displayName,mail,userPrincipalName,givenName,surname,accountEnabled';
      $url    = 'https://graph.microsoft.com/v1.0/users?$select=' . $select . '&$top=999';

      if (!empty($conn['email_filter'])) {
         $filter = rawurlencode("endsWith(mail,'" . str_replace("'", "''", $conn['email_filter']) . "')");
         // Advanced query (endsWith) requires ConsistencyLevel + $count.
         $url   .= '&$count=true&$filter=' . $filter;
      }

      $users = [];
      $guard = 0;
      while ($url && $guard < 1000) {
         $guard++;
         $response = self::httpGet($url, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'ConsistencyLevel: eventual',
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
         return false;
      }

      $token = json_decode($response, true);
      return $token['access_token'] ?? false;
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
      if (!empty($conn['email_filter'])) {
         $where[] = ['glpi_useremails.email' => ['LIKE', '%' . $conn['email_filter']]];
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

   /** Perform an HTTP GET request, returning the body or null on failure. */
   private static function httpGet(string $url, array $headers = []): ?string {
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($ch, CURLOPT_TIMEOUT, 30);
      $response = curl_exec($ch);
      $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);

      if ($response === false || $status < 200 || $status >= 300) {
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
      curl_close($ch);

      if ($response === false || $status < 200 || $status >= 300) {
         return null;
      }
      return (string) $response;
   }
}
