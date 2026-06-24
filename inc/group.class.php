<?php

if (!defined('GLPI_ROOT')) {
   die("Cannot access directly");
}

/**
 * Reflects an Entra ID user's group memberships into GLPI, mirroring what the
 * native LDAP/AD authentication does.
 *
 * GLPI's LDAP login does two things with a user's groups (see
 * User::getFromLDAP()):
 *   1. it links the user to the GLPI groups configured in their "Liaison
 *      annuaire LDAP" tab (fields ldap_group_dn, or ldap_field + ldap_value),
 *      creating dynamic Group_User entries;
 *   2. it feeds the resulting GLPI group ids to the "Règles d'affectation
 *      d'habilitations" engine (RuleRightCollection), which assigns profiles
 *      and entities.
 *
 * This helper reproduces both behaviours from the groups returned by Microsoft
 * Graph, so an Entra account gets the same automatic habilitations as an LDAP
 * one. No GLPI group is ever created: only existing groups carrying a linkage
 * are matched — which also makes the whole feature opt-in (it is a no-op until
 * an administrator fills the "Liaison annuaire LDAP" fields of a group).
 */
class PluginSsomicrosoftGroup {

   /**
    * Does at least one GLPI group carry an LDAP linkage we can match against?
    *
    * When none do, there is nothing to map: callers can skip the (costly)
    * Microsoft Graph group lookup entirely and leave habilitations untouched,
    * preserving the plugin's previous behaviour.
    */
   public static function hasMappings(): bool {
      return countElementsInTable('glpi_groups', [
         'OR' => [
            ['ldap_group_dn' => ['<>', '']],
            ['ldap_field'    => ['<>', '']],
         ],
      ]) > 0;
   }

   /**
    * Apply an Entra user's group memberships to their GLPI account.
    *
    * @param int   $users_id    GLPI user id (already provisioned).
    * @param array $entraGroups List of Graph group objects, each typically with
    *                           id, displayName, onPremisesDistinguishedName and
    *                           onPremisesSamAccountName.
    */
   public static function apply(int $users_id, array $entraGroups): void {
      if ($users_id <= 0) {
         return;
      }

      $user = new User();
      if (!$user->getFromDB($users_id)) {
         return;
      }

      // 1. Resolve the GLPI groups the user should belong to, from the linkage
      //    configured on each group (DN match or field/value match).
      $group_ids = self::matchGlpiGroupIds($entraGroups);

      // Diagnostic line: tells whether groups were received from Graph at all
      // (0 received => GroupMember.Read.All probably missing / not consented)
      // and whether any GLPI linkage matched (received > 0 but matched 0 =>
      // the "DN du groupe" / ldap_value does not match the listed identifiers).
      self::log(sprintf(
         'Groupes pour %s (user #%d) : %d reçu(s) d\'Entra [%s] → %d groupe(s) GLPI rapproché(s)%s.',
         (string) ($user->fields['name'] ?? '?'),
         $users_id,
         count($entraGroups),
         self::describeEntraGroups($entraGroups),
         count($group_ids),
         $group_ids ? ' (groups_id: ' . implode(', ', $group_ids) . ')' : ''
      ));

      // 2. Run the authorization rules ("Règles d'affectation d'habilitations")
      //    with those group ids, exactly like User::getFromLDAP() does. Groups
      //    assigned by a rule are returned in _ldap_rules['groups_id'] and must
      //    be preserved by the Group_User sync below.
      $rules  = new RuleRightCollection();
      $result = $rules->processAllRules($group_ids, $user->fields, [
         'type'  => $user->fields['authtype'],
         'login' => $user->fields['name'],
         'email' => UserEmail::getDefaultForUser($users_id),
      ]);

      $rule_groups = (array) ($result['_ldap_rules']['groups_id'] ?? []);

      // 3. Reflect the membership into dynamic Group_User links, only touching
      //    the groups this plugin manages so an SSO login never wipes the rest.
      self::syncGroupLinks($users_id, $group_ids, $rule_groups, self::managedGroupIds());

      // 4. Persist the habilitations (dynamic Profile_User entries) computed by
      //    the rule engine.
      $user->input = $result;
      $user->willProcessRuleRight();
      $user->applyRightRules();
   }

