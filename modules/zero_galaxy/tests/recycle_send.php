<?php

require __DIR__ . '/support/bootstrap.php';
require __DIR__ . '/support/database.php';
zgGameRequest('zero-galaxy-recycle-send-test');
require getcwd() . '/common.php';
require_once SN_ROOT_PHYSICAL . 'includes/includes/flt_functions.php';

use DBAL\db_mysql;
use Planet\DBStaticPlanet;
use Unit\DBStaticUnit;
use ZeroGalaxy\AjaxFleetSender;
use ZeroGalaxy\RiftGeometry;
use ZeroGalaxy\Settings;
use ZeroGalaxy\Storage;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$tables = new ZgTables(SN::$db->link, 'recycle_send');
$db = $tables->db;
$prefix = $tables->prefix;
$original = [SN::$db, SN::$sys_user_logged_in, $user, $planetrow];

try {
  $tables->cloneGame(['users', 'planets', 'unit', 'fleets', 'que'], SN::$db->db_prefix);
  $tables->useInGame();
  $settings = Settings::load(['target_active_per_system' => 0]);
  $storage = new Storage($tables->sql());
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
  $ownerId = (int) $tables->scalar("SELECT owner_id FROM {$prefix}zero_galaxy_npcs WHERE category_key = 'tiny'");
  $unstable = SN_TIME_NOW + 10000; $closes = SN_TIME_NOW + 20000;
  $snapshot = $db->real_escape_string(zgSnapshot());
  $db->query("INSERT INTO {$prefix}zero_galaxy_rifts
    (system,position,born_at,opens_at,unstable_at,closes_at,disappears_at,image,config_snapshot,npc_owner_id)
    VALUES (1,1," . (SN_TIME_NOW - 10) . ',' . (SN_TIME_NOW - 9) . ",{$unstable},{$closes}," . (SN_TIME_NOW + 30000)
    . ",'normaltempplanet01','{$snapshot}',{$ownerId})");
  $riftId = (int) $db->insert_id;
  $storage->tick(SN_TIME_NOW, $settings, 1, 1, true);
  $targetId = (int) $tables->scalar("SELECT planet_id FROM {$prefix}zero_galaxy_rifts WHERE rift_id={$riftId}");
  $db->query("UPDATE {$prefix}planets SET debris_metal=30000,debris_crystal=10000 WHERE id={$targetId}");

  $refresh = function () use ($pilot, $sourceId) {
    global $user, $planetrow;
    DBStaticUnit::cache_clear();
    $user = db_user_by_id($pilot, true);
    $planetrow = DBStaticPlanet::db_planet_by_id($sourceId, true);
    SN::$sys_user_logged_in = true;
  };
  $state = function () use ($tables, $prefix): array {
    return array_map(function ($table) use ($tables, $prefix) {
      return $tables->rows("SELECT * FROM {$prefix}{$table}");
    }, ['users', 'planets', 'unit', 'fleets', 'zero_galaxy_rifts']);
  };
  $post = function (array $overrides = []) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_POST = array_replace(['galaxy' => '0', 'system' => '1', 'planet' => '1',
      'planet_type' => (string) PT_DEBRIS, 'mission' => (string) MT_RECYCLE], $overrides);
  };
  $resetLaunch = function () use ($db, $prefix, $sourceId, $refresh) {
    $db->query("DELETE FROM {$prefix}fleets");
    $db->query("UPDATE {$prefix}unit SET unit_level=50 WHERE unit_location_id={$sourceId} AND unit_snid=" . SHIP_RECYCLER);
    $db->query("UPDATE {$prefix}planets SET deuterium=1000000000 WHERE id={$sourceId}");
    $refresh();
  };
  $rejected = function (string $message, string $expected = null) use ($state, $check, $tables) {
    $before = $state();
    $response = AjaxFleetSender::send();
    $check(strpos($response, '|flt_recyclers=') === false && ($expected === null || $response === $expected), $message . ': correct rejection');
    $check($state() === $before, $message . ': ships, fuel, target and flights untouched');
    $check(!db_mysql::$db_in_transaction && (int) $tables->scalar('SELECT @@in_transaction') === 0, $message . ': no leaked transaction');
  };

  foreach ([['galaxy' => '1'],['system' => '0'],['planet' => '0'],['planet_type' => PT_PLANET],
    ['mission' => MT_ATTACK],['system' => '1.5']] as $invalid) {
    $refresh(); $post($invalid);
    $rejected('Malformed quick-recycle request', isset($invalid['mission']) ? null : $lang['zero_galaxy_recycle_bad_request']);
  }
  $post(); $_SERVER['REQUEST_METHOD']='GET'; $rejected('Non-POST request');
  $post(); unset($_SERVER['HTTP_X_REQUESTED_WITH']); $rejected('Non-AJAX request');
  $post(); SN::$sys_user_logged_in=false; $rejected('Unauthenticated caller');
  $resetLaunch(); $post();
  $db->query("UPDATE {$prefix}zero_galaxy_rifts SET unstable_at=" . (SN_TIME_NOW-1)
    . ',closes_at=' . SN_TIME_NOW . " WHERE rift_id={$riftId}");
  $check(strpos(AjaxFleetSender::send(), '|flt_recyclers=') !== false, 'A phase deadline must not gate launch toward an existing debris field');
  $db->query("UPDATE {$prefix}zero_galaxy_rifts SET unstable_at={$unstable},closes_at={$closes} WHERE rift_id={$riftId}");
  $resetLaunch();
  $db->query("UPDATE {$prefix}planets SET debris_metal=0,debris_crystal=0 WHERE id={$targetId}");
  $refresh(); $post(); $rejected('Empty debris field', $lang['fl_attack_error'][ATTACK_NO_DEBRIS]);
  $resetLaunch(); $post(['system' => '2', 'planet' => '2']);
  $rejected('Quick recycling requires an existing target planet', $lang['zero_galaxy_recycle_missing']);
  $db->query("UPDATE {$prefix}planets SET debris_metal=30000,debris_crystal=10000 WHERE id={$targetId}");
  $resetLaunch();
  $db->query("UPDATE {$prefix}unit SET unit_level=0 WHERE unit_location_id={$sourceId} AND unit_snid=" . SHIP_RECYCLER);
  $refresh(); $post(); $rejected('No available recyclers', $lang['fl_attack_error'][ATTACK_NO_RECYCLERS]);
  $db->query("UPDATE {$prefix}unit SET unit_level=50 WHERE unit_location_id={$sourceId} AND unit_snid=" . SHIP_RECYCLER);
  $refresh(); $post(); db_mysql::db_transaction_start();
  $response = AjaxFleetSender::send();
  $check(strpos($response, '|') === false && db_mysql::$db_in_transaction
    && (int) $tables->scalar('SELECT @@in_transaction') === 1, 'Reject nested transaction without taking ownership');
  db_mysql::db_transaction_rollback();

  $refresh(); $post();
  $db->query("CREATE TRIGGER {$prefix}reject_unit BEFORE UPDATE ON {$prefix}unit FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Recycler sender rollback test'");
  ob_start();
  try { $rejected('Native unit debit failure after fleet insert and fuel debit', $lang['zero_galaxy_recycle_failed']); }
  finally { restore_error_handler(); ob_end_clean(); $db->query("DROP TRIGGER {$prefix}reject_unit"); }

  $refresh(); $post();
  $sourceBefore = $tables->one("SELECT * FROM {$prefix}planets WHERE id={$sourceId}");
  $targetBefore = $tables->one("SELECT * FROM {$prefix}planets WHERE id={$targetId}");
  $riftBefore = $tables->one("SELECT * FROM {$prefix}zero_galaxy_rifts WHERE rift_id={$riftId}");
  $from = RiftGeometry::source($planetrow);
  $selection = flt_calculate_fleet_to_transport([SHIP_RECYCLER => 50], 40000, $from, $targetBefore);
  $expectedFleet = array_map('intval',$selection['fleet']);
  $travel = flt_travel_data($user,$planetrow,$targetBefore,$expectedFleet,10,[],Universe::getBaseGalaxyDistance());
  $response = AjaxFleetSender::send();
  $sent = (int) $expectedFleet[SHIP_RECYCLER];
  $check(strpos($response, '|flt_recyclers=' . (50-$sent)) !== false, 'Send native minimum fleet and return precise remaining recycler count');
  $fleet = $tables->one("SELECT * FROM {$prefix}fleets ORDER BY fleet_id DESC LIMIT 1");
  $check(sys_unit_str2arr($fleet['fleet_array']) === $expectedFleet && (int) $fleet['fleet_mission'] === MT_RECYCLE
    && (int) $fleet['fleet_end_type'] === PT_DEBRIS, 'Persist ordinary native recycle mission to debris, no fighters or captain');
  $check((int) $fleet['fleet_end_planet_id'] === $targetId && (int) $fleet['fleet_target_owner'] === $ownerId,
    'Fleet retains native destination planet and owner for lifecycle cleanup');
  $check((int) $fleet['fleet_start_galaxy'] === 5 && (int) $fleet['fleet_start_system'] === 2
    && (int) $fleet['fleet_start_planet'] === 8, 'Temporary distance normalization must not alter actual origin');
  $check((int) $fleet['fleet_start_time'] === SN_TIME_NOW + (int) $travel['duration']
    && (int) $fleet['fleet_end_time'] === SN_TIME_NOW + 2*(int) $travel['duration'], 'Both native times use exactly one intergalactic distance');
  $check((int) $tables->scalar("SELECT unit_level FROM {$prefix}unit WHERE unit_location_id={$sourceId} AND unit_snid=" . SHIP_RECYCLER) === 50-$sent,
    'Exactly selected recyclers leave source');
  $check((int) $tables->scalar("SELECT unit_level FROM {$prefix}unit WHERE unit_location_id={$sourceId} AND unit_snid=" . SHIP_SMALL_FIGHTER_LIGHT) === 17,
    'Quick recycling never takes combat ships');
  $check((float) $sourceBefore['deuterium'] - (float) $tables->scalar("SELECT deuterium FROM {$prefix}planets WHERE id={$sourceId}") === (float) $travel['consumption'],
    'Exact native one-distance fuel is debited once');
  $check($targetBefore === $tables->one("SELECT * FROM {$prefix}planets WHERE id={$targetId}")
    && $riftBefore === $tables->one("SELECT * FROM {$prefix}zero_galaxy_rifts WHERE rift_id={$riftId}"),
    'Sending does not collect debris, reserve it, change phase or extend deadlines');

  $db->query("DELETE FROM {$prefix}fleets");
  $db->query("UPDATE {$prefix}unit SET unit_level=1 WHERE unit_location_id={$sourceId} AND unit_snid=" . SHIP_RECYCLER);
  $refresh(); $post();
  $check(strpos(AjaxFleetSender::send(),'|flt_recyclers=0') !== false, 'Insufficient recycler inventory sends an available partial collection fleet');
  $db->query("DELETE FROM {$prefix}fleets");
  $db->query("UPDATE {$prefix}unit SET unit_level=50 WHERE unit_location_id={$sourceId} AND unit_snid=" . SHIP_RECYCLER);
  $refresh(); $post();
  $single = flt_travel_data($user,$from,$targetBefore,[SHIP_RECYCLER=>1],10);
  $fuel = 2*(int)$single['consumption'];
  $db->query("UPDATE {$prefix}planets SET deuterium={$fuel} WHERE id={$sourceId}");
  $db->query("UPDATE {$prefix}planets SET debris_metal=1000000000000000,debris_crystal=2000000000000000 WHERE id={$targetId}");
  $refresh(); $post();
  $check(strpos(AjaxFleetSender::send(),'|flt_recyclers=48') !== false, 'Native selection reduces fleet to available fuel even for enormous debris fields');
  $check((float)$tables->scalar("SELECT deuterium FROM {$prefix}planets WHERE id={$sourceId}") >= 0, 'Partial launch cannot overdraw fuel');

  $db->query("DELETE FROM {$prefix}fleets");
  $db->query("UPDATE {$prefix}unit SET unit_level=50 WHERE unit_location_id={$sourceId} AND unit_snid=" . SHIP_RECYCLER);
  $db->query("UPDATE {$prefix}planets SET deuterium=1000000000 WHERE id={$sourceId}");
  $refresh(); $post();
  $fault = new ZgRefreshFailure($original[0], $sourceId);
  SN::$db = $fault;
  try { $response = AjaxFleetSender::send(); } finally { SN::$db = $original[0]; }
  $check($fault->failures===1 && strpos($response,'|flt_recyclers=0')!==false
    && (int)$tables->scalar("SELECT COUNT(*) FROM {$prefix}fleets")===1, 'Committed launch survives a failed display refresh without reporting a failed send');
  $check(!db_mysql::$db_in_transaction && (int)$tables->scalar('SELECT @@in_transaction')===0, 'Successful sends leave no open transaction');
  echo "Zero Galaxy recycler sender: {$assertions} assertions passed; native dispatch helpers and isolated SQL tables.\n";
} finally {
  SN::$db = $original[0];
  $tables->restoreGame();
  [, SN::$sys_user_logged_in, $user, $planetrow] = $original;
  $tables->drop();
}
