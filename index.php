<?php
require_once dirname(__FILE__) . "/../../../../inc/includes.php";

Html::header(__('Synchro AAD', 'syncaad'), '', 'plugins', 'syncaad');

echo '<div class="center">';
echo '<p><a class="vsubmit" href="' . $CFG_GLPI['root_doc'] . '/plugins/syncaad/front/connection.php">' . __('Gérer les connexions', 'syncaad') . '</a></p>';
echo '<p><a class="vsubmit" href="' . $CFG_GLPI['root_doc'] . '/plugins/syncaad/front/sync.php">' . __('Synchroniser maintenant', 'syncaad') . '</a></p>';
echo '</div>';

Html::footer();
