<?php

namespace {
  if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
}

namespace Fleet {
  class DbFleetStatic {
    public static $fleets = [];
    public static $reads = 0;
    public static function fleet_count_flying($owner) { return 2; }
    public static function fleet_list_by_planet_coords($galaxy, $system, $position, $type) {
      self::$reads++;
      return self::$fleets;
    }
  }
  class FleetStatic {
    public static function flt_fleet_speed(...$args) { return 10000; }
  }
}

namespace {
  define('INSIDE', true);
  define('SN_ROOT_PHYSICAL', dirname(__DIR__, 3) . '/');
  define('SN_ROOT_RELATIVE', '');
  define('SN_ROOT_VIRTUAL', '/');
  define('PT_PLANET', 1); define('PT_MOON', 3); define('PT_DEBRIS', 2);
  define('MT_SPY', 6); define('MT_RECYCLE', 8); define('MT_COLONIZE', 7); define('MT_EXPLORE', 15);
  define('P_CAPACITY', 'capacity'); define('SHIP_SPY', 210); define('RES_DEUTERIUM', 903);
  define('FLEET_STATUS_FLYING', 0);
  define('PLAYER_OPTION_FLEET_SPY_DEFAULT', 1);
  define('PLAYER_OPTION_UNIVERSE_OLD', 2);
  require_once SN_ROOT_PHYSICAL . 'classes/template_compile.php';
  require_once SN_ROOT_PHYSICAL . 'classes/template.php';
  foreach (['Lifecycle', 'Profile', 'RiftName', 'UniverseView'] as $class) {
    require_once dirname(__DIR__) . '/classes/ZeroGalaxy/' . $class . '.php';
  }

  class SN {
    public static $lang = [];
    public static $config;
    public static $user_options = [1 => 1, 2 => 0];
  }
  class Universe {
    public static $distances = [];
    public static function distance($from, $to) {
      self::$distances[] = [$from['galaxy'], $to['galaxy']];
      return $from['galaxy'] !== $to['galaxy'] ? 20000 : 1000;
    }
    public static function flt_server_flight_speed_multiplier() { return 1; }
  }
  class HelperString {
    public static function numberFloorAndFormat($number) { return number_format($number, 0, '', '.'); }
  }
  class DebrisFixtureTemplate extends template {
    public function renderSource(string $source): string {
      ob_start();
      $this->evaluate($this->compiler->compile($source));
      return ob_get_clean();
    }
  }
  class DebrisFixtureModule {
    public function addModuleTemplate($name, $template) { return $template; }
    public function getRootRelative() { return 'modules/zero_galaxy/'; }
    public function getVersion() { return 'fixture'; }
  }
  $owned = [209 => 5, 223 => 0, 210 => 7, 903 => 100000];
  function lng_include($name) {}
  function db_user_by_id($id) { return $id === 9 ? ['id' => 9, 'username' => 'NPC'] : null; }
  function sn_get_groups($name) { return $name === 'flt_recyclers' ? [209 => 209, 223 => 223] : []; }
  function get_ship_data($id, $user) {
    return ['capacity' => $id === 223 ? 40000 : 20000, 'consumption' => 1, 'speed' => 10000];
  }
  function mrc_get_level($user, $source, $id, ...$args) { global $owned; return $owned[$id] ?? 0; }
  function js_safe_string($text) { return addslashes($text); }
  function GetMaxFleets($user) { return 20; }
  function sys_unit_str2arr($value) {
    $units = [];
    foreach (explode(';', $value) as $item) {
      if ($item) { [$id, $count] = explode(',', $item); $units[(int) $id] = (int) $count; }
    }
    return $units;
  }
  SN::$config = (object) ['game_maxSystem' => 199, 'game_maxPlanet' => 7, 'users_amount' => 5, 'tpl_minifier' => false];
  include dirname(__DIR__) . '/language/ru/zero_galaxy.mo.php';
  $lang = SN::$lang = $a_lang_array;
  $user = ['id' => 1];
  $planetrow = ['id' => 100, 'galaxy' => 8, 'system' => 194, 'planet' => 8, 'planet_type' => PT_MOON];
  $template_result = [];
  $base = ['rift_id' => 1, 'system' => 10, 'position' => 1, 'owner_id' => 9, 'planet_id' => 200,
    'born_at' => 10, 'opens_at' => 20, 'unstable_at' => 80, 'closes_at' => 100, 'disappears_at' => 120,
    'image' => 'normaltempplanet01', 'config_snapshot' => '{}', 'debris_metal' => '9007199254740993', 'debris_crystal' => '17'];
  $rifts = [$base,
    array_replace($base, ['rift_id' => 2, 'position' => 2, 'unstable_at' => 30, 'debris_metal' => '60000', 'debris_crystal' => '40000']),
    array_replace($base, ['rift_id' => 3, 'position' => 3, 'unstable_at' => 30, 'closes_at' => 40]),
    array_replace($base, ['rift_id' => 4, 'position' => 4, 'opens_at' => 60]),
    array_replace($base, ['rift_id' => 5, 'position' => 5, 'debris_metal' => '0', 'debris_crystal' => '0']),
    array_replace($base, ['rift_id' => 6, 'position' => 6, 'owner_id' => 0]),
    array_replace($base, ['rift_id' => 7, 'position' => 7, 'disappears_at' => 40]),
  ];
  $incoming = ['fleet_owner' => 1, 'fleet_mission' => MT_RECYCLE, 'fleet_mess' => 0, 'fleet_end_galaxy' => 0,
    'fleet_end_system' => 10, 'fleet_end_type' => PT_DEBRIS, 'fleet_end_planet' => 2, 'fleet_array' => '209,2'];
  \Fleet\DbFleetStatic::$fleets = [$incoming,
    array_replace($incoming, ['fleet_owner' => 2, 'fleet_array' => '209,999']),
    array_replace($incoming, ['fleet_mess' => 1, 'fleet_array' => '209,999']),
    array_replace($incoming, ['fleet_mission' => MT_SPY, 'fleet_array' => '209,999']),
    array_replace($incoming, ['fleet_end_type' => PT_PLANET, 'fleet_array' => '209,999']),
  ];
  $render = function ($rows) {
    $template = new DebrisFixtureTemplate();
    $template->_rootref = &$template->_tpldata['.'][0];
    return \ZeroGalaxy\UniverseView::render(new DebrisFixtureModule(), $template, $rows, 10, 50, ['refresh_seconds' => 30]);
  };
  $assertions = 0;
  $check = function ($condition, $message) use (&$assertions) {
    $assertions++;
    if (!$condition) { throw new RuntimeException($message); }
  };
  $template = $render($rifts);
  $rows = $template->_tpldata['galaxyrow'];
  $payload = json_decode($template->_rootref['ZERO_RIFTS_JSON'], true);
  $check($rows[0]['DEBRIS_METAL'] === '9007199254740993' && $rows[0]['DEBRIS_CRYSTAL'] === '17', 'Native view fields must retain decimal-string resource precision');
  $check($rows[0]['DEBRIS'] === 9007199254741010 && $rows[0]['DEBRIS_WILL_GATHER'] === 100000, 'Real debris and available source capacity must reach the native view');
  $check($rows[1]['DEBRIS_RESERVED'] === 40000 && $rows[1]['DEBRIS_WILL_GATHER'] === 60000, 'Only own incoming recycling capacity is reserved');
  $check($rows[1]['DEBRIS_RESERVED_PERCENT'] === 40.0 && $rows[1]['DEBRIS_GATHER_TOTAL_PERCENT'] === 100, 'Native popup percentages must cover incoming and available recycling');
  foreach ([2,3,4,5,6] as $position) {
    $check($rows[$position]['DEBRIS'] === 0 && $rows[$position]['DEBRIS_WILL_GATHER'] === 0, 'Closed, energy, empty, foreign and expired positions must show no debris');
  }
  $check($payload['rifts'][0]['canRecycle'] && $payload['rifts'][1]['canRecycle'], 'Active and unstable debris must allow quick collection');
  $check(!$payload['rifts'][2]['canRecycle'] && !$payload['rifts'][3]['canRecycle'] && !$payload['rifts'][4]['canRecycle'], 'Nonexistent or empty fields must not allow collection');
  $check($payload['recycleUrl'] === 'index.php?page=zero_galaxy_recycle' && $payload['recycleMission'] === MT_RECYCLE
    && $payload['debrisType'] === PT_DEBRIS, 'The native quick control must be routed to the module endpoint');
  $check($template->_rootref['PLANET_RECYCLERS'] === 5 && $template->_rootref['PLANET_RECYCLERS_TEXT'] === '5', 'Available recycler counter must use the selected source planet');
  $check(\Fleet\DbFleetStatic::$reads === 1, 'Incoming fleets must be read once per system');
  $check(Universe::$distances && count(array_filter(Universe::$distances, function ($pair) { return $pair !== [1,0]; })) === 0,
    'All native transport estimates must use one intergalactic distance from any actual source');

