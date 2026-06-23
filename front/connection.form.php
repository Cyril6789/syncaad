<?php

include('../../../inc/includes.php');

$connection = new PluginSyncaadConnection();

if (isset($_POST['add'])) {
    $connection->check(-1, CREATE, $_POST);
    if ($connection->add($_POST)) {
        Html::back();
    }
} elseif (isset($_POST['update'])) {
    $connection->check($_POST['id'], UPDATE);
    if ($connection->update($_POST)) {
        Html::back();
    }
} elseif (isset($_POST['delete'])) {
    $connection->check($_POST['id'], DELETE);
    $connection->delete($_POST);
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/syncaad/front/connection.php');
} elseif (isset($_POST['purge'])) {
    $connection->check($_POST['id'], PURGE);
    $connection->delete($_POST, 1);
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/syncaad/front/connection.php');
}

Session::checkRight('plugin_syncaad', READ);

Html::header(
    PluginSyncaadConnection::getTypeName(1),
    '',
    'config',
    'PluginSyncaadConnection'
);

$connection->display(['id' => (int) ($_GET['id'] ?? 0)]);

Html::footer();
