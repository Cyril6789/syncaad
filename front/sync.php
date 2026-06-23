<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_syncaad', UPDATE);

$connection_id = (int) ($_REQUEST['connection_id'] ?? 0);
$user_id       = (int) ($_REQUEST['user_id'] ?? 0);

if ($user_id) {
    PluginSyncaadSync::syncSingleUser($user_id, $connection_id);
    Session::addMessageAfterRedirect(__('Synchronisation de l\'utilisateur terminée.', 'syncaad'));
} elseif ($connection_id) {
    $conn = new PluginSyncaadConnection();
    if ($conn->getFromDB($connection_id)) {
        PluginSyncaadSync::syncConnection($conn->fields);
        Session::addMessageAfterRedirect(__('Synchronisation de la connexion terminée.', 'syncaad'));
    }
} else {
    PluginSyncaadSync::syncAll();
    Session::addMessageAfterRedirect(__('Synchronisation complète terminée.', 'syncaad'));
}

Html::back();
