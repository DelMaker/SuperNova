<?php

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Lifecycle.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Storage.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Profile.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/RiftName.php';

use ZeroGalaxy\Lifecycle;
use ZeroGalaxy\Storage;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function storageTestDb(): mysqli {
  $db = new mysqli(
    getenv('SUPERNOVA_DB_HOST') ?: 'db',
    getenv('SUPERNOVA_DB_USER') ?: 'supernova',
    getenv('SUPERNOVA_DB_PASSWORD') ?: 'supernova-local',
    getenv('SUPERNOVA_DB_NAME') ?: 'supernova'
  );
  $db->set_charset('utf8');
  return $db;
}

function storageTestSettings(): array {
  return [
    'target_active_per_system' => 1,
    'tick_seconds' => 15,
    'generation_seconds' => 100,
    'energy_seconds' => [2, 2],
    'active_seconds' => [3, 3],
    'unstable_seconds' => [4, 4],
    'closed_seconds' => [2, 2],
    'refresh_seconds' => 2,
  ];
}

if (($argv[1] ?? '') === '--worker') {
  $prefix = $argv[2] ?? '';
  if (!preg_match('/^zg_test_[a-f0-9]{10}_$/D', $prefix)) {
    throw new RuntimeException('Worker requires an isolated test prefix');
  }
  $worker = new Storage(storageTestDb(), $prefix);
  $worker->tick((int) $argv[3], storageTestSettings(), 1, 1);
  exit;
}

