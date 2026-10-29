<?php

if (PHP_SAPI !== 'cli' || getenv('SUPERNOVA_DB_HOST') !== 'db'
  || getenv('SUPERNOVA_DB_NAME') !== 'supernova' || !is_file('/var/lib/supernova/cookie-secret')) {
  exit("Attack sender tests require the local SuperNova Docker environment.\n");
}

chdir(dirname(__DIR__, 3));
$_SERVER = array_replace($_SERVER, [
  'SCRIPT_NAME' => '/zero-galaxy-attack-send-test.php',
  'SCRIPT_FILENAME' => getcwd() . '/zero-galaxy-attack-send-test.php',
  'HTTP_HOST' => 'localhost:8080', 'SERVER_NAME' => 'localhost',
  'REMOTE_ADDR' => '127.0.0.1', 'REQUEST_METHOD' => 'GET',
  'REQUEST_URI' => '/zero-galaxy-attack-send-test.php', 'HTTP_USER_AGENT' => 'ZeroGalaxy attack sender test',
]);
$_GET = $_POST = $_COOKIE = $_REQUEST = [];
define('IN_AJAX', true);
$allow_anonymous = true;
require getcwd() . '/common.php';
foreach (['Settings', 'Profile', 'Lifecycle', 'Storage', 'AttackPolicy', 'AttackSender'] as $class) {
  require_once dirname(__DIR__) . '/classes/ZeroGalaxy/' . $class . '.php';
}
require_once SN_ROOT_PHYSICAL . 'includes/includes/flt_functions.php';

use DBAL\db_mysql;
use Planet\DBStaticPlanet;
use Unit\DBStaticUnit;
use ZeroGalaxy\AttackPolicy;
use ZeroGalaxy\AttackSender;
use ZeroGalaxy\Profile;
use ZeroGalaxy\Settings;
use ZeroGalaxy\Storage;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = SN::$db->link;
$originalPrefix = SN::$db->db_prefix;
$originalLoggedIn = SN::$sys_user_logged_in;
$originalUser = $user;
$originalPlanet = $planetrow;
$prefix = 'zg_send_' . bin2hex(random_bytes(5)) . '_';
$tables = ['zero_galaxy_npcs', 'zero_galaxy_rifts', 'zero_galaxy_state', 'captain', 'bashing', 'fleets', 'unit', 'planets', 'users'];
$assertions = 0;
$check = function ($condition, $message) use (&$assertions) {
  $assertions++;
  if (!$condition) { throw new RuntimeException($message); }
};
$one = function (string $sql) use ($db): ?array { return $db->query($sql)->fetch_assoc(); };
$scalar = function (string $sql) use ($db) { return $db->query($sql)->fetch_row()[0]; };
$rows = function (string $sql) use ($db): array { return $db->query($sql)->fetch_all(MYSQLI_ASSOC); };
$expect = function (callable $callable, string $class, string $message, string $exactMessage = null) use ($check) {
  try { $callable(); }
  catch (Throwable $error) {
    if (!$error instanceof $class) { throw $error; }
    $check($exactMessage === null || $error->getMessage() === $exactMessage, $message . ': wrong error message');
    return;
  }
  $check(false, $message);
};

