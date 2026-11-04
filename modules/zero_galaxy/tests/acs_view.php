<?php

require __DIR__ . '/support/bootstrap.php';
zgCoreConstants();
require __DIR__ . '/support/template.php';

use ZeroGalaxy\AcsView;

class SN { public static $lang = []; }

$module = new ZgModule();
$flights = [
  ['ID' => 11, 'END_COORDS' => '[0:1:1]', 'MESSAGE' => 0, 'MISSION' => 1, 'MISSION_NAME' => 'Attack'],
  ['ID' => 12, 'END_COORDS' => '[0:1:2]', 'MESSAGE' => 0, 'MISSION' => 2, 'MISSION_NAME' => 'ACS'],
  ['ID' => 13, 'END_COORDS' => '[1:1:1]', 'MESSAGE' => 0, 'MISSION' => 1, 'MISSION_NAME' => 'Attack'],
  ['ID' => 14, 'END_COORDS' => '[2:1:2]', 'MESSAGE' => 0, 'MISSION' => 2, 'MISSION_NAME' => 'ACS'],
  ['ID' => 15, 'END_COORDS' => '[0:1:3]', 'MESSAGE' => 1, 'MISSION' => 1, 'MISSION_NAME' => 'Attack'],
  ['ID' => 16, 'END_COORDS' => '[0:1:4]', 'MESSAGE' => 0, 'MISSION' => 6, 'MISSION_NAME' => 'Spy'],
];
$template = ZgTemplate::fixture(['flying_fleets' => ZgTemplate::native('flying_fleets')]);
foreach ($flights as $row) { $template->assign_block_vars('fleets', $row); }
$template->assign_vars(['FLEETS_FLYING' => 6, 'FLEETS_MAX' => 10]);
AcsView::decorate($module, $template);
$check($module->selected === 'zero_galaxy_flying_fleets'
  && array_column($template->_tpldata['fleets'], 'ID') === array_column($flights, 'ID'),
  'Zero-galaxy flights must wrap the native list without changing its rows');
$check(json_decode($template->_rootref['ZERO_ACS_BLOCKED_JSON'], true) === [11, 12, 15, 16],
  'Every zero-galaxy flight must be listed for the group-button filter');
$native = file_get_contents(ZgTemplate::native('flying_fleets'));
$html = $template->renderSource(str_replace('<!-- INCLUDE flying_fleets -->', preg_replace('/<!-- INCLUDE [a-z_]+ -->/', '', $native),
  file_get_contents(ZgTemplate::module('zero_galaxy_flying_fleets'))));
preg_match_all('/fleet_page=4&fleetid=([0-9]+)/', $html, $buttons);
$check(array_map('intval', $buttons[1]) === [11, 12, 13, 14] && strpos($html, 'var blocked = [11,12,15,16];') !== false,
  'The wrapper must render the unchanged native list followed by its button filter');
$check(substr_count($html, 'class="return_fleet"') === 5, 'Recall controls remain available for all outbound fleets');
$snapshot = $template->_tpldata; AcsView::decorate($module, $template);
$check($template->_tpldata === $snapshot, 'Repeated global view hooks must be idempotent');

$template = ZgTemplate::fixture(['flying_fleets' => ZgTemplate::native('flying_fleets')]);
$template->assign_block_vars('fleets', $flights[2]);
$module->selected = null; AcsView::decorate($module, $template);
$check($module->selected === null, 'Ordinary-only flight pages must keep the native template');

$template = ZgTemplate::fixture(['fleet1' => ZgTemplate::native('fleet1')]);
foreach ([['ID'=>1,'GALAXY'=>0], ['ID'=>2,'GALAXY'=>1], ['ID'=>3,'GALAXY'=>'0'], ['ID'=>4,'GALAXY'=>2]] as $row) {
  $template->assign_block_vars('acss', $row);
}
AcsView::decorate($module, $template);
$check(array_column($template->_tpldata['acss'], 'ID') === [2,4], 'The native target picker removes only zero-galaxy invitations');
$snapshot = $template->_tpldata; AcsView::decorate($module, $template);
$check($template->_tpldata === $snapshot, 'Repeated invitation filtering must be idempotent');
$template = ZgTemplate::fixture(['fleet1' => 'modules/other/fleet1-custom.tpl.html']);
$template->assign_block_vars('acss', ['ID'=>1,'GALAXY'=>0]);
AcsView::decorate($module, $template);
$check(count($template->_tpldata['acss']) === 1, 'Unrelated custom template must not be altered');
echo "ACS exclusion views: {$assertions} assertions passed; native template compiler, no game bootstrap or database.\n";
