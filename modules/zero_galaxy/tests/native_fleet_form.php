<?php

namespace Planet {
  class DBStaticPlanet {
    public static $destination;
    public static function db_planet_by_gspt(...$coordinates) { return self::$destination; }
  }
}

namespace Fleet {
  class FleetDispatcher { public function __construct($gc = null) {} }
}

namespace {
  if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
  define('INSIDE', true);
  define('INITIAL_PAGE', 'fleet');
  define('SN_ROOT_PHYSICAL', dirname(__DIR__, 3) . '/');
  define('SN_ROOT_RELATIVE', '');
  define('SN_TIME_NOW', 1700000000);
  foreach (['PT_PLANET'=>1,'PT_DEBRIS'=>2,'PT_MOON'=>3,'MT_ATTACK'=>1,'MT_AKS'=>2,'MT_SPY'=>6,
    'MT_RECYCLE'=>8,'SHIP_SPY'=>210] as $key => $value) { define($key, $value); }
  require_once __DIR__ . '/support/autoload.php';
  require_once SN_ROOT_PHYSICAL . 'classes/template_compile.php';
  require_once SN_ROOT_PHYSICAL . 'classes/template.php';
  require __DIR__ . '/support/assertions.php';

  class SN {
    public static $gc;
    public static $config;
    public static $lang;
    public static $sn_secret_word = 'native-fleet-form-test-signing-key';
  }
  class Universe { public static function getBaseGalaxyDistance() { return 20000; } }
  class SnTemplate {
    public static function messageBox($message, $title) { throw new \RuntimeException($message); }
  }
  class NativeFormFixtureTemplate extends template {
    public function renderSource(string $source): string {
      ob_start(); $this->evaluate($this->compiler->compile($source)); return ob_get_clean();
    }
  }
  class NativeFormFixtureModule {
    public function getRootRelative() { return 'modules/zero_galaxy/'; }
    public function addModuleTemplate($name, $template) {
      $template->files[$name] = SN_ROOT_PHYSICAL . 'modules/zero_galaxy/design/templates/OpenGame/' . $name . '.tpl.html';
      return $template;
    }
  }
  class NativeFormFixtureStorage extends \ZeroGalaxy\Storage {
    public $target;
    public $lookups = [];
    public function __construct() {}
    public function findActive(int $system, int $position, int $now): ?array {
      $this->lookups[] = [$system, $position, $now];
      return $system === 3 && $position === 4 ? $this->target : null;
    }
  }
  function sys_get_param_int($name, $default = 0) { return (int) ($_POST[$name] ?? $_GET[$name] ?? $default); }
  function sn_get_groups($group) { return $group === 'flt_recyclers' ? [209] : [202,209,210]; }
  function eco_get_total_cost($id, $amount) { return ['total' => [202=>4000,209=>18000,210=>1000][$id] * $amount]; }
  function uni_render_coordinates($row) { return '[' . $row['galaxy'] . ':' . $row['system'] . ':' . $row['planet'] . ']'; }
  function flt_travel_data($actor, $source, $target, $ships, $speed, $data, $distance) {
    if ($distance !== 20000) { throw new \RuntimeException('A native zero flight must use one-galaxy distance'); }
    return ['consumption' => 13, 'hold' => 987];
  }
  SN::$config = (object) ['game_maxSystem'=>199,'game_maxPlanet'=>15];
  SN::$lang = ['type_mission'=>[1=>'Attack',2=>'ACS',6=>'Spy',8=>'Recycle'], 'sys_planet_type_sh'=>[1=>'P',2=>'D',3=>'M']]
    + array_fill_keys(['zero_galaxy_fleet_limit','zero_galaxy_attack_selected','zero_galaxy_attack_remaining',
      'zero_galaxy_attack_over','zero_galaxy_points_short','zero_galaxy_attack_title','zero_galaxy_attack_missing',
      'zero_galaxy_acs_join','zero_galaxy_acs_selected','zero_galaxy_acs_arrival','zero_galaxy_acs_return','zero_galaxy_acs_wait',
      'zero_galaxy_attack_bad_request','zero_galaxy_attack_failed','fl_error'], 'label');
  SN::$lang['zero_galaxy_attack_bad_request'] = 'BAD_REQUEST';
  SN::$lang['zero_galaxy_attack_failed'] = 'FAILED';
  SN::$lang['zero_galaxy_unavailable'] = 'UNAVAILABLE';
  SN::$lang['fl_bad_mission'] = 'BAD_MISSION';
  SN::$gc = (object) ['fleetDispatcher'=>new \ZeroGalaxy\RiftFleetDispatcher()];
  $user = ['id'=>7]; $planetrow = ['id'=>20,'deuterium'=>1000];
  $module = new NativeFormFixtureModule();
  $storage = new NativeFormFixtureStorage();
  $storage->target = ['rift_id'=>123, 'system'=>3, 'position'=>4, 'planet_id'=>30, 'npc_owner_id'=>8,
    'born_at'=>SN_TIME_NOW-60,'opens_at'=>SN_TIME_NOW-30,'unstable_at'=>SN_TIME_NOW+60,
    'closes_at'=>SN_TIME_NOW+120,'disappears_at'=>SN_TIME_NOW+180,
    'config_snapshot'=>json_encode(['profile'=>['version'=>1,'fleet_limit'=>100000,'category'=>[
      'key'=>'tiny','name'=>'Крошечный &copy;','color'=>'#ADFF2F']]])];
  $fixture = function (string $name, int $galaxy = 0) {
    $template = new NativeFormFixtureTemplate();
    $template->_rootref = &$template->_tpldata['.'][0];
    $template->files[$name] = SN_ROOT_PHYSICAL . 'design/templates/OpenGame/' . $name . '.tpl.html';
    $template->assign_vars(['galaxy'=>$galaxy,'system'=>3,'planet'=>4,
      'planet_type'=>(int) ($_POST['planet_type'] ?? PT_PLANET),'speed'=>10,'target_mission'=>MT_ATTACK]);
    $template->assign_var('usedfleet', $_POST['usedfleet'] ?? str_rot13(base64_encode(json_encode([202=>5]))));
    $template->assign_block_vars('fleets', ['START_COORDS'=>'[1:1:1]']);
    return $template;
  };
  $post = function (int $type = PT_PLANET) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['fleet_page'=>2];
    $_POST = ['galaxy'=>'0','system'=>'3','planet'=>'4','planet_type'=>(string) $type,'speed'=>'10',
      'usedfleet'=>str_rot13(base64_encode(json_encode([202=>5,209=>2,210=>1])))];
  };

  $post(); $template = $fixture('fleet2', 1); $original = $template->files;
  \ZeroGalaxy\NativeFleetForm::decorate($module,$template,[]);
  $check($template->files === $original, 'An ordinary destination keeps the native confirmation untouched');
  $template = $fixture('fleet1', 1);
  \ZeroGalaxy\NativeFleetForm::decorate($module,$template,[]);
  $payload = json_decode($template->_rootref['ZERO_ATTACK_JSON'],true);
  $check($payload['step'] === 1 && $payload['distance'] === 20000,
    'The native target picker receives the distance adapter before a player enters galaxy zero');

  $post(); $template = $fixture('fleet2');
  \ZeroGalaxy\NativeFleetForm::decorate($module,$template,[]);
  $check(array_column($template->_tpldata['missions'],'ID') === [MT_ATTACK,MT_SPY],
    'A planet exposes attack and reconnaissance even when the selected fleet includes recyclers');
  $check($template->_rootref['fleet_capacity'] === 987 && $template->_rootref['planet_deuterium'] === 987,
    'Confirmation uses the native travel estimate at the fixed galaxy distance');
  $check($template->_tpldata['fleets'][0]['END_COORDS'] === '[0:3:4]', 'The native route shows the empty zero-galaxy target');
  $payload = json_decode($template->_rootref['ZERO_ATTACK_JSON'],true);
  $fields = $payload['fields'];
  $check(\ZeroGalaxy\AttackPolicy::validToken($fields['token'],$fields['token_at'],7,20,0,SN_TIME_NOW),
    'Confirmation signs the actual pilot and departure planet for the final native POST');
  $source = preg_replace('/<!-- INCLUDE [a-z_]+ -->/', '', file_get_contents(SN_ROOT_PHYSICAL . 'design/templates/OpenGame/fleet2.tpl.html'));
  $html = $template->renderSource($source);
  foreach ([MT_ATTACK,MT_SPY] as $mission) {
    $check(strpos($html, 'id="target_mission' . $mission . '"') !== false, 'Native mission control is rendered: ' . $mission);
  }
  $check(strpos($html, 'id="target_mission' . MT_RECYCLE . '"') === false,
    'The real native planet confirmation must not render a recycle radio button');
  $check(strpos($html, 'action="fleet.php?fleet_page=3"') !== false
    && strpos($html, 'name="galaxy"          value="0"') !== false,
    'The actual native template posts galaxy zero to fleet.php without a new endpoint');
  $post(PT_DEBRIS); $template=$fixture('fleet2');
  \ZeroGalaxy\NativeFleetForm::decorate($module,$template,[]);
  $check(array_column($template->_tpldata['missions'],'ID') === [MT_RECYCLE]
    && $template->_tpldata['missions'][0]['CHECKED'], 'A debris destination offers only recycling');
  $post(PT_MOON); $template=$fixture('fleet2');
  \ZeroGalaxy\NativeFleetForm::decorate($module,$template,[]);
  $check(array_column($template->_tpldata['missions'],'ID') === [MT_ATTACK,MT_SPY],
    'A moon offers attack and reconnaissance, never recycling');

  $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];
  $_GET = ['galaxy'=>'0','system'=>'3','planet'=>'4','planet_type'=>'1','target_mission'=>'1'];
  $template = $fixture('fleet0');
  \ZeroGalaxy\NativeFleetForm::decorate($module,$template,['acs_enabled'=>true],$storage);
  $payload = json_decode($template->_rootref['ZERO_ATTACK_JSON'],true);
  $check($payload['step'] === 0 && (string) $payload['target']['limit'] === '100000'
    && $payload['target']['system'] === 3 && $payload['target']['position'] === 4,
    'A direct attack from a report exposes the current rift budget in the native ship selector');
  $check($payload['target']['name'] === 'Крошечный &copy; разлом' && $payload['target']['color'] === '#ADFF2F',
    'The native selector receives the current target name and category color without HTML decoding');
  $check($payload['unitCosts'][202] === '4000' && (string) $payload['costScale'] === '1'
    && $payload['attackMissions'] === [MT_ATTACK,MT_AKS],
    'The single wizard supplies native resource costs and the missions subject to its budget');

  $_GET['target_mission'] = '0'; $template = $fixture('fleet0');
  $template->assign_var('target_mission',0);
  \ZeroGalaxy\NativeFleetForm::decorate($module,$template,[],$storage);
  $payload = json_decode($template->_rootref['ZERO_ATTACK_JSON'] ?? '{}',true);
  $check(empty($payload['target']), 'A generic ship selector must not announce a budget before an attack target is chosen');
  $_GET['target_mission'] = '1'; $_GET['galaxy'] = '1'; $template = $fixture('fleet0',1);
  $original = $template->files; $beforeLookups = count($storage->lookups);
  \ZeroGalaxy\NativeFleetForm::decorate($module,$template,[],$storage);
  $check($template->files === $original && count($storage->lookups) === $beforeLookups,
    'An ordinary attack retains the native ship selector without looking up a zero-galaxy budget');

  $post(); $_POST['target_mission'] = '1'; $template = $fixture('fleet1');
  \ZeroGalaxy\NativeFleetForm::decorate($module,$template,[],$storage);
  $payload = json_decode($template->_rootref['ZERO_ATTACK_JSON'],true);
  $check($payload['step'] === 1 && (string) $payload['target']['limit'] === '100000',
    'Manual zero-galaxy coordinates retain their budget in the ordinary destination and speed step');
  $_POST['usedfleet'] = str_rot13(base64_encode(json_encode([202=>9007199254740993,209=>2,210=>1])));
  $template = $fixture('fleet2');
  \ZeroGalaxy\NativeFleetForm::decorate($module,$template,[],$storage);
  $payload = json_decode($template->_rootref['ZERO_ATTACK_JSON'],true);
  $check($payload['step'] === 2 && $payload['fixedFleet'][202] === '9007199254740993',
    'Native confirmation preserves every digit of fleet quantities in its budget payload');
  $check((string) $payload['target']['limit'] === '100000', 'The native confirmation uses the same current target budget');
  foreach ([0,11] as $speed) {
    $post(); $_POST['speed'] = (string) $speed; $template = $fixture('fleet2');
    try {
      \ZeroGalaxy\NativeFleetForm::decorate($module,$template,[],$storage);
      $check(false, 'An invalid native speed must stop before dispatch');
    } catch (\RuntimeException $error) {
      $check($error->getMessage() === 'BAD_REQUEST' && !isset($template->_rootref['ZERO_ATTACK_JSON']),
        'The native form rejects an out-of-range speed before creating a signed final submission');
    }
  }

  $_POST=['galaxy'=>'1']; $_GET=['fleet_page'=>3];
  \ZeroGalaxy\NativeFleetForm::intercept();
  $check($_GET['fleet_page'] === 3 && !isset($_POST['fleet_page']), 'Ordinary final dispatch is never intercepted');
  $_POST=['galaxy'=>'0']; $_GET=['mode'=>3];
  \ZeroGalaxy\NativeFleetForm::intercept();
  $check($_GET['fleet_page'] === 2 && $_POST['fleet_page'] === 2,
    'Zero final dispatch defers through the read-only native confirmation until common.php prepares the player');
  $post(PT_PLANET); $_GET['fleet_page'] = 3;
  $_POST += ['target_mission'=>(string) MT_RECYCLE,'source_id'=>'20','token_at'=>(string) SN_TIME_NOW,
    'token'=>\ZeroGalaxy\AttackPolicy::token(7,20,0,SN_TIME_NOW)];
  \ZeroGalaxy\NativeFleetForm::intercept(); $template=$fixture('fleet2');
  try {
    \ZeroGalaxy\NativeFleetForm::decorate($module,$template,[],$storage);
    $check(false, 'A forged final recycling POST to a planet must be rejected');
  } catch (\RuntimeException $error) {
    $check($error->getMessage() === 'BAD_MISSION' && !isset($template->_rootref['ZERO_ATTACK_JSON']),
      'A validly signed planet/recycle POST stops before sending instead of being converted to debris');
  }
  echo "Native fleet form: {$assertions} assertions passed; native template compiler, no game bootstrap or database.\n";
}
