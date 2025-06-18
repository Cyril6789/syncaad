<?php
function plugin_version_syncaad() {
    return [
        'name'           => __('Synchro AAD', 'syncaad'),
        'version'        => '1.0.0',
        'author'         => __('Your Name', 'syncaad'),
        'license'        => 'GPLv2+',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => '10.0',
                'max' => '',
            ]
        ]
    ];
}

function plugin_init_syncaad() {
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['csrf_compliant']['syncaad'] = true;

    $PLUGIN_HOOKS['menu_entry']['syncaad'] = true;
    $PLUGIN_HOOKS['config_page']['syncaad'] = 'front/connection.php';
    $PLUGIN_HOOKS['rights']['syncaad'] = 'plugin_syncaad_getRights';
}

function plugin_syncaad_getRights() {
    return [
        'syncaad' => __('Gérer les connexions AAD', 'syncaad')
    ];

}

function plugin_install_syncaad() {
    global $DB;
    $query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_syncaad_connections` ("
            . "`id` int(11) NOT NULL AUTO_INCREMENT,"
            . "`name` varchar(255) NOT NULL,"
            . "`tenant_id` varchar(64) NOT NULL,"
            . "`client_id` varchar(64) NOT NULL,"
            . "`client_secret` text NOT NULL,"
            . "`email_filter` varchar(255) DEFAULT NULL,"
            . "`disable_if_disabled` tinyint(1) DEFAULT 1,"
            . "`delete_missing` tinyint(1) DEFAULT 0,"
            . "`active` tinyint(1) DEFAULT 1,"
            . "PRIMARY KEY (`id`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $DB->queryOrDie($query, "Create table glpi_plugin_syncaad_connections");
    return true;
}

function plugin_uninstall_syncaad() {
    global $DB;
    $DB->queryOrDie("DROP TABLE IF EXISTS `glpi_plugin_syncaad_connections`", "Drop table");
    return true;
}

function plugin_menu_syncaad() {
    return [
        'title' => __('Synchro AAD', 'syncaad'),
        'page'  => '/plugins/syncaad/front/connection.php'
    ];
}
