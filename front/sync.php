<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_ssomicrosoft', UPDATE);

$connection_id = (int) ($_REQUEST['connection_id'] ?? 0);
$user_id       = (int) ($_REQUEST['user_id'] ?? 0);

if ($user_id) {
    PluginSsomicrosoftSync::syncSingleUser($user_id, $connection_id);
    Session::addMessageAfterRedirect(__('Synchronisation de l\'utilisateur terminée.', 'ssomicrosoft'));
} elseif ($connection_id) {
    $conn = new PluginSsomicrosoftConnection();
    if ($conn->getFromDB($connection_id)) {
        PluginSsomicrosoftSync::syncConnection($conn->fields);
        Session::addMessageAfterRedirect(__('Synchronisation de la connexion terminée.', 'ssomicrosoft'));
    }
} else {
    PluginSsomicrosoftSync::syncAll();
    Session::addMessageAfterRedirect(__('Synchronisation complète terminée.', 'ssomicrosoft'));
}

Html::back();
