#!/usr/bin/env php
<?php
require_once dirname(__FILE__, 4) . "/inc/includes.php";
require_once GLPI_ROOT . '/plugins/ssomicrosoft/inc/sync.class.php';

PluginSsomicrosoftSync::syncAll();

