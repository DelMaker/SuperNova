<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('INSIDE', true);
define('SN_ROOT_PHYSICAL', dirname(__DIR__, 3) . '/');
define('SN_ROOT_RELATIVE', '');
require_once SN_ROOT_PHYSICAL . 'classes/template_compile.php';
require_once SN_ROOT_PHYSICAL . 'classes/template.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/AcsView.php';

use ZeroGalaxy\AcsView;

class SN { public static $lang = []; }
class AcsFixtureTemplate extends template {
  public function renderSource(string $source): string {
    ob_start(); $this->evaluate($this->compiler->compile($source)); return ob_get_clean();
  }
}
class AcsFixtureModule {
  public $selected = null;
  public function addModuleTemplate($name, $template) {
    $this->selected = $name;
    $template->files[$name] = SN_ROOT_PHYSICAL . 'modules/zero_galaxy/design/templates/OpenGame/' . $name . '.tpl.html';
    return $template;
  }
}
$assertions = 0;
$check = function ($condition, string $message) use (&$assertions) {
  $assertions++; if (!$condition) { throw new RuntimeException($message); }
};
$fixture = function (string $name): AcsFixtureTemplate {
  $template = new AcsFixtureTemplate(); $template->_rootref = &$template->_tpldata['.'][0];
  $template->files[$name] = SN_ROOT_PHYSICAL . 'design/templates/OpenGame/' . $name . '.tpl.html';
  return $template;
};
$module = new AcsFixtureModule();
$check(AcsView::decorate($module, null, ['acs_enabled' => false]) === null, 'Non-templates must be ignored');
$template = $fixture('fleet0'); $before = $template->files;
$check(AcsView::decorate($module, $template, ['acs_enabled' => false]) === $template
  && $template->files === $before, 'Unrelated fleet pages must retain the native template');

$flights = [
  ['ID' => 11, 'END_COORDS' => '[0:1:1]', 'MESSAGE' => 0, 'MISSION' => 1, 'MISSION_NAME' => 'Attack'],
  ['ID' => 12, 'END_COORDS' => '[0:1:2]', 'MESSAGE' => 0, 'MISSION' => 2, 'MISSION_NAME' => 'ACS'],
  ['ID' => 13, 'END_COORDS' => '[1:1:1]', 'MESSAGE' => 0, 'MISSION' => 1, 'MISSION_NAME' => 'Attack'],
  ['ID' => 14, 'END_COORDS' => '[2:1:2]', 'MESSAGE' => 0, 'MISSION' => 2, 'MISSION_NAME' => 'ACS'],
  ['ID' => 15, 'END_COORDS' => '[0:1:3]', 'MESSAGE' => 1, 'MISSION' => 1, 'MISSION_NAME' => 'Attack'],
  ['ID' => 16, 'END_COORDS' => '[0:1:4]', 'MESSAGE' => 0, 'MISSION' => 6, 'MISSION_NAME' => 'Spy'],
];
$native = file_get_contents(SN_ROOT_PHYSICAL . 'design/templates/OpenGame/flying_fleets.tpl.html');
$override = file_get_contents(SN_ROOT_PHYSICAL . 'modules/zero_galaxy/design/templates/OpenGame/zero_galaxy_flying_fleets.tpl.html');
foreach ([false, true] as $enabled) {
  $module->selected = null; $template = $fixture('flying_fleets');
  foreach ($flights as $row) { $template->assign_block_vars('fleets', $row); }
  $template->assign_vars(['FLEETS_FLYING' => 6, 'FLEETS_MAX' => 10]);
  $before = $template->_tpldata['fleets'];
  $check(AcsView::decorate($module, $template, ['acs_enabled' => $enabled]) === $template, 'Decorator must retain the template instance');
  $source = $module->selected ? $override : $native;
  // Includes contain native JS and page hints, not the per-fleet ACS or recall controls.
  $html = $template->renderSource(preg_replace('/<!-- INCLUDE [a-z_]+ -->/', '', $source));
  preg_match_all('/fleet_page=4&fleetid=([0-9]+)/', $html, $buttons);
  $check(array_map('intval', $buttons[1]) === ($enabled ? [11,12,13,14] : [13,14]),
    'Disabled ACS must omit zero-galaxy create/invite controls from server-rendered HTML');
  $check(substr_count($html, 'class="return_fleet"') === 5, 'Recall controls must remain available for all outbound fleets');
  $check(substr_count($html, 'class="ownattack"') === 3 && substr_count($html, 'class="ownfederation"') === 2,
    'Native attack and federation styling must retain original mission IDs');
  foreach ($template->_tpldata['fleets'] as $i => $row) {
    $check($row['MISSION'] === $before[$i]['MISSION'] && $row['MISSION_NAME'] === $before[$i]['MISSION_NAME'],
      'Hiding controls must not alter mission data');
  }
  if ($enabled) {
    $check($module->selected === null && $template->_tpldata['fleets'] === $before,
      'Enabled ACS must use the untouched native flying-fleets template');
  } else {
    $check($module->selected === 'zero_galaxy_flying_fleets', 'Disabled zero targets require the module template');
    $snapshot = $template->_tpldata;
    AcsView::decorate($module, $template, ['acs_enabled' => false]);
    $check($template->_tpldata === $snapshot, 'Repeated global view hooks must be idempotent');
  }
}

$template = $fixture('flying_fleets'); $template->assign_block_vars('fleets', $flights[2]);
$module->selected = null; AcsView::decorate($module, $template, ['acs_enabled' => false]);
$check($module->selected === null, 'Ordinary-only flight pages must keep the native template');

foreach ([false, true] as $enabled) {
  $template = $fixture('fleet1');
  foreach ([['ID'=>1,'GALAXY'=>0], ['ID'=>2,'GALAXY'=>1], ['ID'=>3,'GALAXY'=>'0'], ['ID'=>4,'GALAXY'=>2]] as $row) {
    $template->assign_block_vars('acss', $row);
  }
  AcsView::decorate($module, $template, ['acs_enabled' => $enabled]);
  $check(array_column($template->_tpldata['acss'], 'ID') === ($enabled ? [1,2,3,4] : [2,4]),
    'The native target picker must remove only disabled zero-galaxy invitations');
  if (!$enabled) {
    $check($template->_tpldata['acss'][0]['S_FIRST_ROW'] && $template->_tpldata['acss'][1]['S_LAST_ROW'],
      'Filtered invitation block row metadata must remain correct');
    $snapshot = $template->_tpldata; AcsView::decorate($module, $template, ['acs_enabled'=>false]);
    $check($template->_tpldata === $snapshot, 'Repeated invitation filtering must be idempotent');
  }
}
$template = $fixture('fleet1'); $template->files['fleet1'] = 'modules/other/fleet1-custom.tpl.html';
$template->assign_block_vars('acss', ['ID'=>1,'GALAXY'=>0]);
AcsView::decorate($module, $template, ['acs_enabled'=>false]);
$check(count($template->_tpldata['acss']) === 1, 'Unrelated custom template must not be altered');
echo "ACS views: {$assertions} assertions passed; native template compiler, no game bootstrap or database.\n";
