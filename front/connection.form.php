<?php
include '../../../inc/includes.php';

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
    if ($connection->delete($_POST)) {
        Html::back();
    }
}

Html::header(__('Synchro AAD', 'syncaad'), '', 'plugins', 'syncaad', 'connection');
$connection->display(['id' => $_GET['id'] ?? 0]);
Html::footer();
