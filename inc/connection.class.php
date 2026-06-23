<?php

if (!defined('GLPI_ROOT')) {
   die("Cannot access directly");
}

/**
 * An Entra ID (Azure AD) connection used both for user synchronisation and,
 * optionally, for SSO authentication.
 */
class PluginSyncaadConnection extends CommonDBTM {
   public static $rightname = 'plugin_syncaad';

   public $dohistory = true;

   static function getTypeName($nb = 0) {
      return _n('Connexion Entra ID', 'Connexions Entra ID', $nb, 'syncaad');
   }

   static function getIcon() {
      return 'ti ti-cloud-lock';
   }

   function defineTabs($options = []) {
      $ong = [];
      $this->addDefaultFormTab($ong);
      $this->addStandardTab('Log', $ong, $options);
      return $ong;
   }

   function rawSearchOptions() {
      $tab = [];

      $tab[] = [
         'id'   => 'common',
         'name' => __('Caractéristiques'),
      ];

      $tab[] = [
         'id'            => 1,
         'table'         => self::getTable(),
         'field'         => 'name',
         'name'          => __('Nom'),
         'datatype'      => 'itemlink',
         'massiveaction' => false,
      ];

      $tab[] = [
         'id'       => 2,
         'table'    => self::getTable(),
         'field'    => 'tenant_id',
         'name'     => __('Tenant ID', 'syncaad'),
         'datatype' => 'string',
      ];

      $tab[] = [
         'id'       => 3,
         'table'    => self::getTable(),
         'field'    => 'email_filter',
         'name'     => __('Filtre de domaine', 'syncaad'),
         'datatype' => 'string',
      ];

      $tab[] = [
         'id'       => 4,
         'table'    => self::getTable(),
         'field'    => 'sso_enabled',
         'name'     => __('SSO activé', 'syncaad'),
         'datatype' => 'bool',
      ];

      $tab[] = [
         'id'       => 5,
         'table'    => self::getTable(),
         'field'    => 'active',
         'name'     => __('Active'),
         'datatype' => 'bool',
      ];

      return $tab;
   }

   function showForm($ID, array $options = []) {
      global $CFG_GLPI;

      $this->initForm($ID, $options);
      $this->showFormHeader($options);

      echo '<tr class="tab_bg_1">';
      echo '<td>' . __('Nom') . '</td>';
      echo '<td>';
      echo Html::input('name', ['value' => $this->fields['name']]);
      echo '</td>';
      echo '<td>' . __('Active') . '</td>';
      echo '<td>';
      Dropdown::showYesNo('active', $this->fields['active']);
      echo '</td>';
      echo '</tr>';

      echo '<tr><th colspan="4">' . __('Connexion Entra ID', 'syncaad') . '</th></tr>';

      echo '<tr class="tab_bg_1">';
      echo '<td>' . __('Tenant ID', 'syncaad') . '</td>';
      echo '<td>' . Html::input('tenant_id', ['value' => $this->fields['tenant_id'], 'size' => 40]) . '</td>';
      echo '<td>' . __('Client ID', 'syncaad') . '</td>';
      echo '<td>' . Html::input('client_id', ['value' => $this->fields['client_id'], 'size' => 40]) . '</td>';
      echo '</tr>';

      echo '<tr class="tab_bg_1">';
      echo '<td>' . __('Client Secret', 'syncaad') . '</td>';
      echo '<td colspan="3">';
      echo '<input type="password" name="client_secret" autocomplete="new-password" size="60" value="'
         . htmlspecialchars((string) $this->fields['client_secret']) . '">';
      echo '</td>';
      echo '</tr>';

      echo '<tr class="tab_bg_1">';
      echo '<td>' . __('Filtre de domaine', 'syncaad') . '</td>';
      echo '<td colspan="3">';
      echo Html::input('email_filter', ['value' => $this->fields['email_filter'], 'size' => 40]);
      echo '<br><span class="text-muted">' . __("Ex. : @contoso.com — seuls les comptes dont l'email se termine ainsi sont traités.", 'syncaad') . '</span>';
      echo '</td>';
      echo '</tr>';

      echo '<tr><th colspan="4">' . __('Synchronisation', 'syncaad') . '</th></tr>';

      echo '<tr class="tab_bg_1">';
      echo '<td>' . __('Désactiver les comptes absents', 'syncaad') . '</td>';
      echo '<td>';
      Dropdown::showYesNo('disable_if_disabled', $this->fields['disable_if_disabled']);
      echo '</td>';
      echo '<td>' . __('Supprimer les comptes absents', 'syncaad') . '</td>';
      echo '<td>';
      Dropdown::showYesNo('delete_missing', $this->fields['delete_missing']);
      echo '</td>';
      echo '</tr>';

      echo '<tr><th colspan="4">' . __('Authentification SSO', 'syncaad') . '</th></tr>';

      echo '<tr class="tab_bg_1">';
      echo '<td>' . __('SSO activé', 'syncaad') . '</td>';
      echo '<td>';
      Dropdown::showYesNo('sso_enabled', $this->fields['sso_enabled']);
      echo '</td>';
      echo '<td>' . __('Créer les comptes manquants', 'syncaad') . '</td>';
      echo '<td>';
      Dropdown::showYesNo('auto_register', $this->fields['auto_register']);
      echo '</td>';
      echo '</tr>';

      echo '<tr class="tab_bg_1">';
      echo '<td>' . __('Profil par défaut (nouveaux comptes)', 'syncaad') . '</td>';
      echo '<td>';
      Profile::dropdown([
         'name'  => 'default_profiles_id',
         'value' => $this->fields['default_profiles_id'],
         'width' => '100%',
      ]);
      echo '</td>';
      echo '<td>' . __('Entité par défaut (nouveaux comptes)', 'syncaad') . '</td>';
      echo '<td>';
      Entity::dropdown([
         'name'  => 'entities_id',
         'value' => $this->fields['entities_id'],
         'width' => '100%',
      ]);
      echo '</td>';
      echo '</tr>';

      // Help the administrator configure the Azure app registration.
      $default_redirect = rtrim($CFG_GLPI['url_base'], '/') . '/plugins/syncaad/front/sso.php';
      echo '<tr class="tab_bg_1">';
      echo '<td>' . __('URL de redirection (Azure)', 'syncaad') . '</td>';
      echo '<td colspan="3">';
      echo Html::input('redirect_uri', ['value' => $this->fields['redirect_uri'], 'size' => 80]);
      echo '<br><span class="text-muted">'
         . sprintf(
            __('Laisser vide pour utiliser : %s — cette URL doit être déclarée comme "Redirect URI" (type Web) dans Entra ID.', 'syncaad'),
            '<code>' . htmlspecialchars($default_redirect) . '</code>'
         )
         . '</span>';
      echo '</td>';
      echo '</tr>';

      $this->showFormButtons($options);
      return true;
   }
}
