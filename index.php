<?php

include('../../inc/includes.php');

global $CFG_GLPI;

// The plugin entry point simply leads to the connections management page.
Html::redirect($CFG_GLPI['root_doc'] . '/plugins/syncaad/front/connection.php');
