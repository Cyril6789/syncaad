<?php

include('../../../inc/includes.php');

global $CFG_GLPI;

$action = $_GET['action'] ?? '';

if (isset($_GET['code']) || isset($_GET['error'])) {
    // Callback from Entra ID.
    PluginSyncaadSso::handleCallback();
} elseif ($action === 'login' && isset($_GET['connection_id'])) {
    PluginSyncaadSso::startLogin((int) $_GET['connection_id']);
} else {
    Html::redirect($CFG_GLPI['root_doc'] . '/');
}
