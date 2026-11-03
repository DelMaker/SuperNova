<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/support/autoload.php';

use ZeroGalaxy\Settings;
use ZeroGalaxy\Storage;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function storageTestDb(): mysqli {
  $db = new mysqli(getenv('SUPERNOVA_DB_HOST') ?: 'db', getenv('SUPERNOVA_DB_USER') ?: 'supernova',
    getenv('SUPERNOVA_DB_PASSWORD') ?: 'supernova-local', getenv('SUPERNOVA_DB_NAME') ?: 'supernova');
  $db->set_charset('utf8');
  return $db;
}
function storageTestSettings(): array { return Settings::load(['target_active_per_system' => 0]); }
if (($argv[1] ?? '') === '--worker') {
  $prefix = $argv[2] ?? '';
  if (!preg_match('/^zg_test_[a-f0-9]{10}_$/D', $prefix)) { throw new RuntimeException('Worker requires an isolated prefix'); }
  (new Storage(storageTestDb(), $prefix))->tick((int) $argv[3], storageTestSettings(), 2, 1);
  exit;
}

$db = storageTestDb();
$prefix = 'zg_test_' . bin2hex(random_bytes(5)) . '_';
$tables = ['zero_galaxy_rifts', 'zero_galaxy_state', 'zero_galaxy_npcs', 'fleets', 'unit', 'planets', 'users'];
require __DIR__ . '/support/assertions.php';
$one = function (string $sql) use ($db): ?array { return $db->query($sql)->fetch_assoc(); };
$scalar = function (string $sql) use ($db) { return $db->query($sql)->fetch_row()[0]; };
$expectFailure = function (callable $operation, string $message) use ($check): void {
  try { $operation(); } catch (RuntimeException $error) { $check(true, $message); return; }
  $check(false, $message);
};
$createUser = function (string $name, int $bot = 0) use ($db, $prefix): int {
  $name = $db->real_escape_string($name);
  $password = '!' . str_repeat('a', 62) . '!';
  $db->query("INSERT INTO {$prefix}users (username,password,user_bot,galaxy) VALUES ('{$name}','{$password}',{$bot},0)");
  return (int) $db->insert_id;
};
$createRift = function (string $key, int $system, int $owner, ?int $planet, int $now) use ($db, $prefix): int {
  $profile = ['version' => 1, 'category' => ['key' => $key, 'name' => 'Крошечный', 'color' => '#ADFF2F'],
    'fleet' => [204 => 3], 'defence' => [401 => 2], 'tech' => [109 => 99], 'resources' => [901 => 123, 902 => 45, 903 => 6],
    'garrison_points' => 100, 'fleet_limit' => 125];
  $snapshot = $db->real_escape_string(json_encode(['profile' => $profile]));
  $planetSql = $planet === null ? 'NULL' : (string) $planet;
  $db->query("INSERT INTO {$prefix}zero_galaxy_rifts
    (system,position,born_at,opens_at,unstable_at,closes_at,disappears_at,image,config_snapshot,planet_id,npc_owner_id)
    VALUES ({$system},1," . ($now - 2) . ',' . ($now - 1) . ',' . ($now + 30) . ',' . ($now + 60) . ',' . ($now + 90) . ",
      'normaltempplanet01','{$snapshot}',{$planetSql},{$owner})");
  return (int) $db->insert_id;
};

try {
  foreach (['users', 'planets', 'unit', 'fleets'] as $table) { $db->query("CREATE TABLE {$prefix}{$table} LIKE sn_{$table}"); }
  $db->query("ALTER TABLE {$prefix}fleets ADD CONSTRAINT {$prefix}target
    FOREIGN KEY (fleet_end_planet_id) REFERENCES {$prefix}planets (id) ON DELETE SET NULL");
  $db->query("CREATE TABLE {$prefix}zero_galaxy_rifts (
    rift_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    system INT UNSIGNED NOT NULL, position INT UNSIGNED NOT NULL,
    born_at BIGINT UNSIGNED NOT NULL, opens_at BIGINT UNSIGNED NOT NULL,
    unstable_at BIGINT UNSIGNED NOT NULL, closes_at BIGINT UNSIGNED NOT NULL,
    disappears_at BIGINT UNSIGNED NOT NULL, image VARCHAR(64) NOT NULL, config_snapshot TEXT NOT NULL,
    planet_id BIGINT UNSIGNED NULL, npc_owner_id BIGINT UNSIGNED NULL, UNIQUE KEY coordinates (system,position)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
  $db->query("CREATE TABLE {$prefix}zero_galaxy_state (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY, next_tick_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
    next_generation_at BIGINT UNSIGNED NOT NULL DEFAULT 0, seeded_systems INT UNSIGNED NOT NULL DEFAULT 0,
    npc_owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0, stats_hidden_ids TEXT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
  $db->query("CREATE TABLE {$prefix}zero_galaxy_npcs (owner_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    rift_id BIGINT UNSIGNED NOT NULL, UNIQUE KEY rift (rift_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8");
  $now = 1700000000; $settings = storageTestSettings();
  $legacyOwner = $createUser(Storage::NPC_NAME, 200);
  $oldOwner = $createUser(Storage::NPC_NAME . ' #1', 200);
  $orphan = $createUser(Storage::NPC_NAME . ' #2', 200);
  $human = $createUser('Untouched human');
  $humanBefore = $one("SELECT * FROM {$prefix}users WHERE id = {$human}");
  $db->query("INSERT INTO {$prefix}planets (name,id_owner,galaxy,system,planet,metal,debris_metal,debris_crystal)
    VALUES ('Migrated planet',{$oldOwner},0,1,1,7,21000,13000)");
  $planet = (int) $db->insert_id;
  $rift = $createRift('tiny', 1, $oldOwner, $planet, $now);
  $before = $one("SELECT * FROM {$prefix}zero_galaxy_rifts WHERE rift_id = {$rift}");
  $db->query("INSERT INTO {$prefix}zero_galaxy_npcs VALUES ({$oldOwner},{$rift}),({$orphan},999)");
  $db->query("INSERT INTO {$prefix}zero_galaxy_state VALUES (1,0," . ($now + 1000) . ",2,{$legacyOwner},NULL)");
  $db->query("INSERT INTO {$prefix}unit
    (unit_player_id,unit_location_type,unit_location_id,unit_type,unit_snid,unit_level)
    VALUES ({$oldOwner},1,{$planet},200,204,3),({$oldOwner},4,{$oldOwner},100,109,99),({$orphan},4,{$orphan},1100,1101,1)");
  $db->query("INSERT INTO {$prefix}fleets
    (fleet_owner,fleet_target_owner,fleet_mission,fleet_end_planet_id,fleet_end_galaxy,fleet_end_system,fleet_end_planet,fleet_start_time,fleet_end_time)
    VALUES ({$human},{$oldOwner},6,{$planet},0,1,1," . ($now + 100) . ',' . ($now + 200) . ')');
  $fleet = (int) $db->insert_id;
  $flightBefore = $one("SELECT * FROM {$prefix}fleets WHERE fleet_id = {$fleet}");

  $storage = new Storage($db, $prefix); $storage->install();
  $check($db->query("SHOW COLUMNS FROM {$prefix}zero_galaxy_npcs LIKE 'category_key'")->num_rows === 1,
    'Installation must upgrade the per-rift registry to support categories');
  $check($one("SELECT * FROM {$prefix}zero_galaxy_rifts WHERE rift_id = {$rift}") === $before,
    'Schema migration must retain the existing rift');
  $writeCommands = function () use ($db): array {
    return $db->query("SHOW SESSION STATUS WHERE Variable_name IN
      ('Com_create_table','Com_alter_table','Com_insert','Com_update','Com_delete','Com_replace')")->fetch_all(MYSQLI_ASSOC);
  };
  $writes = $writeCommands(); (new Storage($db, $prefix))->install();
  $check($writeCommands() === $writes, 'An installed schema must need no DDL or data writes on a fresh request');
  $storage->tick($now, $settings, 2, 1);
  $owners = [];
  foreach ($db->query("SELECT owner_id,category_key FROM {$prefix}zero_galaxy_npcs")->fetch_all(MYSQLI_ASSOC) as $npc) {
    $owners[$npc['category_key']] = (int) $npc['owner_id'];
  }
  $check(count($owners) === 7 && !array_diff_key($settings['categories'], $owners),
    'There must be exactly one NPC per configured category, including categories without planets');
  $owner = $owners['tiny'];
  $after = $one("SELECT * FROM {$prefix}zero_galaxy_rifts WHERE rift_id = {$rift}");
  $before['npc_owner_id'] = (string) $owner;
  $check($after === $before, 'Owner migration must preserve all saved phase deadlines, limits and garrison data');
  $body = $one("SELECT id_owner,metal,debris_metal,debris_crystal FROM {$prefix}planets WHERE id = {$planet}");
  $check(array_map('intval', $body) === ['id_owner' => $owner, 'metal' => 7, 'debris_metal' => 21000, 'debris_crystal' => 13000],
    'Owner migration must preserve depleted loot and debris');
  $check((int) $scalar("SELECT unit_level FROM {$prefix}unit WHERE unit_player_id = {$owner} AND unit_location_type = 1 AND unit_snid = 204") === 3,
    'Owner migration must transfer the actual depleted garrison');
  $flightBefore['fleet_target_owner'] = (string) $owner;
  $check($one("SELECT * FROM {$prefix}fleets WHERE fleet_id = {$fleet}") === $flightBefore,
    'Owner migration must retain an incoming flight and change only its target owner');
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}users WHERE id IN ({$oldOwner},{$orphan},{$legacyOwner})") === 0
    && (int) $scalar("SELECT COUNT(*) FROM {$prefix}unit WHERE unit_player_id IN ({$oldOwner},{$orphan},{$legacyOwner})") === 0,
    'Migration must retire all old per-rift and legacy NPCs, including external blueprint inventory');
  $check((int) $scalar("SELECT npc_owner_id FROM {$prefix}zero_galaxy_state WHERE id = 1") === 0, 'Migration must stop using the global legacy owner');
  $npc = $one("SELECT * FROM {$prefix}users WHERE id = {$owner}");
  $check((int) $npc['user_bot'] === 200 && (int) $npc['authlevel'] === 0 && (int) $npc['parent_account_id'] === 0
    && strlen($npc['password']) === 64 && $npc['password'][0] === '!', 'Shared NPCs must remain service users with disabled login');

  $changed = $settings; $changed['categories']['tiny']['tech'][109] = 17; $changed['categories']['tiny']['tech'][106] = 0;
  $storage->tick($now + 1, $changed, 2, 1, true);
  $check((int) $scalar("SELECT unit_level FROM {$prefix}unit WHERE unit_player_id = {$owner} AND unit_location_type = 4 AND unit_snid = 109") === 17
    && (int) $scalar("SELECT COUNT(*) FROM {$prefix}unit WHERE unit_player_id = {$owner} AND unit_location_type = 4 AND unit_snid = 106") === 0,
    'Configuration must update existing NPC research and remove zero-level technology');
  $check((int) $scalar("SELECT npc_owner_id FROM {$prefix}zero_galaxy_rifts WHERE rift_id = {$rift}") === $owner
    && (int) $scalar("SELECT onlinetime FROM {$prefix}users WHERE id = {$owner}") === $now + 1,
    'Technology updates must reuse the owner and retain its active-player heartbeat');

  $added = $changed; $added['categories']['custom'] = $added['categories']['tiny'];
  $added['categories']['custom']['name'] = 'Тестовый';
  $storage->tick($now + 2, $added, 2, 1, true);
  $customOwner = (int) $scalar("SELECT owner_id FROM {$prefix}zero_galaxy_npcs WHERE category_key = 'custom'");
  $check($customOwner > 0 && (int) $scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_npcs") === 8,
    'Adding a category must create exactly one additional NPC');
  $customRift = $createRift('custom', 2, $customOwner, null, $now);
  $storage->tick($now + 3, $added, 2, 1, true);
  $customPlanet = (int) $scalar("SELECT planet_id FROM {$prefix}zero_galaxy_rifts WHERE rift_id = {$customRift}");
  $db->query("INSERT INTO {$prefix}fleets
    (fleet_owner,fleet_target_owner,fleet_mission,fleet_end_planet_id,fleet_end_galaxy,fleet_end_system,fleet_end_planet,fleet_start_time,fleet_end_time)
    VALUES ({$human},{$customOwner},8,{$customPlanet},0,2,1," . ($now + 100) . ',' . ($now + 200) . ')');
  $customFleet = (int) $db->insert_id;
  $customFlight = $one("SELECT * FROM {$prefix}fleets WHERE fleet_id = {$customFleet}");
  $storage->tick($now + 4, $changed, 2, 1, true);
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_npcs") === 7
    && (int) $scalar("SELECT COUNT(*) FROM {$prefix}users WHERE id = {$customOwner}") === 0
    && (int) $scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_rifts WHERE rift_id = {$customRift}") === 0
    && (int) $scalar("SELECT COUNT(*) FROM {$prefix}planets WHERE id = {$customPlanet}") === 0,
    'Removing a category must remove its rifts, planets and service user');
  $customFlight['fleet_end_planet_id'] = null; $customFlight['fleet_target_owner'] = '0';
  $check($one("SELECT * FROM {$prefix}fleets WHERE fleet_id = {$customFleet}") === $customFlight,
    'Removing a category must retain pending flight times and cargo while clearing vanished target links');
  $check($one("SELECT * FROM {$prefix}users WHERE id = {$human}") === $humanBefore, 'NPC synchronization must leave human users unchanged');

  $db->begin_transaction();
  $expectFailure(function () use ($storage, $now, $settings): void { $storage->tick($now + 5, $settings, 2, 1, true); },
    'World synchronization must reject a caller-owned transaction');
  $check((int) $scalar('SELECT @@in_transaction') === 1, 'Rejection must not commit or roll back the caller transaction');
  $db->rollback();

  $db->query("UPDATE {$prefix}zero_galaxy_npcs SET category_key = owner_id");
  $db->query("ALTER TABLE {$prefix}zero_galaxy_npcs DROP INDEX category,
    CHANGE COLUMN category_key rift_id BIGINT UNSIGNED NOT NULL, ADD UNIQUE KEY rift (rift_id)");
  $db->query("UPDATE {$prefix}zero_galaxy_state SET next_tick_at = 0 WHERE id = 1");
  $workers = [];
  for ($i = 0; $i < 2; $i++) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--worker', $prefix, (string) ($now + 2000)],
      [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Could not start concurrent storage test'); }
    fclose($pipes[0]); $workers[] = [$process, $pipes];
  }
  foreach ($workers as [$process, $pipes]) {
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $check(proc_close($process) === 0, 'Concurrent category synchronization must succeed: ' . $output);
  }
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_npcs") === 7
    && (int) $scalar("SELECT COUNT(*) FROM {$prefix}users WHERE user_bot = 200") === 7,
    'Concurrent requests must not duplicate or leak NPCs');
  echo "Storage: {$assertions} assertions passed; isolated schema migration and category lifecycle checked.\n";
} finally {
  if ((int) $scalar('SELECT @@in_transaction') !== 0) { $db->rollback(); }
  $db->query('SET FOREIGN_KEY_CHECKS = 0');
  foreach ($tables as $table) { $db->query("DROP TABLE IF EXISTS {$prefix}{$table}"); }
  $db->query('SET FOREIGN_KEY_CHECKS = 1'); $db->close();
}
