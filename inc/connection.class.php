<?php

if (!defined('GLPI_ROOT')) {
   die("Cannot access directly");
}

class PluginSyncaadConnection extends CommonDBTM {
   public static $rightname = 'syncaad';

   static function getTypeName($nb = 0) {
      return _n('Connexion Entra ID', 'Connexions Entra ID', $nb, 'syncaad');
   }


   function defineTabs($options = []) {
      $ong = [];
      $this->addStandardTab('PluginSyncaadConnection', $ong, $options);
      return $ong;
   }

   static function getSearchOptions() {
      $tab = parent::getSearchOptions();

      $tab['common'] = __('Caractéristiques');

      $tab[1]['table'] = self::getTable();
      $tab[1]['field'] = 'name';
      $tab[1]['name'] = __('Nom');

      $tab[2]['table'] = self::getTable();
      $tab[2]['field'] = 'tenant_id';
      $tab[2]['name'] = __('Tenant ID', 'syncaad');

      $tab[3]['table'] = self::getTable();
      $tab[3]['field'] = 'email_filter';
      $tab[3]['name'] = __('Filtre emails', 'syncaad');

      $tab[4]['table'] = self::getTable();
      $tab[4]['field'] = 'active';
      $tab[4]['name'] = __('Active');

      return $tab;
   }

   function showForm($ID, $options = []) {
      if ($ID) {
         $this->check($ID, READ);
         $this->getFromDB($ID);
      } else {
         $this->check(-1, CREATE);
      }

      $this->showFormHeader($options);

      echo '<tr class="tab_bg1">';
      echo '<th>' . __('Nom') . '</th>';
      echo '<td><input type="text" name="name" value="' . $this->fields['name'] . '" size="40"></td>';
      echo '</tr>';

      echo '<tr class="tab_bg1">';
      echo '<th>Tenant ID</th><td><input type="text" name="tenant_id" value="' . $this->fields['tenant_id'] . '" size="40"></td>';
      echo '</tr>';

      echo '<tr class="tab_bg1">';
      echo '<th>Client ID</th><td><input type="text" name="client_id" value="' . $this->fields['client_id'] . '" size="40"></td>';
      echo '</tr>';

      echo '<tr class="tab_bg1">';
      echo '<th>Client Secret</th><td><input type="password" name="client_secret" value="' . $this->fields['client_secret'] . '" size="40"></td>';
      echo '</tr>';

      echo '<tr class="tab_bg1">';
      echo '<th>' . __('Filtre emails', 'syncaad') . '</th><td><input type="text" name="email_filter" value="' . $this->fields['email_filter'] . '" size="40"></td>';
      echo '</tr>';

      echo '<tr class="tab_bg1">';
      echo '<th>' . __('Désactiver si désactivé', 'syncaad') . '</th><td>';
      Dropdown::showYesNo('disable_if_disabled', $this->fields['disable_if_disabled']);
      echo '</td>';
      echo '</tr>';

      echo '<tr class="tab_bg1">';
      echo '<th>' . __('Supprimer si absent', 'syncaad') . '</th><td>';
      Dropdown::showYesNo('delete_missing', $this->fields['delete_missing']);
      echo '</td>';
      echo '</tr>';

      echo '<tr class="tab_bg1">';
      echo '<th>' . __('Active') . '</th><td>';
      Dropdown::showYesNo('active', $this->fields['active']);
      echo '</td>';
      echo '</tr>';

      $this->showFormButtons($options);
      return true;
   }
}
