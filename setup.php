<?php

define('PLUGIN_SYNCAAD_VERSION', '2.0.0');

// Minimal/maximal GLPI version, inclusive/exclusive
define('PLUGIN_SYNCAAD_MIN_GLPI', '11.0.0');
define('PLUGIN_SYNCAAD_MAX_GLPI', '11.0.99');

/**
 * Plugin metadata.
 */
function plugin_version_syncaad() {
    return [
        'name'           => __('Synchro AAD', 'syncaad'),
        'version'        => PLUGIN_SYNCAAD_VERSION,
        'author'         => 'Cyril Heilmann',
        'license'        => 'GPLv2+',
        'homepage'       => 'https://github.com/Cyril6789/syncaad',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_SYNCAAD_MIN_GLPI,
                'max' => PLUGIN_SYNCAAD_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.1',
            ],
        ],
    ];
}

/**
 * Check the plugin prerequisites before it can be installed/activated.
 */
function plugin_syncaad_check_prerequisites() {
    if (!extension_loaded('curl')) {
        echo __('The PHP "curl" extension is required by Synchro AAD.', 'syncaad');
        return false;
    }
    if (!function_exists('random_bytes')) {
        echo __('A PHP version providing random_bytes() is required by Synchro AAD.', 'syncaad');
        return false;
    }
    return true;
}

/**
 * Check that the plugin is correctly configured.
 */
function plugin_syncaad_check_config($verbose = false) {
    // No mandatory global configuration: connections are created from the UI.
    return true;
}

/**
 * Register hooks and classes.
 */
function plugin_init_syncaad() {
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['syncaad'] = true;

    Plugin::registerClass('PluginSyncaadConnection');

    // Expose the plugin rights in the standard profile form so that a
    // super-admin can see and manage them from Administration > Profiles.
    Plugin::registerClass('PluginSyncaadProfile', [
        'addtabon' => 'Profile',
    ]);

    // Menu entry (standard interface), only for users allowed to manage connections.
    if (Session::haveRight('plugin_syncaad', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['syncaad'] = [
            'config' => 'PluginSyncaadConnection',
        ];
    }

    // Configuration shortcut on the plugins list.
    $PLUGIN_HOOKS['config_page']['syncaad'] = 'front/connection.php';

    // Add the "Sign in with Entra ID" button(s) on the login page.
    $PLUGIN_HOOKS['display_login']['syncaad'] = 'plugin_syncaad_display_login';

    // The SSO start/callback endpoint must be reachable by unauthenticated users.
    if (class_exists('Glpi\\Http\\Firewall')) {
        Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'syncaad',
            '#^/front/sso\.php#',
            Glpi\Http\Firewall::STRATEGY_NO_CHECK
        );
    }
}

/**
 * Render the SSO login button(s) on the GLPI login page.
 */