  foreach (['universe_new', 'universe_old'] as $nativeView) {
    $html = $template->renderSource(file_get_contents(SN_ROOT_PHYSICAL . 'design/templates/OpenGame/' . $nativeView . '.tpl.html'));
    $check(substr_count($html, 'uni_show_debris') >= 2, 'Both native universe layouts must render populated debris fields');
  }
  $native = file_get_contents(SN_ROOT_PHYSICAL . 'design/templates/OpenGame/universe.tpl.html');
  preg_match('/var uni_row = new Array\(\);([\s\S]*?)\/\/ -->/', $native, $match);
  $nativeData = $template->renderSource($match[1]);
  $check(strpos($nativeData, "debris_metal: '9007199254740993'") !== false, 'Actual native JavaScript data template must preserve all debris digits');

  $owned[209] = 0;
  $emptySource = $render([$rifts[1]]);
  $emptyPayload = json_decode($emptySource->_rootref['ZERO_RIFTS_JSON'], true);
  $check($emptySource->_rootref['PLANET_RECYCLERS'] === 0 && !$emptyPayload['rifts'][0]['canRecycle'], 'A source without recyclers keeps the field visible but cannot dispatch');
  $check($emptySource->_tpldata['galaxyrow'][1]['DEBRIS'] === 100000, 'No available recyclers must not hide the actual debris resources');
  $reads = \Fleet\DbFleetStatic::$reads;
  $render([$rifts[2], $rifts[3], $rifts[4]]);
  $check(\Fleet\DbFleetStatic::$reads === $reads, 'Systems without visible debris must not query incoming recyclers');
  echo "Debris view: {$assertions} assertions passed; real native transport estimates and both universe layouts.\n";
}