   /**
    * Map the user's Entra groups to GLPI group ids using each group's LDAP
    * linkage, the same way User::getFromLDAPGroupVirtual() does for LDAP:
    *   - ldap_group_dn : matched against the Entra group's full DN
    *     (onPremisesDistinguishedName, for AD-synced groups) AND against the CN
    *     extracted from the configured DN, compared to the group's displayName /
    *     sAMAccountName. This is the key to parity: Entra usually exposes only
    *     the group *name* (not the on-prem DN), so a GLPI group still configured
    *     with the full AD DN "CN=NAME,OU=...,DC=..." matches on its CN "NAME";
    *   - ldap_field + ldap_value : ldap_value is matched (SQL LIKE semantics)
    *     against the user's group identifiers, ldap_field being the membership
    *     attribute (e.g. "memberof").
    *
    * @param array $entraGroups
    * @return int[] De-duplicated GLPI group ids.
    */
   public static function matchGlpiGroupIds(array $entraGroups): array {
      global $DB;

      // Flatten every identifier an Entra group can be recognised by.
      $identifiers = [];
      foreach ($entraGroups as $g) {
         foreach (['onPremisesDistinguishedName', 'displayName', 'onPremisesSamAccountName', 'id'] as $key) {
            $val = trim((string) ($g[$key] ?? ''));
            if ($val !== '') {
               $identifiers[] = $val;
            }
         }
      }
      if (empty($identifiers)) {
         return [];
      }
      $identifiers_lc = array_map('strtolower', $identifiers);

      $matched = [];
      foreach ($DB->request([
         'SELECT' => ['id', 'ldap_group_dn', 'ldap_field', 'ldap_value'],
         'FROM'   => 'glpi_groups',
         'WHERE'  => [
            'OR' => [
               ['ldap_group_dn' => ['<>', '']],
               ['ldap_field' => ['<>', '']],
            ],
         ],
      ]) as $group) {
         $gid = (int) $group['id'];

         // DN linkage: try the configured value as-is (full DN) and its CN
         // component, so a full AD DN matches an Entra group exposed by name.
         $dn_raw = trim((string) ($group['ldap_group_dn'] ?? ''));
         if ($dn_raw !== '') {
            $dn_candidates = [strtolower($dn_raw)];
            $cn = self::extractCn($dn_raw);
            if ($cn !== '') {
               $dn_candidates[] = strtolower($cn);
            }
            if (array_intersect($dn_candidates, $identifiers_lc)) {
               $matched[$gid] = true;
               continue;
            }
         }

         // field/value linkage.
         $value = trim((string) ($group['ldap_value'] ?? ''));
         if (($group['ldap_field'] ?? '') !== '' && $value !== '') {
            foreach ($identifiers as $candidate) {
               if (self::likeMatch($candidate, $value)) {
                  $matched[$gid] = true;
                  break;
               }
            }
         }
      }

      return array_keys($matched);
   }

   /**
    * Ids of every GLPI group that carries an Entra/LDAP linkage, i.e. the groups
    * this plugin is responsible for. Dynamic memberships of groups outside this
    * set (e.g. assigned by the native LDAP login or added by hand) are never
    * touched by the sync, so an SSO login does not wipe them.
    *
    * @return int[]
    */
   private static function managedGroupIds(): array {
      global $DB;

      $ids = [];
      foreach ($DB->request([
         'SELECT' => 'id',
         'FROM'   => 'glpi_groups',
         'WHERE'  => [
            'OR' => [
               ['ldap_group_dn' => ['<>', '']],
               ['ldap_field' => ['<>', '']],
            ],
         ],
      ]) as $g) {
         $ids[] = (int) $g['id'];
      }
      return $ids;
   }

