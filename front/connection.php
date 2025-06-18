<?php
include '../../../inc/includes.php';

Html::header(__('Connexions Entra ID', 'syncaad'), '', 'plugins', 'syncaad', 'connection');

Session::checkRight('syncaad', READ);

Search::show('PluginSyncaadConnection');

Html::footer();