function plugin_syncaad_display_login() {
    global $CFG_GLPI, $DB;

    if (!$DB->tableExists('glpi_plugin_syncaad_connections')) {
        return;
    }

    $iterator = $DB->request([
        'FROM'  => 'glpi_plugin_syncaad_connections',
        'WHERE' => [
            'sso_enabled' => 1,
            'active'      => 1,
        ],
        'ORDER' => 'name ASC',
    ]);

    if (!count($iterator)) {
        return;
    }

    echo '<div class="plugin-syncaad-sso d-grid gap-2 mt-2 mb-2">';
    foreach ($iterator as $conn) {
        $url = $CFG_GLPI['root_doc']
             . '/plugins/syncaad/front/sso.php?action=login&connection_id=' . (int) $conn['id'];
        $label = sprintf(__('Se connecter avec %s', 'syncaad'), $conn['name']);
        echo '<a class="btn btn-primary w-100" href="' . htmlspecialchars($url) . '">'
           . '<i class="ti ti-brand-windows me-1"></i>'
           . htmlspecialchars($label)
           . '</a>';
    }
    echo '</div>';

    // When SSO is available it becomes the primary way to sign in: promote the
    // SSO buttons above the standard form and fold the latter behind a discreet
    // toggle ("Connexion GLPI"). Done client-side because the standard form is
    // rendered by GLPI itself, in a position we cannot control from the hook.
    // Everything is kept inside the form's own container so it stays the exact
    // same width as the standard login form.
    $toggle_label = json_encode(__('Connexion GLPI', 'syncaad'), JSON_UNESCAPED_UNICODE);
    echo <<<HTML
<style>
.plugin-syncaad-sso { margin-bottom: .25rem; }
.plugin-syncaad-classic { margin-top: 1rem; }
.plugin-syncaad-divider {
   border: 0;
   border-top: 1px solid var(--bs-border-color, #dee2e6);
   margin: 0;
}
.plugin-syncaad-toggle {
   display: flex;
   align-items: center;
   justify-content: center;
   gap: .35rem;
   width: 100%;
   margin: 0;
   padding: .5rem 0;
   background: transparent;
   border: 0;
   color: var(--bs-secondary-color, #6c757d);
   font-size: .8125rem;
   line-height: 1.2;
   cursor: pointer;
}
.plugin-syncaad-toggle:hover,
.plugin-syncaad-toggle:focus-visible { color: var(--bs-body-color, #212529); }
.plugin-syncaad-toggle .ti {
   font-size: 1rem;
   transition: transform .2s ease;
}
.plugin-syncaad-toggle[aria-expanded="true"] .ti { transform: rotate(180deg); }
.plugin-syncaad-classic-body { margin-top: .5rem; }
.plugin-syncaad-classic-body[hidden] { display: none; }
</style>
<script type="text/javascript">
(function() {
   function init() {
      var sso = document.querySelector('.plugin-syncaad-sso');
      if (!sso) { return; }

      var field = document.querySelector('input[name="login_name"], input[name="login"], input[name="fielda"]');
      var form  = field ? field.closest('form') : null;
      if (!form || form.dataset.syncaadDone) { return; }
      form.dataset.syncaadDone = '1';

      var box = document.createElement('div');
      box.className = 'plugin-syncaad-classic';
      box.innerHTML =
         '<hr class="plugin-syncaad-divider">' +
         '<button type="button" class="plugin-syncaad-toggle" aria-expanded="false">' +
            '<span>' + {$toggle_label} + '</span>' +
            '<i class="ti ti-chevron-down" aria-hidden="true"></i>' +
         '</button>' +
         '<div class="plugin-syncaad-classic-body" hidden></div>';

      // Re-order: SSO buttons first, then the collapsed standard form, all
      // inside the form's original container so widths match exactly.
      var parent = form.parentNode;
      parent.insertBefore(sso, form);
      parent.insertBefore(box, form);
      box.querySelector('.plugin-syncaad-classic-body').appendChild(form);

      var btn  = box.querySelector('.plugin-syncaad-toggle');
      var body = box.querySelector('.plugin-syncaad-classic-body');
      btn.addEventListener('click', function() {
         var collapsed = body.hasAttribute('hidden');
         if (collapsed) {
            body.removeAttribute('hidden');
         } else {
            body.setAttribute('hidden', '');
         }
         btn.setAttribute('aria-expanded', collapsed ? 'true' : 'false');
      });
   }

   if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
   } else {
      init();
   }
})();
</script>
HTML;
}

/**
 * Plugin installation: create tables, ensure the SSO server variable exists
 * and grant rights to the super-admin profile.
 */
function plugin_syncaad_install() {
    global $DB;

    $migration         = new Migration(PLUGIN_SYNCAAD_VERSION);
    $table             = 'glpi_plugin_syncaad_connections';
    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();

    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL DEFAULT '',
            `tenant_id` varchar(64) NOT NULL DEFAULT '',
            `client_id` varchar(64) NOT NULL DEFAULT '',
            `client_secret` text,
            `email_filter` varchar(255) DEFAULT NULL,
            `disable_if_disabled` tinyint NOT NULL DEFAULT '1',
            `delete_missing` tinyint NOT NULL DEFAULT '0',
            `active` tinyint NOT NULL DEFAULT '1',
            `sso_enabled` tinyint NOT NULL DEFAULT '0',
            `auto_register` tinyint NOT NULL DEFAULT '1',
            `redirect_uri` varchar(255) DEFAULT NULL,
            `default_profiles_id` int unsigned NOT NULL DEFAULT '0',
            `entities_id` int unsigned NOT NULL DEFAULT '0',
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `active` (`active`),
            KEY `sso_enabled` (`sso_enabled`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->doQuery($query);
    } else {
        // Upgrade from a previous version: add any missing column.
        $columns = [
            'sso_enabled'         => "ALTER TABLE `$table` ADD `sso_enabled` tinyint NOT NULL DEFAULT '0'",
            'auto_register'       => "ALTER TABLE `$table` ADD `auto_register` tinyint NOT NULL DEFAULT '1'",
            'redirect_uri'        => "ALTER TABLE `$table` ADD `redirect_uri` varchar(255) DEFAULT NULL",
            'default_profiles_id' => "ALTER TABLE `$table` ADD `default_profiles_id` int unsigned NOT NULL DEFAULT '0'",
            'entities_id'         => "ALTER TABLE `$table` ADD `entities_id` int unsigned NOT NULL DEFAULT '0'",
            'date_creation'       => "ALTER TABLE `$table` ADD `date_creation` timestamp NULL DEFAULT NULL",
            'date_mod'            => "ALTER TABLE `$table` ADD `date_mod` timestamp NULL DEFAULT NULL",
        ];
        foreach ($columns as $field => $sql) {
            if (!$DB->fieldExists($table, $field)) {
                $DB->doQuery($sql);
            }
        }
    }

    $migration->executeMigration();

    // Make the right usable: register it for every profile so it shows up in
    // the profile form, then grant full access to the super-admin profile and
    // to the profile currently used by the installer.
    ProfileRight::addProfileRights(['plugin_syncaad']);

    // Resolve the super-admin profile dynamically (it is id 4 on a default
    // GLPI install, but may differ). Fall back to id 4 if not found.
    $super_admin_id = 0;
    foreach ($DB->request([
        'SELECT' => 'id',
        'FROM'   => 'glpi_profiles',
        'WHERE'  => ['name' => 'Super-Admin'],
    ]) as $row) {
        $super_admin_id = (int) $row['id'];
    }
    if ($super_admin_id <= 0) {
        $super_admin_id = 4;
    }

    foreach ([$super_admin_id, (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0)] as $profile_id) {
        if ($profile_id > 0) {
            // Grant the right and, for the active profile, refresh the running
            // session so the configuration key works without re-logging in.
            PluginSyncaadProfile::createFirstAccess($profile_id);
            $DB->update(
                'glpi_profilerights',
                ['rights' => READ | CREATE | UPDATE | DELETE | PURGE],
                ['profiles_id' => $profile_id, 'name' => 'plugin_syncaad']
            );
            if (isset($_SESSION['glpiactiveprofile']['id'])
                && (int) $_SESSION['glpiactiveprofile']['id'] === $profile_id) {
                $_SESSION['glpiactiveprofile']['plugin_syncaad'] = READ | CREATE | UPDATE | DELETE | PURGE;
            }
        }
    }

    return true;
}

/**
 * Plugin uninstallation: drop tables and remove rights.
 */
function plugin_syncaad_uninstall() {
    global $DB;

    if ($DB->tableExists('glpi_plugin_syncaad_connections')) {
        $DB->doQuery("DROP TABLE `glpi_plugin_syncaad_connections`");
    }

    ProfileRight::deleteProfileRights(['plugin_syncaad']);

    return true;
}
