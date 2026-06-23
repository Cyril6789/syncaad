<?php

if (!defined('GLPI_ROOT')) {
   die("Cannot access directly");
}

/**
 * Helper that maps an Entra ID (Microsoft Graph) user object to a GLPI user.
 *
 * It is shared by the synchronisation engine and the SSO login flow so that
 * both create/update GLPI accounts the same way (including e-mail handling
 * through glpi_useremails and default profile assignment).
 */
class PluginSyncaadUser {

   /**
    * Normalise a Microsoft Graph user object to the fields we care about.
    *
    * @param array $aad Raw Graph user object.
    * @return array{login:string, email:string, firstname:string, realname:string, active:int}
    */
   public static function normalize(array $aad): array {
      $login = $aad['userPrincipalName'] ?? ($aad['mail'] ?? '');
      $email = $aad['mail'] ?? ($aad['userPrincipalName'] ?? '');

      return [
         'login'     => trim((string) $login),
         'email'     => trim((string) $email),
         'firstname' => (string) ($aad['givenName'] ?? ''),
         'realname'  => (string) ($aad['surname'] ?? ''),
         // accountEnabled is not always returned (e.g. delegated /me); default to active.
         'active'    => array_key_exists('accountEnabled', $aad)
                         ? (!empty($aad['accountEnabled']) ? 1 : 0)
                         : 1,
      ];
   }

   /**
    * Find an existing GLPI user matching the given login (UPN) or e-mail.
    */
   public static function find(string $login, string $email): ?User {
      $user = new User();

      if ($login !== '' && $user->getFromDBbyName($login)) {
         return $user;
      }

      if ($email !== '') {
         global $DB;
         $row = $DB->request([
            'SELECT' => 'users_id',
            'FROM'   => 'glpi_useremails',
            'WHERE'  => ['email' => $email],
            'LIMIT'  => 1,
         ])->current();

         if ($row && $user->getFromDB((int) $row['users_id'])) {
            return $user;
         }
      }

      return null;
   }

   /**
    * Create or update a GLPI user from an Entra ID user object.
    *
    * @param array $aad          Raw Graph user object.
    * @param array $conn         The connection row (provides defaults).
    * @param bool  $allow_create Whether a missing user may be created.
    * @return User|null The persisted user, or null on failure / skip.
    */
   public static function upsert(array $aad, array $conn, bool $allow_create = true): ?User {
      $data = self::normalize($aad);

      if ($data['login'] === '' && $data['email'] === '') {
         return null;
      }

      $user = self::find($data['login'], $data['email']);

      if ($user === null) {
         if (!$allow_create) {
            return null;
         }

         $input = [
            'name'      => $data['login'] !== '' ? $data['login'] : $data['email'],
            'realname'  => $data['realname'],
            'firstname' => $data['firstname'],
            'is_active' => $data['active'],
            'authtype'  => Auth::EXTERNAL,
            '_no_history' => true,
         ];
         if ($data['email'] !== '') {
            $input['_useremails'] = [-1 => $data['email']];
         }
         $entity = (int) ($conn['entities_id'] ?? 0);
         if ($entity > 0) {
            $input['entities_id'] = $entity;
         }

         $user = new User();
         $id = $user->add($input);
         if (!$id) {
            return null;
         }

         self::ensureProfile((int) $id, $conn);
         $user->getFromDB((int) $id);

         return $user;
      }

      // Existing user: refresh identity fields.
      $user->update([
         'id'        => $user->getID(),
         'realname'  => $data['realname'],
         'firstname' => $data['firstname'],
         'is_active' => $data['active'],
         '_no_history' => true,
      ]);

      if ($data['email'] !== '') {
         self::ensureEmail($user->getID(), $data['email']);
      }

      return $user;
   }

   /**
    * Make sure the user has at least one profile so it can actually log in.
    */
   public static function ensureProfile(int $users_id, array $conn): void {
      if ($users_id <= 0) {
         return;
      }

      if (countElementsInTable('glpi_profiles_users', ['users_id' => $users_id]) > 0) {
         return;
      }

      $profile_id = (int) ($conn['default_profiles_id'] ?? 0);
      if ($profile_id <= 0) {
         $profile_id = (int) Profile::getDefault();
      }
      if ($profile_id <= 0) {
         return;
      }

      $entity = (int) ($conn['entities_id'] ?? 0);

      $pu = new Profile_User();
      $pu->add([
         'users_id'     => $users_id,
         'profiles_id'  => $profile_id,
         'entities_id'  => $entity,
         'is_recursive' => 1,
         'is_dynamic'   => 0,
      ]);
   }

   /**
    * Add an e-mail to the user if it is not already registered.
    */
   public static function ensureEmail(int $users_id, string $email): void {
      if ($users_id <= 0 || $email === '') {
         return;
      }

      if (countElementsInTable('glpi_useremails', ['users_id' => $users_id, 'email' => $email]) > 0) {
         return;
      }

      $useremail = new UserEmail();
      $useremail->add([
         'users_id'   => $users_id,
         'email'      => $email,
         'is_default' => countElementsInTable('glpi_useremails', ['users_id' => $users_id]) === 0 ? 1 : 0,
      ]);
   }
}
