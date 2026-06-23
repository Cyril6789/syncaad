<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_syncaad', READ);

Html::header(
    PluginSyncaadConnection::getTypeName(Session::getPluralNumber()),
    '',
    'config',
    'PluginSyncaadConnection'
);

if (Session::haveRight('plugin_syncaad', UPDATE)) {
    echo '<div class="center mb-3">';
    echo '<a class="btn btn-primary" href="' . $CFG_GLPI['root_doc'] . '/plugins/syncaad/front/sync.php">';
    echo '<i class="ti ti-refresh me-1"></i>' . __('Synchroniser toutes les connexions', 'syncaad');
    echo '</a>';
    echo '</div>';
}

Search::show('PluginSyncaadConnection');

Html::footer();