$db = storageTestDb();
$prefix = 'zg_test_' . bin2hex(random_bytes(5)) . '_';
$tables = ['zero_galaxy_rifts', 'zero_galaxy_state', 'zero_galaxy_npcs', 'fleets', 'unit', 'planets', 'users'];
$assertions = 0;
$check = function ($condition, $message) use (&$assertions) {
  $assertions++;
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$one = function ($sql) use ($db) {
  return $db->query($sql)->fetch_assoc();
};
$scalar = function ($sql) use ($db) {
  return $db->query($sql)->fetch_row()[0];
};
$expectFailure = function ($callable, $message) use ($check) {
  try {
    $callable();
  } catch (RuntimeException $error) {
    $check(true, $message);
    return;
  }
  $check(false, $message);
};

try {
  // CREATE LIKE clones native fields/indexes, without touching native data.
  foreach (['users', 'planets', 'unit', 'fleets'] as $table) {
    $db->query("CREATE TABLE {$prefix}{$table} LIKE sn_{$table}");
  }
  $db->query("ALTER TABLE {$prefix}fleets ADD CONSTRAINT {$prefix}target
    FOREIGN KEY (fleet_end_planet_id) REFERENCES {$prefix}planets (id) ON DELETE SET NULL");

  $now = 1700000000;
  $settings = storageTestSettings();
  $snapshot = $db->real_escape_string(json_encode($settings));
  // Install over the earlier module-only schema and retain the existing cycle.
  $db->query("CREATE TABLE {$prefix}zero_galaxy_rifts (
    rift_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    system INT UNSIGNED NOT NULL, position INT UNSIGNED NOT NULL,
    born_at BIGINT UNSIGNED NOT NULL, opens_at BIGINT UNSIGNED NOT NULL,
    unstable_at BIGINT UNSIGNED NOT NULL, closes_at BIGINT UNSIGNED NOT NULL,
    disappears_at BIGINT UNSIGNED NOT NULL, image VARCHAR(64) NOT NULL,
    config_snapshot TEXT NOT NULL,
    PRIMARY KEY (rift_id), UNIQUE KEY coordinates (system, position)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
  $db->query("CREATE TABLE {$prefix}zero_galaxy_state (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    next_tick_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
    next_generation_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
    seeded_systems INT UNSIGNED NOT NULL DEFAULT 0
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
  $db->query("INSERT INTO {$prefix}zero_galaxy_state VALUES (1, 0, " . ($now + 100) . ", 1)");
  $db->query("INSERT INTO {$prefix}zero_galaxy_rifts
    (system, position, born_at, opens_at, unstable_at, closes_at, disappears_at, image, config_snapshot)
    VALUES (1, 1, {$now}, " . ($now + 2) . ", " . ($now + 5) . ", " . ($now + 9) . ",
      " . ($now + 11) . ", 'normaltempplanet01', '{$snapshot}')");
  $oldRift = $one("SELECT * FROM {$prefix}zero_galaxy_rifts");

  $storage = new Storage($db, $prefix);
  $storage->install();
  $storage->install();
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_state") === 1, 'Installation must preserve one singleton');
  $migrated = $one("SELECT * FROM {$prefix}zero_galaxy_rifts");
  foreach ($oldRift as $key => $value) {
    $check($migrated[$key] === $value, 'Migration must retain field ' . $key);
  }
  $check(array_key_exists('planet_id', $migrated) && $migrated['planet_id'] === null, 'Migration must add an empty planet link');
  $check($storage->npcOwnerId() === 0, 'Schema installation must not impersonate an existing user');

  $writeCommands = function () use ($db): array {
    return $db->query("SHOW SESSION STATUS WHERE Variable_name IN
      ('Com_create_table','Com_alter_table','Com_insert','Com_update','Com_delete','Com_replace')")->fetch_all(MYSQLI_ASSOC);
  };
  $commandsBefore = $writeCommands();
  (new Storage($db, $prefix))->install();
  $check($writeCommands() === $commandsBefore,
    'A fresh request against an installed schema must not issue DDL or data writes');
  $db->query("DELETE FROM {$prefix}zero_galaxy_state WHERE id=1");
  (new Storage($db, $prefix))->install();
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_state WHERE id=1") === 1,
    'Existing tables without the singleton must still recover through installation');
  $check($one("SELECT * FROM {$prefix}zero_galaxy_rifts") === $migrated,
    'Recovering the singleton must preserve the existing rift');
  $db->query("UPDATE {$prefix}zero_galaxy_state SET next_generation_at=" . ($now + 100) . ",seeded_systems=1 WHERE id=1");

  $storage->tick($now, $settings, 1, 1);
  $npcId = $storage->npcOwnerId();
  $check($npcId > 0, 'First tick must create an NPC');
  $npc = $one("SELECT * FROM {$prefix}users WHERE id = {$npcId}");
  $check((int) $npc['authlevel'] === 0 && (int) $npc['user_bot'] === 200, 'NPC must have a distinct marker and no admin rights');
  $check(strlen($npc['password']) === 64 && $npc['salt'] === '', 'NPC must not have a usable legacy password');
  $check($npc['password'][0] === '!' && strpos($npc['username'], Storage::NPC_NAME) === 0, 'NPC must have a readable name and non-hash login sentinel');
  $check((int) $npc['parent_account_id'] === 0 && (int) $npc['parent_account_global'] === 0, 'NPC must not have an account');
  $check((int) $npc['id_planet'] === 0 && (int) $npc['current_planet'] === 0, 'NPC must not have a human capital');
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}planets") === 0, 'Energy phase must not create a physical planet');
  $check(Lifecycle::phase($storage->inSystem(1, $now)[1], $now) === 'energy', 'Energy marker must be shared in storage');
  $check($storage->findActive(1, 1, $now) === null, 'Energy marker must not be a mission target');
  $check((int) $scalar("SELECT next_tick_at FROM {$prefix}zero_galaxy_state") === $now + 2, 'Phase deadline must beat the 15 second throttle');

  $db->query("UPDATE {$prefix}users SET username = 'Zero Galaxy 0123456789abcdef', password = REPEAT('a', 64) WHERE id = {$npcId}");
  $storage->tick($now + 1, $settings, 1, 1, true);
  $upgradedNpc = $one("SELECT * FROM {$prefix}users WHERE id = {$npcId}");
  $check(strpos($upgradedNpc['username'], Storage::NPC_NAME) === 0 && $upgradedNpc['password'][0] === '!', 'Existing prototype NPC must safely receive a readable name and disabled login');
  $check($storage->npcOwnerId() === $npcId, 'NPC upgrade must preserve ownership identity');

  $storage->tick($now + 2, $settings, 1, 1);
  $active = $storage->findActive(1, 1, $now + 2);
  $check($active !== null && (int) $active['owner_id'] === $npcId, 'Opening must materialize the shared NPC target');
  $planetId = (int) $active['planet_id'];
  $planet = $one("SELECT * FROM {$prefix}planets WHERE id = {$planetId}");
  $check((int) $planet['galaxy'] === 0 && (int) $planet['system'] === 1 && (int) $planet['planet'] === 1, 'Planet must use the real zero coordinate');
  $check((int) $planet['planet_type'] === 1 && (int) $planet['id_owner'] === $npcId, 'Only an owned planet may be materialized');
  $check($planet['name'] === Storage::ACTIVE_NAME, 'Active planet must have its active name');
  foreach (['metal', 'crystal', 'deuterium', 'metal_perhour', 'crystal_perhour', 'deuterium_perhour', 'energy_max', 'energy_used'] as $field) {
    $check((float) $planet[$field] === 0.0, 'Empty planet must have zero ' . $field);
  }
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}unit") === 0, 'Stage 2 must not create ships, defenses or technologies');

  $zero = $settings;
  $zero['target_active_per_system'] = 0;
  $zero['energy_seconds'] = [1000, 1000];
  $zero['active_seconds'] = [2000, 2000];
  $storage->tick($now + 3, $zero, 1, 1, true);
  $sameRift = $storage->inSystem(1, $now + 3)[1];
  foreach (['born_at', 'opens_at', 'unstable_at', 'closes_at', 'disappears_at', 'config_snapshot'] as $field) {
    $check($sameRift[$field] === $oldRift[$field], 'Hot config must preserve existing ' . $field);
  }
  $check((int) $scalar("SELECT onlinetime FROM {$prefix}users WHERE id = {$npcId}") === $now + 3, 'Forced tick must refresh the NPC heartbeat');

  $db->query("INSERT INTO {$prefix}users (username, password, galaxy) VALUES ('Untouched human', 'unusable-test-password', 1)");
  $humanId = (int) $db->insert_id;
  $db->query("INSERT INTO {$prefix}planets (name, id_owner, galaxy, system, planet, metal)
    VALUES ('Untouched planet', {$humanId}, 1, 1, 1, 1234)");
  $humanPlanetId = (int) $db->insert_id;
  $humanBefore = $one("SELECT * FROM {$prefix}users WHERE id = {$humanId}");
  $humanPlanetBefore = $one("SELECT * FROM {$prefix}planets WHERE id = {$humanPlanetId}");
  $db->query("INSERT INTO {$prefix}unit (unit_player_id, unit_location_type, unit_location_id, unit_snid, unit_level)
    VALUES ({$humanId}, 1, {$humanPlanetId}, 201, 7), ({$npcId}, 1, {$planetId}, 201, 3)");
  $db->query("INSERT INTO {$prefix}fleets
    (fleet_owner, fleet_mission, fleet_start_planet_id, fleet_end_planet_id,
     fleet_end_galaxy, fleet_end_system, fleet_end_planet, fleet_mess, fleet_start_time, fleet_end_time)
    VALUES ({$humanId}, 6, {$humanPlanetId}, {$planetId}, 0, 1, 1, 0, " . ($now + 100) . ", " . ($now + 200) . ")");
  $fleetId = (int) $db->insert_id;
  $fleetBefore = $one("SELECT * FROM {$prefix}fleets WHERE fleet_id = {$fleetId}");

  $storage->tick($now + 5, $zero, 1, 1, true);
  $check($one("SELECT name FROM {$prefix}planets WHERE id = {$planetId}")['name'] === Storage::UNSTABLE_NAME, 'Unstable boundary must rename the existing target');
  $check((int) $storage->findActive(1, 1, $now + 5)['planet_id'] === $planetId, 'Instability must preserve the physical planet ID');
  $storage->tick($now + 9, $zero, 1, 1, true);
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}planets WHERE id = {$planetId}") === 0, 'Closed phase must delete the physical planet');
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}unit WHERE unit_player_id = {$npcId}") === 0, 'Closing must clean the NPC planet units');
  $check((int) $scalar("SELECT unit_level FROM {$prefix}unit WHERE unit_player_id = {$humanId}") === 7, 'Closing must retain unrelated human units');
  $closed = $storage->inSystem(1, $now + 9)[1];
  $check($closed['planet_id'] === null && Lifecycle::phase($closed, $now + 9) === 'closed', 'Closed trace must outlive its planet');
  $check($storage->findActive(1, 1, $now + 9) === null, 'Closed trace must not be a mission target');
  $fleetAfter = $one("SELECT * FROM {$prefix}fleets WHERE fleet_id = {$fleetId}");
  $check($fleetAfter['fleet_end_planet_id'] === null, 'Native FK must clear only the vanished target link');
  $fleetBefore['fleet_end_planet_id'] = null;
  $check($fleetBefore === $fleetAfter, 'Incoming fleet, mission state and flight times must survive closure');
  $check($one("SELECT * FROM {$prefix}users WHERE id = {$humanId}") === $humanBefore, 'Human user must remain untouched');
  $check($one("SELECT * FROM {$prefix}planets WHERE id = {$humanPlanetId}") === $humanPlanetBefore, 'Human planet must remain untouched');

  $storage->tick($now + 11, $zero, 1, 1, true);
  $check($storage->inSystem(1, $now + 11) === [], 'Final deadline must remove the trace');
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}users") === 2, 'Lifecycle must keep one reusable NPC and the human');

  $db->begin_transaction();
  $db->query("INSERT INTO {$prefix}users (username) VALUES ('Rollback sentinel')");
  $expectFailure(function () use ($storage, $now, $zero) {
    $storage->tick($now + 12, $zero, 1, 1, true);
  }, 'Tick must reject a caller-owned transaction');
  $check((int) $scalar('SELECT @@in_transaction') === 1, 'Tick must not commit or roll back a caller-owned transaction');
  $db->rollback();
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}users WHERE username = 'Rollback sentinel'") === 0, 'Caller must retain rollback control');
  $db->begin_transaction();
  $expectFailure(function () use ($db, $prefix) {
    (new Storage($db, $prefix))->install();
  }, 'Installer must never issue implicit-commit DDL inside a foreign transaction');
  $db->rollback();

  $storage->tick($now + 1000, $settings, 1, 1, true);
  $newCycle = $storage->inSystem(1, $now + 1000)[1];
  $check((int) $newCycle['born_at'] === $now + 1000, 'Idle catch-up must perform one bounded generation');
  $check((int) $newCycle['rift_id'] !== (int) $oldRift['rift_id'], 'Reused coordinates must get a new generation ID');
  $check($storage->npcOwnerId() === $npcId, 'New generations must reuse the original NPC');
  $storage->tick($now + 1001, $settings, 1, 1, true);
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_rifts") === 1, 'Forced tick must not bypass the generation interval');

  $db->query("INSERT INTO {$prefix}planets (name, id_owner, galaxy, system, planet) VALUES ('Foreign zero planet', {$humanId}, 0, 1, 1)");
  $foreignPlanetId = (int) $db->insert_id;
  $foreignPlanetBefore = $one("SELECT * FROM {$prefix}planets WHERE id = {$foreignPlanetId}");
  $expectFailure(function () use ($storage, $now, $settings) {
    $storage->tick($now + 1002, $settings, 1, 1, true);
  }, 'Occupied coordinates must block materialization without replacement');
  $check($one("SELECT * FROM {$prefix}planets WHERE id = {$foreignPlanetId}") === $foreignPlanetBefore, 'Foreign zero planet must remain untouched');
  $db->query("DELETE FROM {$prefix}planets WHERE id = {$foreignPlanetId}");
  $storage->tick($now + 1002, $settings, 1, 1, true);
  $newPlanetId = (int) $storage->findActive(1, 1, $now + 1002)['planet_id'];
  $db->query("INSERT INTO {$prefix}unit (unit_player_id, unit_location_type, unit_location_id, unit_snid, unit_level)
    VALUES ({$humanId}, 1, {$newPlanetId}, 201, 11)");
  $foreignUnitId = (int) $db->insert_id;
  $expectFailure(function () use ($storage, $now, $zero) {
    $storage->tick($now + 1009, $zero, 1, 1, true);
  }, 'Foreign units must block deletion rather than be removed');
  $check((int) $scalar("SELECT unit_level FROM {$prefix}unit WHERE unit_id = {$foreignUnitId}") === 11, 'Foreign unit must survive a failed cleanup');
  $db->query("DELETE FROM {$prefix}unit WHERE unit_id = {$foreignUnitId}");
  $db->query("UPDATE {$prefix}planets SET id_owner = {$humanId} WHERE id = {$newPlanetId}");
  $changedPlanet = $one("SELECT * FROM {$prefix}planets WHERE id = {$newPlanetId}");
  $expectFailure(function () use ($storage, $now, $zero) {
    $storage->tick($now + 1009, $zero, 1, 1, true);
  }, 'Ownership corruption must abort deletion');
  $check($one("SELECT * FROM {$prefix}planets WHERE id = {$newPlanetId}") === $changedPlanet, 'Corrupt ownership must never alter the human-owned row');
  $db->query("UPDATE {$prefix}planets SET id_owner = {$npcId} WHERE id = {$newPlanetId}");
  $db->query("UPDATE {$prefix}zero_galaxy_state SET npc_owner_id = {$humanId} WHERE id = 1");
  $expectFailure(function () use ($storage, $now, $zero) {
    $storage->tick($now + 1009, $zero, 1, 1, true);
  }, 'A human must never become the NPC through state corruption');
  $check($one("SELECT * FROM {$prefix}users WHERE id = {$humanId}") === $humanBefore, 'NPC identity failure must not update the human heartbeat');
  $db->query("UPDATE {$prefix}zero_galaxy_state SET npc_owner_id = {$npcId} WHERE id = 1");

  // Two PHP processes tick the same initialized world concurrently.
  $storage->tick($now + 1011, $zero, 1, 1, true);
  $db->query("DELETE FROM {$prefix}users WHERE id = {$npcId}");
  $db->query("UPDATE {$prefix}zero_galaxy_state SET npc_owner_id = 0, seeded_systems = 0, next_tick_at = 0, next_generation_at = 0 WHERE id = 1");
  $workers = [];
  for ($i = 0; $i < 2; $i++) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--worker', $prefix, (string) ($now + 2000)],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
      throw new RuntimeException('Could not start concurrent storage test');
    }
    fclose($pipes[0]);
    $workers[] = [$process, $pipes];
  }
  foreach ($workers as $worker) {
    list($process, $pipes) = $worker;
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $check(proc_close($process) === 0, 'Concurrent tick must succeed: ' . $output);
  }
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_rifts") === 1, 'Concurrent tick must preserve unique coordinates');
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}users WHERE user_bot = 200") === 1, 'Concurrent tick must not create another NPC');
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}planets WHERE galaxy = 0") === 0, 'Concurrent initial births must remain energy markers');

  echo 'Storage: ' . $assertions . " assertions passed; isolated prefix cleaned.\n";
} finally {
  if ((int) $scalar('SELECT @@in_transaction') !== 0) {
    $db->rollback();
  }
  $db->query('SET FOREIGN_KEY_CHECKS = 0');
  foreach ($tables as $table) {
    $db->query("DROP TABLE IF EXISTS {$prefix}{$table}");
  }
  $db->query('SET FOREIGN_KEY_CHECKS = 1');
  $db->close();
}