   /**
    * Extract the CN (first RDN) from a distinguished name such as
    * "CN=My Group,OU=Apps,DC=example,DC=local" → "My Group".
    */
   private static function extractCn(string $dn): string {
      if (preg_match('/^\s*cn=((?:[^,\\\\]|\\\\.)+)/i', $dn, $m)) {
         return trim(str_replace(['\\,', '\\='], [',', '='], $m[1]));
      }
      return '';
   }

   /**
    * Reflect the resolved membership into dynamic Group_User links, mirroring
    * User::syncLdapGroups(): keep links that still apply, drop dynamic links
    * that no longer match (unless an authorization rule still grants them), and
    * add the missing ones as dynamic.
    *
    * Deletions are restricted to "managed" groups (those carrying an Entra/LDAP
    * linkage): a dynamic membership of any other group is left untouched, so an
    * SSO login can never wipe groups the plugin is not responsible for.
    *
    * @param int   $users_id
    * @param int[] $wanted_ids  GLPI groups the user must belong to.
    * @param int[] $rule_ids    GLPI groups granted by authorization rules.
    * @param int[] $managed_ids GLPI groups this plugin manages.
    */
   private static function syncGroupLinks(int $users_id, array $wanted_ids, array $rule_ids, array $managed_ids): void {
      global $DB;

      $wanted   = array_fill_keys(array_map('intval', $wanted_ids), true);
      $rule_set = array_fill_keys(array_map('intval', $rule_ids), true);
      $managed  = array_fill_keys(array_map('intval', $managed_ids), true);

      $group_user = new Group_User();
      foreach ($DB->request([
         'SELECT' => ['id', 'groups_id', 'is_dynamic'],
         'FROM'   => 'glpi_groups_users',
         'WHERE'  => ['users_id' => $users_id],
      ]) as $link) {
         $gid = (int) $link['groups_id'];
         if (isset($wanted[$gid])) {
            // Already linked: nothing to add for this group.
            unset($wanted[$gid]);
         } elseif (!empty($link['is_dynamic']) && isset($managed[$gid]) && !isset($rule_set[$gid])) {
            // Managed dynamic link no longer backed by a membership or a rule.
            $group_user->delete(['id' => (int) $link['id']]);
         }
      }

      foreach (array_keys($wanted) as $gid) {
         $group_user->add([
            'users_id'   => $users_id,
            'groups_id'  => $gid,
            'is_dynamic' => 1,
         ]);
      }
   }

   /**
    * Case-insensitive SQL-LIKE match (supports the % and _ wildcards, plus the
    * shell-style * as an alias for %), used to compare a group identifier
    * against a glpi_groups.ldap_value pattern.
    */
   private static function likeMatch(string $value, string $pattern): bool {
      $regex = '';
      $len   = strlen($pattern);
      for ($i = 0; $i < $len; $i++) {
         $c = $pattern[$i];
         if ($c === '%' || $c === '*') {
            $regex .= '.*';
         } elseif ($c === '_') {
            $regex .= '.';
         } else {
            $regex .= preg_quote($c, '/');
         }
      }
      return (bool) preg_match('/^' . $regex . '$/i', $value);
   }

   /**
    * Build a short, readable summary of the Entra groups for the diagnostic
    * log: the DN when present (what "DN du groupe" must match for AD-synced
    * groups), otherwise the display name. Capped so the log stays compact.
    */
   private static function describeEntraGroups(array $entraGroups): string {
      if (empty($entraGroups)) {
         return 'aucun';
      }

      $labels = [];
      foreach ($entraGroups as $g) {
         $labels[] = trim((string) ($g['onPremisesDistinguishedName'] ?? ''))
                  ?: trim((string) ($g['displayName'] ?? ''))
                  ?: trim((string) ($g['id'] ?? '?'));
      }

      $shown = array_slice($labels, 0, 15);
      $more  = count($labels) - count($shown);

      return implode(' | ', $shown) . ($more > 0 ? sprintf(' | … (+%d)', $more) : '');
   }

   /** Write a diagnostic line to the plugin log (files/_log/ssomicrosoft.log). */
   private static function log(string $message): void {
      Toolbox::logInFile('ssomicrosoft', $message . "\n");
   }
}