try {
  if (!preg_match('/^[a-zA-Z0-9_]*$/D', $originalPrefix)) { throw new RuntimeException('Invalid source prefix'); }
  foreach (['users', 'planets', 'unit', 'fleets', 'bashing', 'captain'] as $table) {
    $db->query("CREATE TABLE {$prefix}{$table} LIKE {$originalPrefix}{$table}");
  }
  // Every native {{table}} query now targets fresh isolated tables. Unit and
  // schema caches are cleared before use and restored after the test.
  SN::$db->db_prefix = $prefix;
  SN::$db->schema()->clear();
  DBStaticUnit::cache_clear();
  $settings = Settings::load(['target_active_per_system' => 0]);
  $storage = new Storage($db, $prefix);
  $storage->tick(SN_TIME_NOW, $settings, 1, 1, true);

  $db->query("INSERT INTO {$prefix}users (username, password, galaxy, total_points, user_lastip, onlinetime)
    VALUES ('Isolated attack pilot', '!disabled-test!', 3, 1000000, '127.0.0.2', " . SN_TIME_NOW . ')');
  $actorId = (int) $db->insert_id;
  $db->query("INSERT INTO {$prefix}users (username, password, galaxy, user_lastip)
    VALUES ('Untouched isolated pilot', '!disabled-other!', 2, '127.0.0.3')");
  $otherId = (int) $db->insert_id;
  foreach ([[$actorId, 'Signed source', 3, 1000000000], [$actorId, 'Other selected source', 4, 1000000000],
    [$otherId, 'Foreign source', 2, 12345]] as $planetSpec) {
    list($owner, $name, $galaxy, $fuel) = $planetSpec;
    $db->query("INSERT INTO {$prefix}planets (name, id_owner, galaxy, system, planet, planet_type, deuterium)
      VALUES ('{$name}', {$owner}, {$galaxy}, 1, 8, 1, {$fuel})");
    $sources[] = (int) $db->insert_id;
  }
  list($sourceId, $alternateId, $foreignId) = $sources;
  $db->query("UPDATE {$prefix}users SET id_planet = {$sourceId}, current_planet = {$sourceId} WHERE id = {$actorId}");
  foreach ([TECH_COMPUTER => 20, TECH_ENGINE_CHEMICAL => 8] as $id => $level) {
    $db->query("INSERT INTO {$prefix}unit (unit_player_id, unit_location_type, unit_location_id, unit_type, unit_snid, unit_level)
      VALUES ({$actorId}, 4, {$actorId}, 100, {$id}, {$level})");
  }
  foreach ([SHIP_SMALL_FIGHTER_LIGHT, SHIP_SPY] as $id) {
    $db->query("INSERT INTO {$prefix}unit (unit_player_id, unit_location_type, unit_location_id, unit_type, unit_snid, unit_level)
      VALUES ({$actorId}, 1, {$sourceId}, 200, {$id}, 50)");
  }
  $shipUnit = (int) $scalar("SELECT unit_id FROM {$prefix}unit WHERE unit_location_type = 1
    AND unit_location_id = {$sourceId} AND unit_snid = " . SHIP_SMALL_FIGHTER_LIGHT);
  $rates = [901 => (float) SN::$config->rpg_exchange_metal,
    902 => SN::$config->rpg_exchange_crystal / SN::$config->rpg_exchange_metal,
    903 => SN::$config->rpg_exchange_deuterium / SN::$config->rpg_exchange_metal];
  $profile = Profile::create('tiny', $settings['categories']['tiny'], 1.25, $rates);
  // An exact native ten-fighter cap isolates the > versus >= admission boundary.
  $profile['fleet_limit'] = AttackPolicy::cost([SHIP_SMALL_FIGHTER_LIGHT => 10]);
  $snapshot = $db->real_escape_string(json_encode(['profile' => $profile], JSON_THROW_ON_ERROR));
  $born = SN_TIME_NOW - 10; $opens = SN_TIME_NOW - 9;
  $unstable = SN_TIME_NOW + 10000; $closes = SN_TIME_NOW + 20000; $disappears = SN_TIME_NOW + 30000;
  $db->query("INSERT INTO {$prefix}zero_galaxy_rifts
    (system, position, born_at, opens_at, unstable_at, closes_at, disappears_at, image, config_snapshot)
    VALUES (1, 1, {$born}, {$opens}, {$unstable}, {$closes}, {$disappears}, 'normaltempplanet01', '{$snapshot}')");
  $riftId = (int) $db->insert_id;
  $storage->tick(SN_TIME_NOW, $settings, 1, 1, true);
  $rift = $one("SELECT * FROM {$prefix}zero_galaxy_rifts WHERE rift_id = {$riftId}");
  $targetId = (int) $rift['planet_id'];
  $npcId = (int) $rift['npc_owner_id'];
  $check($targetId > 0 && $npcId > 0, 'The target must be a genuinely materialized native populated rift.');
  $foreignBefore = $one("SELECT * FROM {$prefix}users WHERE id = {$otherId}");
  $foreignPlanetBefore = $one("SELECT * FROM {$prefix}planets WHERE id = {$foreignId}");

  $refresh = function () use ($actorId, $sourceId) {
    global $user, $planetrow;
    DBStaticUnit::cache_clear();
    $user = db_user_by_id($actorId, true);
    $planetrow = DBStaticPlanet::db_planet_by_id($sourceId, true);
    SN::$sys_user_logged_in = true;
  };
  $state = function () use ($rows, $prefix): array {
    return [$rows("SELECT * FROM {$prefix}users ORDER BY id"), $rows("SELECT * FROM {$prefix}planets ORDER BY id"),
      $rows("SELECT * FROM {$prefix}unit ORDER BY unit_id"), $rows("SELECT * FROM {$prefix}fleets ORDER BY fleet_id"),
      $rows("SELECT * FROM {$prefix}captain ORDER BY captain_id")];
  };
  $rejected = function (callable $callable, string $message, string $exact = null, string $class = InvalidArgumentException::class)
    use ($state, $expect, $check, $refresh, $scalar) {
    $refresh(); $before = $state();
    $expect($callable, $class, $message, $exact);
    $check($state() === $before, $message . ': rejected send must not change ships, fuel, captains, users or fleets');
    $check(!db_mysql::$db_in_transaction && (int) $scalar('SELECT @@in_transaction') === 0, $message . ': own transaction must be closed');
  };
  $send = function (array $ships, int $speed = 10, int $captain = 0, int $source = 0, array $targetRift = null)
    use ($storage, $settings, $rift) {
    return AttackSender::send($storage, $settings, $targetRift ?? $rift, $ships, $speed, $captain, $source);
  };

  $rejected(function () use ($send) { $send([SHIP_SMALL_FIGHTER_LIGHT => 11]); }, 'Strictly over-limit fleet', AttackPolicy::OVER_LIMIT);
  $rejected(function () use ($send) { $send([]); }, 'Empty fleet');
  $rejected(function () use ($send) { $send([SHIP_SMALL_FIGHTER_LIGHT => 1.5]); }, 'Fractional ships');
  $rejected(function () use ($send) { $send([999999 => 1]); }, 'Unknown ship type');
  foreach ([0, 11] as $speed) {
    $rejected(function () use ($send, $speed) { $send([SHIP_SMALL_FIGHTER_LIGHT => 1], $speed); }, 'Invalid speed');
  }
  $rejected(function () use ($send, $foreignId) { $send([SHIP_SMALL_FIGHTER_LIGHT => 1], 10, 0, $foreignId); }, 'Another player source planet');
  $rejected(function () use ($send, $riftId) { $send([SHIP_SMALL_FIGHTER_LIGHT => 1], 10, 0, 0, ['rift_id' => $riftId + 999]); }, 'Expired or replaced target generation');
  foreach (['opens_at' => SN_TIME_NOW + 1, 'closes_at' => SN_TIME_NOW] as $field => $value) {
    $old = (int) $rift[$field];
    if ($field === 'closes_at') {
      $db->query("UPDATE {$prefix}zero_galaxy_rifts SET unstable_at = " . (SN_TIME_NOW - 1) . " WHERE rift_id = {$riftId}");
    }
    $db->query("UPDATE {$prefix}zero_galaxy_rifts SET {$field} = {$value} WHERE rift_id = {$riftId}");
    $rejected(function () use ($send) { $send([SHIP_SMALL_FIGHTER_LIGHT => 1]); }, 'Unavailable target phase ' . $field);
    $db->query("UPDATE {$prefix}zero_galaxy_rifts SET {$field} = {$old} WHERE rift_id = {$riftId}");
    if ($field === 'closes_at') {
      $db->query("UPDATE {$prefix}zero_galaxy_rifts SET unstable_at = {$unstable} WHERE rift_id = {$riftId}");
    }
  }
  $db->query("UPDATE {$prefix}planets SET id_owner = {$otherId} WHERE id = {$targetId}");
  $rejected(function () use ($send) { $send([SHIP_SMALL_FIGHTER_LIGHT => 1]); }, 'Changed target ownership');
  $db->query("UPDATE {$prefix}planets SET id_owner = {$npcId} WHERE id = {$targetId}");
  $db->query("UPDATE {$prefix}users SET authlevel = 1 WHERE id = {$npcId}");
  $rejected(function () use ($send) { $send([SHIP_SMALL_FIGHTER_LIGHT => 1]); }, 'Invalid NPC privilege marker');
  $db->query("UPDATE {$prefix}users SET authlevel = 0 WHERE id = {$npcId}");
  foreach (['parent_account_id', 'parent_account_global'] as $accountField) {
    $db->query("UPDATE {$prefix}users SET {$accountField} = 12345 WHERE id = {$npcId}");
    $rejected(function () use ($send) { $send([SHIP_SMALL_FIGHTER_LIGHT => 1]); }, 'NPC linked to an account through ' . $accountField);
    $db->query("UPDATE {$prefix}users SET {$accountField} = 0 WHERE id = {$npcId}");
  }
  $db->query("UPDATE {$prefix}unit SET unit_level = 1 WHERE unit_id = {$shipUnit}");
  $rejected(function () use ($send) { $send([SHIP_SMALL_FIGHTER_LIGHT => 2]); }, 'Not enough owned ships');
  $db->query("UPDATE {$prefix}unit SET unit_level = 50 WHERE unit_id = {$shipUnit}");
  $db->query("UPDATE {$prefix}planets SET deuterium = 0 WHERE id = {$sourceId}");
  $rejected(function () use ($send) { $send([SHIP_SMALL_FIGHTER_LIGHT => 1]); }, 'Not enough native fuel');
  $db->query("UPDATE {$prefix}planets SET deuterium = 1000000000 WHERE id = {$sourceId}");
  $rejected(function () use ($send) { $send([SHIP_SPY => 1]); }, 'Lone probes cannot use the attack mission');
  $rejected(function () use ($send) { $send([SHIP_SMALL_FIGHTER_LIGHT => 1], 10, 999999); }, 'Unavailable captain');
  $refresh(); $before = $state(); SN::$sys_user_logged_in = false;
  $expect(function () use ($send) { $send([SHIP_SMALL_FIGHTER_LIGHT => 1]); }, InvalidArgumentException::class, 'Unauthenticated caller');
  $check($state() === $before, 'Unauthenticated send must leave all fixture data untouched.');

  $refresh();
  db_mysql::db_transaction_start();
  $expect(function () use ($send) { $send([SHIP_SMALL_FIGHTER_LIGHT => 1]); }, RuntimeException::class, 'Foreign transaction context');
  $check(db_mysql::$db_in_transaction && (int) $scalar('SELECT @@in_transaction') === 1,
    'Rejecting a nested send must retain caller transaction control.');
  db_mysql::db_transaction_rollback();

  // Fail only after fleet insert and fuel debit, when the actual native unit
  // changeset runs. Both earlier writes must roll back with that SQL failure.
  $db->query("CREATE TRIGGER {$prefix}reject_unit BEFORE UPDATE ON {$prefix}unit FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attack sender rollback test'");
  ob_start();
  try {
    $rejected(function () use ($send) { $send([SHIP_SMALL_FIGHTER_LIGHT => 1]); },
      'Database failure after fleet insert and fuel deduction', null, RuntimeException::class);
  } finally {
    // Native doquery() restores its warning handler only on success. The
    // intentional mysqli exception leaves that one handler frame installed.
    restore_error_handler();
    ob_end_clean();
  }
  $db->query("DROP TRIGGER {$prefix}reject_unit");

  $captainModule = moduleCaptain();
  $check(is_object($captainModule), 'This integration environment must load the native captain module.');
  $db->query("INSERT INTO {$prefix}unit (unit_player_id, unit_location_type, unit_location_id, unit_type, unit_snid, unit_level)
    VALUES ({$actorId}, 1, {$sourceId}, " . UNIT_CAPTAIN . ', ' . UNIT_CAPTAIN . ', 1)');
  $captainId = (int) $db->insert_id;
  $db->query("INSERT INTO {$prefix}captain (captain_unit_id, captain_level, captain_attack, captain_shield, captain_armor)
    VALUES ({$captainId}, 3, 1, 1, 1)");
  $refresh();
  $sourceBefore = $one("SELECT * FROM {$prefix}planets WHERE id = {$sourceId}");
  $targetBefore = $one("SELECT * FROM {$prefix}planets WHERE id = {$targetId}");
  $result = $send([SHIP_SMALL_FIGHTER_LIGHT => 10]);
  $fleet = $one("SELECT * FROM {$prefix}fleets WHERE fleet_id = " . $result['fleet_id']);
  $check($result['fleet_id'] > 0 && $result['fleet'] === [SHIP_SMALL_FIGHTER_LIGHT => 10], 'A fleet exactly equal to the saved limit must launch successfully.');
  $check((int) $fleet['fleet_mission'] === MT_ATTACK && (int) $fleet['fleet_group'] === 0
    && sys_unit_str2arr($fleet['fleet_array']) === [SHIP_SMALL_FIGHTER_LIGHT => 10], 'A normal native solo attack fleet must persist its exact ship composition.');
  $check((int) $fleet['fleet_start_planet_id'] === $sourceId && (int) $fleet['fleet_start_galaxy'] === 3
    && (int) $fleet['fleet_end_planet_id'] === $targetId && (int) $fleet['fleet_end_galaxy'] === 0
    && (int) $fleet['fleet_target_owner'] === $npcId, 'Native fleet records must preserve actual source and zero-galaxy target identities.');
  $check((int) $result['travel']['distance'] === (int) Universe::getBaseGalaxyDistance(), 'Every ordinary source galaxy must use the same one-galaxy distance.');
  $check((int) $fleet['fleet_start_time'] === $result['arrival'] && $result['arrival'] === SN_TIME_NOW + (int) $result['travel']['duration']
    && (int) $fleet['fleet_end_time'] === $result['return_at'] && $result['return_at'] === SN_TIME_NOW + 2 * (int) $result['travel']['duration'],
    'Native arrival and return timestamps must match server-calculated travel.');
  $check((int) $scalar("SELECT unit_level FROM {$prefix}unit WHERE unit_id = {$shipUnit}") === 40,
    'Successful launch must subtract the exact native ship count once.');
  $check((int) $scalar("SELECT deuterium FROM {$prefix}planets WHERE id = {$sourceId}") === (int) $sourceBefore['deuterium'] - (int) $result['travel']['consumption'],
    'Successful launch must debit exactly the server-calculated fuel once.');
  $check($one("SELECT * FROM {$prefix}planets WHERE id = {$targetId}") === $targetBefore,
    'Launching an attack must not change the NPC garrison, loot or phase.');
  $check((float) $fleet['fleet_resource_metal'] === 0.0 && (float) $fleet['fleet_resource_crystal'] === 0.0
    && (float) $fleet['fleet_resource_deuterium'] === 0.0, 'An attack must not smuggle client resource cargo.');
  $captain = $one("SELECT * FROM {$prefix}unit WHERE unit_id = {$captainId}");
  $check((int) $captain['unit_location_type'] === LOC_PLANET && (int) $captain['unit_location_id'] === $sourceId,
    'A captain present at the source must stay there when the player did not select it.');

  $computer = (int) $scalar("SELECT unit_id FROM {$prefix}unit WHERE unit_player_id = {$actorId} AND unit_snid = " . TECH_COMPUTER);
  $db->query("UPDATE {$prefix}unit SET unit_level = 0 WHERE unit_id = {$computer}");
  $rejected(function () use ($send) { $send([SHIP_SMALL_FIGHTER_LIGHT => 1]); }, 'Native fleet slots exhausted');
  $db->query("UPDATE {$prefix}unit SET unit_level = 20 WHERE unit_id = {$computer}");

  $db->query("UPDATE {$prefix}users SET current_planet = {$alternateId} WHERE id = {$actorId}");
  $refresh();
  $alternateBefore = $one("SELECT * FROM {$prefix}planets WHERE id = {$alternateId}");
  $withCaptain = $send([SHIP_SMALL_FIGHTER_LIGHT => 2], 7, $captainId, $sourceId);
  $captain = $one("SELECT * FROM {$prefix}unit WHERE unit_id = {$captainId}");
  $check((int) $captain['unit_location_type'] === LOC_FLEET && (int) $captain['unit_location_id'] === $withCaptain['fleet_id'],
    'The selected native captain must be associated with that exact outgoing fleet.');
  $check((int) $withCaptain['source']['id'] === $sourceId
    && $one("SELECT * FROM {$prefix}planets WHERE id = {$alternateId}") === $alternateBefore,
    'An explicitly signed source must remain authoritative after the player selects another current planet.');
  $check((int) $scalar("SELECT current_planet FROM {$prefix}users WHERE id = {$actorId}") === $alternateId,
    'Sending from a signed source must not change the player selected-planet preference.');
  $rejected(function () use ($send, $captainId, $sourceId) { $send([SHIP_SMALL_FIGHTER_LIGHT => 1], 10, $captainId, $sourceId); },
    'A captain already flying cannot be attached to another fleet');
  $check($one("SELECT * FROM {$prefix}users WHERE id = {$otherId}") === $foreignBefore
    && $one("SELECT * FROM {$prefix}planets WHERE id = {$foreignId}") === $foreignPlanetBefore,
    'Rejected and successful sends must preserve unrelated human users and planets.');
  echo "Attack sender: {$assertions} assertions passed; native helpers used and isolated tables cleaned.\n";
} finally {
  if (db_mysql::$db_in_transaction) { db_mysql::db_transaction_rollback(); }
  elseif ((int) $scalar('SELECT @@in_transaction') !== 0) { $db->rollback(); }
  SN::$db->db_prefix = $originalPrefix;
  SN::$db->schema()->clear();
  DBStaticUnit::cache_clear();
  SN::$sys_user_logged_in = $originalLoggedIn;
  $user = $originalUser; $planetrow = $originalPlanet;
  $db->query('SET FOREIGN_KEY_CHECKS = 0');
  foreach ($tables as $table) { $db->query("DROP TABLE IF EXISTS {$prefix}{$table}"); }
  $db->query('SET FOREIGN_KEY_CHECKS = 1');
}
