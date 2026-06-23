<?php

if (!defined('GLPI_ROOT')) {
   die("Cannot access directly");
}

/**
 * Manage the SSO Microsoft rights from the standard GLPI profile form.
 *
 * Registering this class as a tab on {@see Profile} makes the
 * `plugin_ssomicrosoft` right visible and editable through
 * Administration > Profiles, so a super-admin can grant or revoke access
 * to the plugin without touching the database.
 */
class PluginSsomicrosoftProfile extends Profile {

   // We piggyback on the core "profile" right: only users allowed to edit
   // profiles may change the plugin rights matrix.
   public static $rightname = 'profile';

   /**
    * Description of the rights handled by the plugin.
    *
    * @return array<int, array<string, mixed>>
    */
   static function getAllRights() {
      return [
         [
            'itemtype' => 'PluginSsomicrosoftConnection',
            'label'    => __('SSO Microsoft', 'ssomicrosoft'),
            'field'    => 'plugin_ssomicrosoft',
            'rights'   => [
               READ   => __('Read'),
               CREATE => __('Create'),
               UPDATE => __('Update'),
               DELETE => __('Delete'),
               PURGE  => ['short' => __('Purge'),
                          'long'  => _x('button', 'Delete permanently')],
            ],
         ],
      ];
   }

   /**
    * Tab name shown on the Profile form.
    */
   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      if ($item instanceof Profile && $item->getField('interface') == 'central') {
         return self::createTabEntry(__('SSO Microsoft', 'ssomicrosoft'));
      }
      return '';
   }

   /**
    * Render the plugin rights matrix inside the Profile form.
    */
   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      if ($item instanceof Profile) {
         $profile = new self();
         $profile->showForm($item->getID());
      }
      return true;
   }

   /**
    * Ensure the given profile owns a profilerights row for every plugin right.
    *
    * When a row is created for the currently active profile, the right is also
    * injected into the live session so it takes effect immediately (no need to
    * log out and back in after installing the plugin).
    *
    * @param int                $profiles_id
    * @param array<string, int> $rights      name => rights value
    */
   static function addDefaultProfileInfos($profiles_id, array $rights) {
      $profileRight = new ProfileRight();
      foreach ($rights as $name => $value) {
         if (!countElementsInTable(
            'glpi_profilerights',
            ['profiles_id' => $profiles_id, 'name' => $name]
         )) {
            $profileRight->add([
               'profiles_id' => $profiles_id,
               'name'        => $name,
               'rights'      => $value,
            ]);
         }

         // Reflect the change in the running session if it concerns the
         // active profile, so the "config" key works right after install.
         if (isset($_SESSION['glpiactiveprofile']['id'])
             && (int) $_SESSION['glpiactiveprofile']['id'] === (int) $profiles_id) {
            $_SESSION['glpiactiveprofile'][$name] = $value;
         }
      }
   }

   /**
    * Grant full plugin rights to a profile (used at install time for the
    * super-admin profile and the installer's own profile).
    *
    * @param int $profiles_id
    */
   static function createFirstAccess($profiles_id) {
      $full = READ | CREATE | UPDATE | DELETE | PURGE;
      foreach (self::getAllRights() as $right) {
         self::addDefaultProfileInfos($profiles_id, [$right['field'] => $full]);
      }
   }

   /**
    * Display (and, for allowed users, edit) the plugin rights of a profile.
    *
    * @param int $profiles_id
    */
   function showForm($profiles_id = 0, $options = []) {
      $profile = new Profile();
      $profile->getFromDB($profiles_id);

      $canedit = Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, PURGE]);

      echo "<div class='spaced'>";
      if ($canedit) {
         echo "<form method='post' action='" . $profile->getFormURL() . "'>";
      }

      $profile->displayRightsChoiceMatrix(self::getAllRights(), [
         'canedit'       => $canedit,
         'default_class' => 'tab_bg_2',
         'title'         => __('SSO Microsoft', 'ssomicrosoft'),
      ]);

      if ($canedit) {
         echo "<div class='center'>";
         echo Html::hidden('id', ['value' => $profiles_id]);
         echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
         echo "</div>";
         Html::closeForm();
      }
      echo "</div>";
   }
}
