<?php

if (!defined('GLPI_ROOT')) {
   die("Cannot access directly");
}

/**
 * An Entra ID (Azure AD) connection used both for user synchronisation and,
 * optionally, for SSO authentication.
 */
class PluginSsomicrosoftConnection extends CommonDBTM {
   public static $rightname = 'plugin_ssomicrosoft';

   public $dohistory = true;

   static function getTypeName($nb = 0) {
      return _n('Connexion Entra ID', 'Connexions Entra ID', $nb, 'ssomicrosoft');
   }

   static function getIcon() {
      return 'ti ti-cloud-lock';
   }

   /**
    * Split a domain filter into a normalised list of domains.
    *
    * Several domains may be listed, separated by a comma or a semicolon
    * (whitespace and line breaks are tolerated too), e.g.
    * "@contoso.com, @fabrikam.com". Returns lowercase, de-duplicated entries.
    *
    * @param string|null $filter
    * @return string[]
    */
   public static function parseEmailFilters($filter): array {
      $filter = trim((string) $filter);
      if ($filter === '') {
         return [];
      }

      $parts = preg_split('/[\s,;]+/', strtolower($filter), -1, PREG_SPLIT_NO_EMPTY);

      return array_values(array_unique($parts));
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
         'name'     => __('Tenant ID', 'ssomicrosoft'),
         'datatype' => 'string',
      ];

      $tab[] = [
         'id'       => 3,
         'table'    => self::getTable(),
         'field'    => 'email_filter',
         'name'     => __('Filtre de domaine', 'ssomicrosoft'),
         'datatype' => 'string',
      ];

