<?php
include '../../../inc/includes.php';
require_once GLPI_ROOT . '/plugins/syncaad/inc/sync.class.php';

Session::checkRight('syncaad', UPDATE);
Html::header(__('Synchronisation', 'syncaad'), '', 'plugins', 'syncaad');

$connection_id = $_GET['connection_id'] ?? 0;
$user_id       = $_GET['user_id'] ?? 0;

if ($user_id) {
    PluginSyncaadSync::syncSingleUser($user_id, $connection_id);
} else {
    if ($connection_id) {
        $conn = new PluginSyncaadConnection();
        if ($conn->getFromDB($connection_id)) {
            PluginSyncaadSync::syncConnection($conn->fields);
        }
    } else {
        PluginSyncaadSync::syncAll();
    }
}

Html::back();
Html::footer();
