<?php

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

define('INSIDE', true);
define('SN_ROOT_PHYSICAL', dirname(__DIR__, 3) . '/');
define('SN_ROOT_RELATIVE', '');
require_once SN_ROOT_PHYSICAL . 'classes/template_compile.php';
require_once SN_ROOT_PHYSICAL . 'classes/template.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Profile.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/AttackPolicy.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/AttackView.php';

// Only presentation dependencies: no sessions, game bootstrap or database.
class SN {
  public static $lang = [];
  public static $sn_secret_word = 'isolated-view-test-secret-0123456789';
}
class Universe {
  public static function getBaseGalaxyDistance() { return 20000; }
}
function sn_get_groups($group) { return [210 => 210]; }
function eco_get_total_cost($id, $amount) { return ['total' => 1000 * $amount]; }
class ViewFixtureTemplate extends template {
  public function renderSource(string $source): string {
    ob_start();
    $this->evaluate($this->compiler->compile($source));
    return ob_get_clean();
  }
}

$assertions = 0;
$check = function ($condition, string $message) use (&$assertions) {
  $assertions++;
  if (!$condition) { throw new RuntimeException($message); }
};
$partial = file_get_contents(SN_ROOT_PHYSICAL . 'design/templates/OpenGame/fleet_miniatures.tpl.html');
$names = ['Крошечный разлом', 'Literal &copy; &amp; разлом', 'Cash $& [PLANET_IMAGE] разлом', 'Имя "Альфа" и \'Бета\' разлом'];
foreach ([1, 2, 3] as $step) {
  $native = file_get_contents(SN_ROOT_PHYSICAL . "design/templates/OpenGame/fleet{$step}.tpl.html");
  $check(strpos($native, '<!-- INCLUDE fleet_miniatures -->') !== false, 'Each native wizard page must use the tested partial');
  foreach ($names as $name) {
    $template = new ViewFixtureTemplate();
    $template->_rootref = &$template->_tpldata['.'][0];
    $template->assign_block_vars('fleets', ['START_COORDS' => '[1:2:3]', 'START_NAME' => $name,
      'END_COORDS' => '[0:18:6]', 'END_NAME' => $name, 'MESSAGE' => 0, 'MISSION' => 1]);
    ZeroGalaxy\AttackView::escapeFleetNames($template);
    $html = $template->renderSource($partial);
    preg_match('/<span id="end_name">(.*?)<\/span>/s', $html, $match);
    $check(isset($match[1]) && html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') === $name,
      'Native miniature must show literal name without decoding an extra layer at step ' . $step);
    $check(substr_count($html, htmlspecialchars($name, ENT_QUOTES, 'UTF-8')) === 2,
      'Source and destination names must each be escaped exactly once');
  }
}

$user = ['id' => 7];
$lang = array_fill_keys(['zero_galaxy_fleet_limit', 'zero_galaxy_attack_selected', 'zero_galaxy_attack_remaining',
  'zero_galaxy_attack_over', 'zero_galaxy_points_short', 'zero_galaxy_attack_title', 'zero_galaxy_attack_missing'], 'label');
$rift = ['rift_id' => 543, 'system' => 18, 'position' => 6, 'config_snapshot' => json_encode([
  'profile' => ['version' => 1, 'fleet_limit' => 1000, 'category' => ['name' => 'Custom', 'color' => '#7B2CBF']],
])];
$payloadMethod = new ReflectionMethod(ZeroGalaxy\AttackView::class, 'payload');
$payloadMethod->setAccessible(true);
$ships = [210 => 9007199254740993];
$payload = $payloadMethod->invoke(null, $rift, 1, $ships, $names[1], 42, 1700000000);
$check($payload['targetName'] === $names[1], 'JSON/banner target name must remain raw');
$check($payload['fixedFleet'] === [210 => '9007199254740993'], 'Fixed fleet counts must preserve every digit across JSON');
$decoded = json_decode(json_encode($payload), true);
$check($decoded['fixedFleet'][210] === '9007199254740993' && $ships[210] === 9007199254740993,
  'Presentation serialization must not change native fleet quantities');
echo "Views: {$assertions} assertions passed; real native partial/template engine, no game bootstrap or database.\n";