      $tab[] = [
         'id'       => 4,
         'table'    => self::getTable(),
         'field'    => 'sso_enabled',
         'name'     => __('SSO activé', 'ssomicrosoft'),
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

      echo '<tr><th colspan="4">' . __('Connexion Entra ID', 'ssomicrosoft') . '</th></tr>';

      echo '<tr class="tab_bg_1"><td colspan="4"><span class="text-muted">'
         . '<i class="ti ti-info-circle me-1"></i>'
         . __("Application Entra ID (App registration) : renseignez le Tenant ID, le Client ID et la « Value » du Client Secret. La synchronisation utilise le flux « client credentials » : dans l'application, ajoutez la permission de type Application « Microsoft Graph → User.Read.All » puis cliquez sur « Accorder un consentement administrateur ». ⚠️ Une permission Déléguée ne suffit PAS pour la synchronisation.", 'ssomicrosoft')
         . '</span></td></tr>';

      echo '<tr class="tab_bg_1">';
      echo '<td>' . __('Tenant ID', 'ssomicrosoft') . '</td>';
      echo '<td>' . Html::input('tenant_id', ['value' => $this->fields['tenant_id'], 'size' => 40]) . '</td>';
      echo '<td>' . __('Client ID', 'ssomicrosoft') . '</td>';
      echo '<td>' . Html::input('client_id', ['value' => $this->fields['client_id'], 'size' => 40]) . '</td>';
      echo '</tr>';

      echo '<tr class="tab_bg_1">';
      echo '<td>' . __('Client Secret', 'ssomicrosoft') . '</td>';
      echo '<td colspan="3">';
      echo '<input type="password" name="client_secret" autocomplete="new-password" size="60" value="'
         . htmlspecialchars((string) $this->fields['client_secret']) . '">';
      echo '</td>';
      echo '</tr>';

      echo '<tr class="tab_bg_1">';
      echo '<td>' . __('Filtre de domaine', 'ssomicrosoft') . '</td>';
      echo '<td colspan="3">';
      echo Html::input('email_filter', ['value' => $this->fields['email_filter'], 'size' => 40]);
      echo '<br><span class="text-muted">' . __("Ex. : @contoso.com — seuls les comptes dont l'email se termine ainsi sont traités. Plusieurs domaines possibles, séparés par une virgule ou un point-virgule (ex. : @contoso.com, @fabrikam.com).", 'ssomicrosoft') . '</span>';
      echo '</td>';
      echo '</tr>';

      echo '<tr><th colspan="4">' . __('Synchronisation', 'ssomicrosoft') . '</th></tr>';

      echo '<tr class="tab_bg_1"><td colspan="4"><span class="text-muted">'
         . '<i class="ti ti-clock me-1"></i>'
         . __("La synchronisation périodique est assurée par l'action automatique « ssomicrosoft » (Synchronisation des comptes depuis Entra ID), planifiable dans Configuration → Actions automatiques. Une synchronisation immédiate est possible via le bouton « Synchroniser toutes les connexions » sur la liste des connexions.", 'ssomicrosoft')
         . '</span></td></tr>';

      echo '<tr class="tab_bg_1">';
      echo '<td>' . __('Désactiver les comptes absents', 'ssomicrosoft') . '</td>';
      echo '<td>';
      Dropdown::showYesNo('disable_if_disabled', $this->fields['disable_if_disabled']);
      echo '</td>';
      echo '<td>' . __('Supprimer les comptes absents', 'ssomicrosoft') . '</td>';
      echo '<td>';
      Dropdown::showYesNo('delete_missing', $this->fields['delete_missing']);
      echo '</td>';
      echo '</tr>';

      echo '<tr><th colspan="4">' . __('Authentification SSO', 'ssomicrosoft') . '</th></tr>';

      echo '<tr class="tab_bg_1"><td colspan="4"><span class="text-muted">'
         . '<i class="ti ti-info-circle me-1"></i>'
         . __("Le SSO utilise le flux délégué (OpenID Connect). Dans l'application Entra ID, ajoutez les permissions Déléguées « openid », « profile », « email » et « User.Read », et déclarez l'URL de redirection (ci-dessous) comme « Redirect URI » de type Web.", 'ssomicrosoft')
         . '</span></td></tr>';

      echo '<tr class="tab_bg_1">';
      echo '<td>' . __('SSO activé', 'ssomicrosoft') . '</td>';
      echo '<td>';
      Dropdown::showYesNo('sso_enabled', $this->fields['sso_enabled']);
      echo '</td>';
      echo '<td>' . __('Créer les comptes manquants', 'ssomicrosoft') . '</td>';
      echo '<td>';
      Dropdown::showYesNo('auto_register', $this->fields['auto_register']);
      echo '</td>';
      echo '</tr>';

      echo '<tr class="tab_bg_1">';
      echo '<td>' . __('Profil par défaut (nouveaux comptes)', 'ssomicrosoft') . '</td>';
      echo '<td>';
      Profile::dropdown([
         'name'  => 'default_profiles_id',
         'value' => $this->fields['default_profiles_id'],
         'width' => '100%',
      ]);
      echo '</td>';
      echo '<td>' . __('Entité par défaut (nouveaux comptes)', 'ssomicrosoft') . '</td>';
      echo '<td>';
      Entity::dropdown([
         'name'  => 'entities_id',
         'value' => $this->fields['entities_id'],
         'width' => '100%',
      ]);
      echo '</td>';
      echo '</tr>';

      // Help the administrator configure the Azure app registration.
      $default_redirect = rtrim($CFG_GLPI['url_base'], '/') . '/plugins/ssomicrosoft/front/sso.php';
      echo '<tr class="tab_bg_1">';
      echo '<td>' . __('URL de redirection (Azure)', 'ssomicrosoft') . '</td>';
      echo '<td colspan="3">';
      echo Html::input('redirect_uri', ['value' => $this->fields['redirect_uri'], 'size' => 80]);
      echo '<br><span class="text-muted">'
         . sprintf(
            __('Laisser vide pour utiliser : %s — cette URL doit être déclarée comme "Redirect URI" (type Web) dans Entra ID.', 'ssomicrosoft'),
            '<code>' . htmlspecialchars($default_redirect) . '</code>'
         )
         . '</span>';
      echo '</td>';
      echo '</tr>';

      $this->showFormButtons($options);
      return true;
   }
}
