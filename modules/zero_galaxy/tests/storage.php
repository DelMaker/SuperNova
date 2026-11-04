<?php

require __DIR__ . '/support/bootstrap.php';
zgCoreConstants();
require __DIR__ . '/support/database.php';

use ZeroGalaxy\Settings;
use ZeroGalaxy\Storage;

$settings = Settings::load(['target_active_per_system' => 0]);
if (($argv[1] ?? '') === '--worker') {
  (new Storage((new ZgTables(zgDb(), 'storage', $argv[2] ?? ''))->sql()))->tick((int) $argv[3], $settings, 2, 1);
  exit;
}

$tables = new ZgTables(zgDb(), 'storage');
$db = $tables->db;
$prefix = $tables->prefix;
$now = ZG_TEST_NOW;
$createUser = function (string $name, int $bot = 0) use ($db, $prefix): int {
  $name = $db->real_escape_string($name);
  $password = '!' . str_repeat('a', 62) . '!';
  $db->query("INSERT INTO {$prefix}users (username,password,user_bot,galaxy) VALUES ('{$name}','{$password}',{$bot},0)");
  return (int) $db->insert_id;
};
$owners = function () use ($tables, $prefix): array {
  return array_map('intval', array_column($tables->rows("SELECT owner_id, category_key FROM {$prefix}zero_galaxy_npcs"),
    'owner_id', 'category_key'));
};
$createRift = function (string $key, int $system, int $owner, int $opensIn = -1) use ($db, $prefix, $now): int {
  $snapshot = $db->real_escape_string(zgSnapshot(['category' => ['key' => $key], 'fleet' => [204 => 3],
    'defence' => [401 => 2], 'resources' => [901 => 123, 902 => 45, 903 => 6]]));
  $db->query("INSERT INTO {$prefix}zero_galaxy_rifts
    (system,position,born_at,opens_at,unstable_at,closes_at,disappears_at,image,config_snapshot,npc_owner_id)
    VALUES ({$system},1," . ($now - 2) . ',' . ($now + $opensIn) . ',' . ($now + 30) . ',' . ($now + 60) . ',' . ($now + 90) . ",
      'normaltempplanet01','{$snapshot}',{$owner})");
  return (int) $db->insert_id;
};
$planetOf = function (int $rift) use ($tables, $prefix): ?array {
  return $tables->one("SELECT p.* FROM {$prefix}planets p JOIN {$prefix}zero_galaxy_rifts r ON r.planet_id = p.id
    WHERE r.rift_id = {$rift}");
};
$commands = function () use ($db): array {
  return array_column($db->query("SHOW SESSION STATUS WHERE Variable_name IN
    ('Com_create_table','Com_alter_table','Com_insert','Com_update','Com_delete','Com_replace')")->fetch_all(), 1, 0);
};

try {
  $tables->cloneGame(['users', 'planets', 'unit', 'fleets']);
  $db->query("ALTER TABLE {$prefix}fleets ADD CONSTRAINT {$prefix}target
    FOREIGN KEY (fleet_end_planet_id) REFERENCES {$prefix}planets (id) ON DELETE SET NULL");
  $human = $createUser('Untouched human');
  $humanBefore = $tables->one("SELECT * FROM {$prefix}users WHERE id = {$human}");

  $storage = new Storage($tables->sql());
  $check($storage->tick($now, $settings, 2, 1) === true, 'The first request must install the schema and reconcile the world');
  $npcs = $owners();
  $check(count($npcs) === 7 && !array_diff_key($settings['categories'], $npcs),
    'There must be exactly one NPC per configured category, including categories without planets');
  $writes = $commands();
  $check((new Storage($tables->sql()))->tick($now, $settings, 2, 1) === false && $commands() === $writes,
    'A fresh request before the schedule is due must need no DDL or data writes');
  $npc = $tables->one("SELECT * FROM {$prefix}users WHERE id = {$npcs['tiny']}");
  $check((int) $npc['user_bot'] === 200 && (int) $npc['authlevel'] === 0 && (int) $npc['parent_account_id'] === 0
    && strlen($npc['password']) === 64 && $npc['password'][0] === '!' && $npc['username'] === 'Обитатели разлома Крошечный',
    'Shared NPCs must remain named service users with disabled login');

  $first = $createRift('tiny', 1, $npcs['tiny'], 1);
  $storage->tick($now, $settings, 2, 1, true);
  $check($planetOf($first) === null, 'Energy reservations must not create planets early');
  $storage->tick($now + 1, $settings, 2, 1, true);
  $planet = $planetOf($first);
  $check($planet && (int) $planet['id_owner'] === $npcs['tiny'] && $planet['name'] === 'Крошечный разлом'
    && [(int) $planet['metal'], (int) $planet['crystal'], (int) $planet['deuterium']] === [123, 45, 6],
    'An opened rift must become a native planet with its saved name and loot');
  $garrison = array_map('intval', array_column($tables->rows("SELECT unit_snid, unit_level FROM {$prefix}unit
    WHERE unit_player_id = {$npcs['tiny']} AND unit_location_type = 1 AND unit_location_id = {$planet['id']}"), 'unit_level', 'unit_snid'));
  $check($garrison === [204 => 3, 401 => 2], 'The saved garrison must be created for the category NPC');

  $second = $createRift('tiny', 2, $npcs['tiny']);
  $storage->tick($now + 2, $settings, 2, 1, true);
  $db->query("UPDATE {$prefix}planets SET metal_perhour = 99, energy_max = 5, last_update = 0, metal = 7 WHERE galaxy = 0");
  $before = $commands();
  $storage->tick($now + 3, $settings, 2, 1, true);
  $after = $commands();
  $frozen = $tables->rows("SELECT name, CAST(metal AS SIGNED) AS metal, metal_perhour, energy_max, last_update
    FROM {$prefix}planets WHERE galaxy = 0");
  $check(count($frozen) === 2 && $frozen === array_fill(0, 2, ['name' => 'Крошечный разлом', 'metal' => '7',
    'metal_perhour' => '0', 'energy_max' => '0', 'last_update' => (string) ($now + 3)]),
    'Every tick must freeze native income without refilling loot or renaming an unchanged phase');
  $check($after['Com_update'] - $before['Com_update'] === 3 && $after['Com_insert'] === $before['Com_insert'],
    'One tick needs a fixed number of updates however many planets exist: owners, planets, schedule');
  $storage->tick($now + 30, $settings, 2, 1, true);
  $check($planetOf($first)['name'] === 'Крошечный нестабильный разлом' && $planetOf($second)['name'] === 'Крошечный нестабильный разлом',
    'A phase change must rename the native planet');

  $changed = $settings; $changed['categories']['tiny']['tech'][109] = 17; $changed['categories']['tiny']['tech'][106] = 0;
  $storage->tick($now + 31, $changed, 2, 1, true);
  $check((int) $tables->scalar("SELECT unit_level FROM {$prefix}unit WHERE unit_player_id = {$npcs['tiny']} AND unit_location_type = 4 AND unit_snid = 109") === 17
    && (int) $tables->scalar("SELECT COUNT(*) FROM {$prefix}unit WHERE unit_player_id = {$npcs['tiny']} AND unit_location_type = 4 AND unit_snid = 106") === 0,
    'Configuration must update existing NPC research and remove zero-level technology');
  $check($owners() === $npcs && (int) $tables->scalar("SELECT onlinetime FROM {$prefix}users WHERE id = {$npcs['tiny']}") === $now + 31,
    'Technology updates must reuse the owners and retain their active-player heartbeat');

  $foreign = $createRift('tiny', 3, $npcs['small']);
  $snapshot = [$tables->rows("SELECT * FROM {$prefix}zero_galaxy_rifts"), $tables->rows("SELECT * FROM {$prefix}planets")];
  zgExpect(function () use ($storage, $now, $changed) { $storage->tick($now + 32, $changed, 2, 1, true); }, RuntimeException::class,
    'A rift owned by another category NPC must stop reconciliation instead of being altered');
  $check([$tables->rows("SELECT * FROM {$prefix}zero_galaxy_rifts"), $tables->rows("SELECT * FROM {$prefix}planets")] === $snapshot,
    'A refused reconciliation must roll back completely');
  $db->query("DELETE FROM {$prefix}zero_galaxy_rifts WHERE rift_id = {$foreign}");

  $added = $changed; $added['categories']['custom'] = $added['categories']['tiny'];
  $added['categories']['custom']['name'] = 'Тестовый';
  $storage->tick($now + 33, $added, 2, 1, true);
  $customOwner = $owners()['custom'] ?? 0;
  $check($customOwner > 0 && count($owners()) === 8, 'Adding a category must create exactly one additional NPC');
  $customRift = $createRift('custom', 4, $customOwner);
  $storage->tick($now + 34, $added, 2, 1, true);
  $customPlanet = (int) $planetOf($customRift)['id'];
  $db->query("INSERT INTO {$prefix}fleets
    (fleet_owner,fleet_target_owner,fleet_mission,fleet_end_planet_id,fleet_end_galaxy,fleet_end_system,fleet_end_planet,fleet_start_time,fleet_end_time)
    VALUES ({$human},{$customOwner},8,{$customPlanet},0,4,1," . ($now + 100) . ',' . ($now + 200) . ')');
  $customFleet = (int) $db->insert_id;
  $customFlight = $tables->one("SELECT * FROM {$prefix}fleets WHERE fleet_id = {$customFleet}");
  $storage->tick($now + 35, $changed, 2, 1, true);
  $check(count($owners()) === 7 && (int) $tables->scalar("SELECT COUNT(*) FROM {$prefix}users WHERE id = {$customOwner}") === 0
    && (int) $tables->scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_rifts WHERE rift_id = {$customRift}") === 0
    && (int) $tables->scalar("SELECT COUNT(*) FROM {$prefix}planets WHERE id = {$customPlanet}") === 0,
    'Removing a category must remove its rifts, planets and service user');
  $customFlight['fleet_end_planet_id'] = null; $customFlight['fleet_target_owner'] = '0';
  $check($tables->one("SELECT * FROM {$prefix}fleets WHERE fleet_id = {$customFleet}") === $customFlight,
    'Removing a category must retain pending flight times and cargo while clearing vanished target links');
  $check($tables->one("SELECT * FROM {$prefix}users WHERE id = {$human}") === $humanBefore, 'NPC synchronization must leave human users unchanged');

  $db->begin_transaction();
  zgExpect(function () use ($storage, $now, $settings): void { $storage->tick($now + 36, $settings, 2, 1, true); },
    RuntimeException::class, 'World synchronization must reject a caller-owned transaction');
  $check((int) $tables->scalar('SELECT @@in_transaction') === 1, 'Rejection must not commit or roll back the caller transaction');
  $db->rollback();

  $db->query("DELETE FROM {$prefix}zero_galaxy_state");
  $check($storage->tick($now + 37, $settings, 2, 1) === true
    && (int) $tables->scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_state") === 1,
    'A missing state row must be recreated before the world is reconciled');

  // A fresh registry: concurrent first requests must not duplicate category NPCs.
  foreach (['zero_galaxy_rifts', 'zero_galaxy_npcs', 'unit'] as $table) { $db->query("DELETE FROM {$prefix}{$table}"); }
  $db->query("DELETE FROM {$prefix}planets WHERE galaxy = 0");
  $db->query("DELETE FROM {$prefix}users WHERE user_bot = 200");
  $db->query("UPDATE {$prefix}zero_galaxy_state SET next_tick_at = 0 WHERE id = 1");
  $workers = [zgWorker(['--worker', $prefix, (string) ($now + 2000)]), zgWorker(['--worker', $prefix, (string) ($now + 2000)])];
  foreach ($workers as $worker) {
    [$code, $output, $errors] = zgFinish($worker);
    $check($code === 0, 'Concurrent category synchronization must succeed: ' . $output . $errors);
  }
  $check(count($owners()) === 7 && (int) $tables->scalar("SELECT COUNT(*) FROM {$prefix}users WHERE user_bot = 200") === 7,
    'Concurrent requests must not duplicate or leak NPCs');
  echo "Storage: {$assertions} assertions passed; isolated schema, batched ticks and category lifecycle checked.\n";
} finally {
  $tables->drop();
  $db->close();
}
