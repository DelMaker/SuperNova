<?php

if (PHP_SAPI !== 'cli' || getenv('SUPERNOVA_DB_HOST') !== 'db'
  || getenv('SUPERNOVA_DB_NAME') !== 'supernova' || !is_file('/var/lib/supernova/cookie-secret')) {
  exit("Recycler sender tests require the local SuperNova Docker environment.\n");
}
chdir(dirname(__DIR__, 3));
$_SERVER = array_replace($_SERVER, [
  'SCRIPT_NAME' => '/zero-recycle-send-test.php', 'SCRIPT_FILENAME' => getcwd() . '/zero-recycle-send-test.php',
  'HTTP_HOST' => 'localhost:8080', 'SERVER_NAME' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1',
  'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/zero-recycle-send-test.php',
]);
$_GET = $_POST = $_COOKIE = $_REQUEST = [];
define('IN_AJAX', true);
$allow_anonymous = true;
require getcwd() . '/common.php';
require_once SN_ROOT_PHYSICAL . 'includes/includes/flt_functions.php';
foreach (['Settings', 'Profile', 'Lifecycle', 'Storage', 'RiftTarget', 'NativeDispatch', 'RecycleSender'] as $class) {
  require_once dirname(__DIR__) . '/classes/ZeroGalaxy/' . $class . '.php';
}

use DBAL\db_mysql;
use Planet\DBStaticPlanet;
use Unit\DBStaticUnit;
use ZeroGalaxy\RecycleSender;
use ZeroGalaxy\Settings;
use ZeroGalaxy\Storage;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = SN::$db->link;
$original = [SN::$db, SN::$db->db_prefix, SN::$sys_user_logged_in, $user, $planetrow];
$prefix = 'zg_rcsend_' . bin2hex(random_bytes(5)) . '_';
$tables = ['zero_galaxy_npcs', 'zero_galaxy_rifts', 'zero_galaxy_state', 'fleets', 'unit', 'planets', 'users'];
$assertions = 0;
$check = function ($condition, string $message) use (&$assertions) {
  $assertions++;
  if (!$condition) { throw new RuntimeException($message); }
};
$one = function (string $sql) use ($db): ?array { return $db->query($sql)->fetch_assoc(); };
$scalar = function (string $sql) use ($db) { return $db->query($sql)->fetch_row()[0]; };
$rows = function (string $sql) use ($db): array { return $db->query($sql)->fetch_all(MYSQLI_ASSOC); };

class RecyclerRefreshFailure {
  private $db;
  private $source;
  public $failures = 0;
  public function __construct($db, int $source) { $this->db = $db; $this->source = $source; }
  public function __get($name) { return $this->db->$name; }
  public function __call($name, $arguments) { return $this->db->$name(...$arguments); }
  public function doquery($sql, $fetch = false, $skip = false) {
    if (!$this->failures && !db_mysql::$db_in_transaction
      && strpos($sql, 'SELECT * FROM {{planets}}') === 0 && strpos($sql, '`id` = ' . $this->source) !== false) {
      $this->failures++;
      throw new RuntimeException('Injected recycler committed source refresh failure');
    }
    return $this->db->doquery($sql, $fetch, $skip);
  }
}

try {
  foreach (['users', 'planets', 'unit', 'fleets'] as $table) {
    $db->query("CREATE TABLE {$prefix}{$table} LIKE {$original[1]}{$table}");
  }
  SN::$db->db_prefix = $prefix;
  SN::$db->schema()->clear();
  DBStaticUnit::cache_clear();
  $settings = Settings::load(['target_active_per_system' => 0]);
  $storage = new Storage($db, $prefix);
  $storage->tick(SN_TIME_NOW, $settings, 1, 1, true);
  $db->query("INSERT INTO {$prefix}users (username,password,galaxy,total_points,user_lastip,onlinetime)
    VALUES ('Isolated recycler pilot','!disabled!',5,1000000,'127.0.0.2'," . SN_TIME_NOW . ")");
  $pilot = (int) $db->insert_id;
  $db->query("INSERT INTO {$prefix}planets (name,id_owner,galaxy,system,planet,planet_type,deuterium)
    VALUES ('Recycler origin',{$pilot},5,2,8,1,1000000000)");
  $sourceId = (int) $db->insert_id;
  $db->query("UPDATE {$prefix}users SET id_planet={$sourceId}, current_planet={$sourceId} WHERE id={$pilot}");
  foreach ([TECH_COMPUTER => 5, TECH_ENGINE_CHEMICAL => 8] as $id => $level) {
    $db->query("INSERT INTO {$prefix}unit (unit_player_id,unit_location_type,unit_location_id,unit_type,unit_snid,unit_level)
      VALUES ({$pilot},4,{$pilot},100,{$id},{$level})");
  }
  foreach ([SHIP_RECYCLER => 50, SHIP_SMALL_FIGHTER_LIGHT => 17] as $id => $count) {
    $db->query("INSERT INTO {$prefix}unit (unit_player_id,unit_location_type,unit_location_id,unit_type,unit_snid,unit_level)
      VALUES ({$pilot},1,{$sourceId},200,{$id},{$count})");
  }
  $born = SN_TIME_NOW - 10; $opens = SN_TIME_NOW - 9;
  $unstable = SN_TIME_NOW + 10000; $closes = SN_TIME_NOW + 20000; $disappears = SN_TIME_NOW + 30000;
  $db->query("INSERT INTO {$prefix}zero_galaxy_rifts
    (system,position,born_at,opens_at,unstable_at,closes_at,disappears_at,image,config_snapshot)
    VALUES (1,1,{$born},{$opens},{$unstable},{$closes},{$disappears},'normaltempplanet01','{}')");
  $riftId = (int) $db->insert_id;
  $storage->tick(SN_TIME_NOW, $settings, 1, 1, true);
  $rift = $one("SELECT * FROM {$prefix}zero_galaxy_rifts WHERE rift_id={$riftId}");
  $targetId = (int) $rift['planet_id'];
  $ownerId = (int) $scalar("SELECT npc_owner_id FROM {$prefix}zero_galaxy_state WHERE id=1");
  $db->query("UPDATE {$prefix}planets SET debris_metal=30000,debris_crystal=10000 WHERE id={$targetId}");

  $refresh = function () use ($pilot, $sourceId) {
    global $user, $planetrow;
    DBStaticUnit::cache_clear();
    $user = db_user_by_id($pilot, true);
    $planetrow = DBStaticPlanet::db_planet_by_id($sourceId, true);
    SN::$sys_user_logged_in = true;
  };
  $state = function () use ($rows, $prefix): array {
    $result = [];
    foreach (['users','planets','unit','fleets','zero_galaxy_rifts'] as $table) {
      $result[$table] = $rows("SELECT * FROM {$prefix}{$table}");
    }
    return $result;
  };
  $post = function (array $overrides = []) use ($riftId) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_POST = array_replace(['galaxy' => '0', 'system' => '1', 'planet' => '1',
      'planet_type' => (string) PT_DEBRIS, 'mission' => (string) MT_RECYCLE, 'rift_id' => (string) $riftId], $overrides);
  };
  $send = function () use ($storage, $settings) { return RecycleSender::send($storage, $settings); };
  $rejected = function (string $message, string $expected = null) use ($send, $state, $check, $scalar) {
    $before = $state();
    $response = $send();
    $check(strpos($response, '|flt_recyclers=') === false && ($expected === null || $response === $expected), $message . ': correct rejection');
    $check($state() === $before, $message . ': ships, fuel, target and flights untouched');
    $check(!db_mysql::$db_in_transaction && (int) $scalar('SELECT @@in_transaction') === 0, $message . ': no leaked transaction');
  };

  foreach ([['galaxy' => '1'],['system' => '0'],['planet' => '0'],['planet_type' => PT_PLANET],
    ['mission' => MT_ATTACK],['rift_id' => '0'],['rift_id' => ['1']],['system' => '1.5']] as $invalid) {
    $refresh(); $post($invalid); $rejected('Malformed quick-recycle request');
  }
  $refresh(); $post(); unset($_POST['rift_id']); $rejected('Missing generation ID');
  $post(); $_SERVER['REQUEST_METHOD']='GET'; $rejected('Non-POST request');
  $post(); unset($_SERVER['HTTP_X_REQUESTED_WITH']); $rejected('Non-AJAX request');
  $post(); SN::$sys_user_logged_in=false; $rejected('Unauthenticated caller');
  $refresh(); $post(['rift_id' => $riftId + 1]); $rejected('Replaced generation');
  foreach (['opens_at' => SN_TIME_NOW + 1, 'closes_at' => SN_TIME_NOW] as $field => $value) {
    $refresh(); $post();
    if ($field === 'closes_at') {
      $db->query("UPDATE {$prefix}zero_galaxy_rifts SET unstable_at=" . (SN_TIME_NOW-1) . " WHERE rift_id={$riftId}");
    }
    $db->query("UPDATE {$prefix}zero_galaxy_rifts SET {$field}={$value} WHERE rift_id={$riftId}");
    $rejected('Unavailable phase ' . $field);
    $db->query("UPDATE {$prefix}zero_galaxy_rifts SET {$field}=" . (int) $rift[$field] . " WHERE rift_id={$riftId}");
    if ($field === 'closes_at') {
      $db->query("UPDATE {$prefix}zero_galaxy_rifts SET unstable_at={$unstable} WHERE rift_id={$riftId}");
    }
  }
  foreach (['user_bot' => 0,'authlevel' => 1,'parent_account_id' => 7,'parent_account_global' => 8] as $field => $value) {
    $refresh(); $post();
    $old = $scalar("SELECT {$field} FROM {$prefix}users WHERE id={$ownerId}");
    $db->query("UPDATE {$prefix}users SET {$field}={$value} WHERE id={$ownerId}");
    $rejected('Invalid NPC marker ' . $field);
    $db->query("UPDATE {$prefix}users SET {$field}={$old} WHERE id={$ownerId}");
  }
  $db->query("UPDATE {$prefix}planets SET debris_metal=0,debris_crystal=0 WHERE id={$targetId}");
  $refresh(); $post(); $rejected('Empty debris field', $lang['fl_attack_error'][ATTACK_NO_DEBRIS]);
  $db->query("UPDATE {$prefix}planets SET debris_metal=30000,debris_crystal=10000 WHERE id={$targetId}");
  $db->query("UPDATE {$prefix}unit SET unit_level=0 WHERE unit_location_id={$sourceId} AND unit_snid=" . SHIP_RECYCLER);
  $refresh(); $post(); $rejected('No available recyclers', $lang['fl_attack_error'][ATTACK_NO_RECYCLERS]);
  $db->query("UPDATE {$prefix}unit SET unit_level=50 WHERE unit_location_id={$sourceId} AND unit_snid=" . SHIP_RECYCLER);
  $db->query("UPDATE {$prefix}planets SET deuterium=0 WHERE id={$sourceId}");
  $refresh(); $post(); $rejected('No launch fuel', $lang['fl_attack_error'][ATTACK_NO_FUEL]);
  $db->query("UPDATE {$prefix}planets SET deuterium=1000000000 WHERE id={$sourceId}");
  $db->query("UPDATE {$prefix}users SET vacation=1 WHERE id={$pilot}");
  $refresh(); $post(); $rejected('Native vacation rule', $lang['fl_attack_error'][ATTACK_OWN_VACATION]);
  $db->query("UPDATE {$prefix}users SET vacation=0 WHERE id={$pilot}");
  $db->query("UPDATE {$prefix}planets SET id_owner={$ownerId} WHERE id={$sourceId}");
  $refresh(); $post(); $rejected('Source is no longer owned by pilot');
  $db->query("UPDATE {$prefix}planets SET id_owner={$pilot} WHERE id={$sourceId}");
  $refresh(); $post();
  $maxFleets = (int) GetMaxFleets($user);
  for ($i=0; $i<$maxFleets; $i++) {
    $db->query("INSERT INTO {$prefix}fleets (fleet_owner,fleet_mission,fleet_start_time,fleet_end_time)
      VALUES ({$pilot}," . MT_RECYCLE . ',' . (SN_TIME_NOW+500) . ',' . (SN_TIME_NOW+1000) . ')');
  }
  $rejected('All native fleet slots occupied', $lang['fl_attack_error'][ATTACK_NO_SLOTS]);
  $db->query("DELETE FROM {$prefix}fleets");
  $refresh(); $post(); db_mysql::db_transaction_start();
  $response = $send();
  $check(strpos($response, '|') === false && db_mysql::$db_in_transaction
    && (int) $scalar('SELECT @@in_transaction') === 1, 'Reject nested transaction without taking ownership');
  db_mysql::db_transaction_rollback();

  $refresh(); $post();
  $db->query("CREATE TRIGGER {$prefix}reject_unit BEFORE UPDATE ON {$prefix}unit FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Recycler sender rollback test'");
  ob_start();
  try { $rejected('Native unit debit failure after fleet insert and fuel debit'); }
  finally { restore_error_handler(); ob_end_clean(); $db->query("DROP TRIGGER {$prefix}reject_unit"); }

  $refresh(); $post();
  $sourceBefore = $one("SELECT * FROM {$prefix}planets WHERE id={$sourceId}");
  $targetBefore = $one("SELECT * FROM {$prefix}planets WHERE id={$targetId}");
  $riftBefore = $one("SELECT * FROM {$prefix}zero_galaxy_rifts WHERE rift_id={$riftId}");
  $from = $planetrow; $from['galaxy']=1;
  $selection = flt_calculate_fleet_to_transport([SHIP_RECYCLER => 50], 40000, $from, $targetBefore);
  $expectedFleet = array_map('intval',$selection['fleet']);
  $travel = flt_travel_data($user,$planetrow,$targetBefore,$expectedFleet,10,[],Universe::getBaseGalaxyDistance());
  $response = $send();
  $sent = (int) $expectedFleet[SHIP_RECYCLER];
  $check(strpos($response, '|flt_recyclers=' . (50-$sent)) !== false, 'Send native minimum fleet and return precise remaining recycler count');
  $fleet = $one("SELECT * FROM {$prefix}fleets ORDER BY fleet_id DESC LIMIT 1");
  $check(sys_unit_str2arr($fleet['fleet_array']) === $expectedFleet && (int) $fleet['fleet_mission'] === MT_RECYCLE
    && (int) $fleet['fleet_end_type'] === PT_DEBRIS, 'Persist ordinary native recycle mission to debris, no fighters or captain');
  $check((int) $fleet['fleet_end_planet_id'] === $targetId && (int) $fleet['fleet_target_owner'] === $ownerId,
    'Fleet retains native destination planet and owner for lifecycle cleanup');
  $check((int) $fleet['fleet_start_galaxy'] === 5 && (int) $fleet['fleet_start_system'] === 2
    && (int) $fleet['fleet_start_planet'] === 8, 'Temporary distance normalization must not alter actual origin');
  $check((int) $fleet['fleet_start_time'] === SN_TIME_NOW + (int) $travel['duration']
    && (int) $fleet['fleet_end_time'] === SN_TIME_NOW + 2*(int) $travel['duration'], 'Both native times use exactly one intergalactic distance');
  $check((int) $scalar("SELECT unit_level FROM {$prefix}unit WHERE unit_location_id={$sourceId} AND unit_snid=" . SHIP_RECYCLER) === 50-$sent,
    'Exactly selected recyclers leave source');
  $check((int) $scalar("SELECT unit_level FROM {$prefix}unit WHERE unit_location_id={$sourceId} AND unit_snid=" . SHIP_SMALL_FIGHTER_LIGHT) === 17,
    'Quick recycling never takes combat ships');
  $check((float) $sourceBefore['deuterium'] - (float) $scalar("SELECT deuterium FROM {$prefix}planets WHERE id={$sourceId}") === (float) $travel['consumption'],
    'Exact native one-distance fuel is debited once');
  $check($targetBefore === $one("SELECT * FROM {$prefix}planets WHERE id={$targetId}") && $riftBefore === $one("SELECT * FROM {$prefix}zero_galaxy_rifts WHERE rift_id={$riftId}"),
    'Sending does not collect debris, reserve it, change phase or extend deadlines');

  $db->query("DELETE FROM {$prefix}fleets");
  $db->query("UPDATE {$prefix}unit SET unit_level=1 WHERE unit_location_id={$sourceId} AND unit_snid=" . SHIP_RECYCLER);
  $refresh(); $post();
  $response = $send();
  $check(strpos($response,'|flt_recyclers=0') !== false, 'Insufficient recycler inventory sends an available partial collection fleet');
  $db->query("DELETE FROM {$prefix}fleets");
  $db->query("UPDATE {$prefix}unit SET unit_level=50 WHERE unit_location_id={$sourceId} AND unit_snid=" . SHIP_RECYCLER);
  $refresh(); $post();
  $single = flt_travel_data($user,$from,$targetBefore,[SHIP_RECYCLER=>1],10);
  $fuel = 2*(int)$single['consumption'];
  $db->query("UPDATE {$prefix}planets SET deuterium={$fuel} WHERE id={$sourceId}");
  $db->query("UPDATE {$prefix}planets SET debris_metal=1000000000000000,debris_crystal=2000000000000000 WHERE id={$targetId}");
  $refresh(); $post();
  $response = $send();
  $check(strpos($response,'|flt_recyclers=48') !== false, 'Native selection reduces fleet to available fuel even for enormous debris fields');
  $check((float)$scalar("SELECT deuterium FROM {$prefix}planets WHERE id={$sourceId}") >= 0, 'Partial launch cannot overdraw fuel');

  $db->query("DELETE FROM {$prefix}fleets");
  $db->query("UPDATE {$prefix}unit SET unit_level=50 WHERE unit_location_id={$sourceId} AND unit_snid=" . SHIP_RECYCLER);
  $db->query("UPDATE {$prefix}planets SET deuterium=1000000000 WHERE id={$sourceId}");
  $refresh(); $post();
  $nativeDb = SN::$db;
  $fault = new RecyclerRefreshFailure($nativeDb,$sourceId);
  SN::$db = $fault;
  try { $response=$send(); } finally { SN::$db=$nativeDb; }
  $check($fault->failures===1 && strpos($response,'|flt_recyclers=0')!==false
    && (int)$scalar("SELECT COUNT(*) FROM {$prefix}fleets")===1, 'Committed launch survives a failed display refresh without reporting a failed send');
  $check(!db_mysql::$db_in_transaction && (int)$scalar('SELECT @@in_transaction')===0, 'Successful sends leave no open transaction');
} finally {
  if (db_mysql::$db_in_transaction) { db_mysql::db_transaction_rollback(); }
  SN::$db=$original[0];
  SN::$db->db_prefix=$original[1];
  SN::$db->schema()->clear();
  DBStaticUnit::cache_clear();
  SN::$sys_user_logged_in=$original[2]; $user=$original[3]; $planetrow=$original[4];
  foreach ($tables as $table) { $db->query("DROP TABLE IF EXISTS {$prefix}{$table}"); }
}
echo "Zero Galaxy recycler sender: {$assertions} assertions passed; native dispatch helpers and isolated SQL tables.\n";
