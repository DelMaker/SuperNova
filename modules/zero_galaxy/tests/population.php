<?php

if (PHP_SAPI !== 'cli' || getenv('SUPERNOVA_DB_HOST') !== 'db'
  || getenv('SUPERNOVA_DB_NAME') !== 'supernova' || !is_file('/var/lib/supernova/cookie-secret')) {
  exit("Population tests require the local SuperNova Docker environment.\n");
}

chdir(dirname(__DIR__, 3));
$_SERVER = array_replace($_SERVER, [
  'SCRIPT_NAME' => '/zero-galaxy-population-test.php',
  'SCRIPT_FILENAME' => getcwd() . '/zero-galaxy-population-test.php',
  'HTTP_HOST' => 'localhost:8080', 'SERVER_NAME' => 'localhost',
  'REMOTE_ADDR' => '127.0.0.1', 'REQUEST_METHOD' => 'GET',
  'REQUEST_URI' => '/zero-galaxy-population-test.php', 'HTTP_USER_AGENT' => 'ZeroGalaxy population test',
]);
$_GET = $_POST = $_COOKIE = $_REQUEST = [];
define('IN_AJAX', true);
$allow_anonymous = true;
require getcwd() . '/common.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Settings.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Profile.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Lifecycle.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Storage.php';

use ZeroGalaxy\Lifecycle;
use ZeroGalaxy\Profile;
use ZeroGalaxy\Settings;
use ZeroGalaxy\Storage;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(getenv('SUPERNOVA_DB_HOST'), getenv('SUPERNOVA_DB_USER') ?: 'supernova',
  getenv('SUPERNOVA_DB_PASSWORD') ?: 'supernova-local', getenv('SUPERNOVA_DB_NAME'));
$db->set_charset('utf8');
$prefix = 'zg_pop_' . bin2hex(random_bytes(5)) . '_';
$sourcePrefix = SN::$db->db_prefix;
if (!preg_match('/^[a-zA-Z0-9_]*$/D', $sourcePrefix)) { throw new RuntimeException('Invalid source database prefix'); }
$tables = ['zero_galaxy_rifts', 'zero_galaxy_state', 'zero_galaxy_npcs', 'fleets', 'unit', 'planets', 'users'];
$assertions = 0;
$check = function ($condition, $message) use (&$assertions) {
  $assertions++;
  if (!$condition) { throw new RuntimeException($message); }
};
$one = function (string $sql) use ($db): ?array { return $db->query($sql)->fetch_assoc(); };
$scalar = function (string $sql) use ($db) { return $db->query($sql)->fetch_row()[0]; };
$rows = function (string $sql) use ($db): array { return $db->query($sql)->fetch_all(MYSQLI_ASSOC); };
$unitMap = function (int $owner, int $location, int $locationId, int $type) use ($rows, $prefix): array {
  $result = [];
  foreach ($rows("SELECT unit_snid, unit_level FROM {$prefix}unit WHERE unit_player_id = {$owner}
    AND unit_location_type = {$location} AND unit_location_id = {$locationId} AND unit_type = {$type} ORDER BY unit_snid") as $unit) {
    $result[(int) $unit['unit_snid']] = (int) $unit['unit_level'];
  }
  return $result;
};
$rates = [901 => (float) SN::$config->rpg_exchange_metal,
  902 => SN::$config->rpg_exchange_crystal / SN::$config->rpg_exchange_metal,
  903 => SN::$config->rpg_exchange_deuterium / SN::$config->rpg_exchange_metal];
$override = ['target_active_per_system' => 1, 'generation_seconds' => 100, 'categories' => []];
foreach (array_keys(Settings::load()['categories']) as $key) { $override['categories'][$key] = ['weight' => $key === 'great' ? 1 : 0]; }
$override['categories']['great'] += ['limit' => 2, 'phases_hours' => [
  'energy' => ['mean' => 2 / 3600, 'spread_percent' => 0],
  'active' => ['mean' => 3 / 3600, 'spread_percent' => 0],
  'unstable' => ['mean' => 4 / 3600, 'spread_percent' => 0],
  'closed' => ['mean' => 2 / 3600, 'spread_percent' => 0],
]];
$settings = Settings::load($override);
$futureOverride = $override;
$futureOverride['categories']['great']['name'] = 'Обновлённый Великий';
$futureOverride['categories']['great']['garrison_points'] = ['mean' => 134077480581000, 'spread_percent' => 0];
$futureOverride['categories']['great']['resource_percent'] = 0;
foreach ([106, 109, 110, 111] as $id) { $futureOverride['categories']['great']['tech'][$id] = ['mean' => 99, 'spread' => 0]; }
foreach (['energy' => 4, 'active' => 5, 'unstable' => 6, 'closed' => 7] as $phase => $seconds) {
  $futureOverride['categories']['great']['phases_hours'][$phase] = ['mean' => $seconds / 3600, 'spread_percent' => 0];
}
$future = Settings::load($futureOverride);
$paused = $future; $paused['target_active_per_system'] = 0;

try {
  foreach (['users', 'planets', 'unit', 'fleets'] as $table) {
    $db->query("CREATE TABLE {$prefix}{$table} LIKE {$sourcePrefix}{$table}");
  }
  $db->query("ALTER TABLE {$prefix}fleets ADD CONSTRAINT {$prefix}target
    FOREIGN KEY (fleet_end_planet_id) REFERENCES {$prefix}planets (id) ON DELETE SET NULL");
  Profile::validateSettings($settings);
  $native = Profile::nativeUnit(204, 200);
  $check($native['cost'] === (float) eco_get_total_cost(204, 1)['total'] && $native['cost'] > 0,
    'Profile cost must match the real native weighted unit helper.');
  $rejected = false;
  try { Profile::nativeUnit(401, 200); } catch (RuntimeException $error) { $rejected = true; }
  $check($rejected, 'Native resolver must reject defence IDs supplied as ships.');

  $now = 1700000000;
  $storage = new Storage($db, $prefix);
  $storage->install();
  $db->query("ALTER TABLE {$prefix}zero_galaxy_rifts AUTO_INCREMENT = 10000");
  $storage->tick($now, $settings, 4, 1);
  $initial = $rows("SELECT * FROM {$prefix}zero_galaxy_rifts ORDER BY rift_id");
  $check(count($initial) === 2, 'Four initially eligible systems must reserve only two global great slots.');
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}planets") === 0, 'Energy reservations must not materialize planets early.');
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_npcs") === 0, 'An unopened populated rift must not allocate a research owner.');
  $saved = [];
  foreach ($initial as $rift) {
    $id = (int) $rift['rift_id']; $saved[$id] = $rift;
    $profile = Profile::read($rift);
    $check($profile !== null && $profile['category']['key'] === 'great', 'Each reservation must persist its selected populated profile.');
    $check(Lifecycle::phase($rift, $now) === 'energy' && (int) $rift['opens_at'] === $now + 2,
      'The saved category phase settings must define its opening deadline.');
  }
  // A native human may already own the readable NPC name; no existing row may
  // be reused or renamed when the reserved rift eventually materializes.
  $collisionRiftId = (int) $initial[0]['rift_id'];
  $collisionName = Storage::NPC_NAME . ' #' . $collisionRiftId;
  $safeCollisionName = $db->real_escape_string($collisionName);
  $db->query("INSERT INTO {$prefix}users (username, password, galaxy)
    VALUES ('{$safeCollisionName}', '!disabled-human-fixture!', 1)");
  $collisionHumanId = (int) $db->insert_id;
  $collisionHumanBefore = $one("SELECT * FROM {$prefix}users WHERE id = {$collisionHumanId}");
  // Make generation due while both reservations are still in energy phase.
  $db->query("UPDATE {$prefix}zero_galaxy_state SET next_generation_at = " . ($now + 1));
  $storage->tick($now + 1, $future, 4, 1, true);
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_rifts") === 2, 'Energy reservations must consume the global cap during another due generation.');

  $storage->tick($now + 2, $future, 4, 1, true);
  $owners = []; $planetIds = [];
  foreach ($rows("SELECT * FROM {$prefix}zero_galaxy_rifts ORDER BY rift_id") as $rift) {
    $id = (int) $rift['rift_id']; $owner = (int) $rift['npc_owner_id']; $planetId = (int) $rift['planet_id'];
    $owners[$id] = $owner; $planetIds[$id] = $planetId;
    $profile = Profile::read($rift); $planet = $one("SELECT * FROM {$prefix}planets WHERE id = {$planetId}");
    $check($owner > 0 && $planetId > 0 && (int) $planet['id_owner'] === $owner, 'Opening must create an individually owned native planet.');
    if ($id === $collisionRiftId) {
      $npcName = $one("SELECT username FROM {$prefix}users WHERE id = {$owner}")['username'];
      $check($owner !== $collisionHumanId && $npcName !== $collisionName
        && preg_match('/^#' . $collisionRiftId . ' [a-f0-9]{8}$/D', $npcName)
        && preg_match('/^.{1,32}$/uD', $npcName),
        'A human name collision must create a separately named NPC instead of blocking materialization or reusing the human.');
      $check((int) $scalar("SELECT owner_id FROM {$prefix}zero_galaxy_npcs WHERE rift_id = {$id}") === $owner,
        'A suffixed NPC identity must still be registered to the correct rift.');
      $check($one("SELECT * FROM {$prefix}users WHERE id = {$collisionHumanId}") === $collisionHumanBefore,
        'Materialization must leave a human holding the NPC-looking name unchanged.');
    }
    $check($rift['config_snapshot'] === $saved[$id]['config_snapshot'], 'Hot configuration must not replace an existing profile snapshot.');
    foreach (['born_at', 'opens_at', 'unstable_at', 'closes_at', 'disappears_at'] as $deadline) {
      $check($rift[$deadline] === $saved[$id][$deadline], 'Hot configuration must preserve recorded ' . $deadline);
    }
    foreach ([200 => 'fleet', 400 => 'defence'] as $type => $key) {
      $expected = $profile[$key]; ksort($expected);
      $check($unitMap($owner, 1, $planetId, $type) === $expected, 'Materialized native ' . $key . ' must exactly match the stored profile.');
    }
    $expectedTech = array_filter($profile['tech'], function ($level) { return $level > 0; }); ksort($expectedTech);
    $check($unitMap($owner, 4, $owner, 100) === $expectedTech, 'Research must be stored on its own NPC user and match that rift snapshot.');
    $check((int) $planet['galaxy'] === 0 && (int) $planet['system'] === (int) $rift['system'] && (int) $planet['planet'] === 1,
      'Physical populated planets must retain the real zero-galaxy coordinates.');
    $equivalent = 0;
    foreach ([901 => 'metal', 902 => 'crystal', 903 => 'deuterium'] as $resource => $field) {
      $check((int) $planet[$field] === $profile['resources'][$resource], 'Initial raw loot must match the snapshot: ' . $field);
      $equivalent += (float) $planet[$field] * $rates[$resource];
    }
    $check($equivalent <= $profile['fleet_points'] * .9 + 1 && $equivalent > $profile['fleet_points'] * .6 - array_sum($rates) - 1,
      'Native weighted loot must be 60–90% of initial actual fleet cost, excluding defence.');
    $check($profile['fleet_limit'] === (int) floor($profile['garrison_points'] * 1.25), 'Saved baseline attack cap must use the initial actual garrison.');
    $nativeCost = 0;
    foreach ($profile['fleet'] + $profile['defence'] as $unitId => $count) { $nativeCost += eco_get_total_cost($unitId, $count)['total']; }
    $check(abs($nativeCost - $profile['garrison_points']) < 1, 'Persisted garrison points must match real native fleet plus defence prices.');
  }
  $check(count(array_unique($owners)) === 2, 'Two rifts must never share a research owner.');
  $unitCount = (int) $scalar("SELECT COUNT(*) FROM {$prefix}unit");
  $storage->tick($now + 3, $future, 4, 1, true);
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}unit") === $unitCount, 'Repeated ticks must not duplicate ships, defence or research.');
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}planets") === 2, 'Repeated ticks must not recreate existing physical planets.');
  $reader = new Storage($db, $prefix);
  foreach ($saved as $id => $rift) {
    $visible = $reader->inSystem((int) $rift['system'], $now + 3)[1];
    $check($visible['config_snapshot'] === $rift['config_snapshot'] && (int) $visible['owner_id'] === $owners[$id],
      'Independent readers must see the same immutable profile and real per-rift owner.');
  }

  $firstId = (int) array_key_first($saved); $secondId = (int) array_key_last($saved);
  $firstOwner = $owners[$firstId]; $firstPlanet = $planetIds[$firstId];
  $fleetUnit = $one("SELECT * FROM {$prefix}unit WHERE unit_player_id = {$firstOwner} AND unit_type = 200 ORDER BY unit_id LIMIT 1");
  $defenceUnit = $one("SELECT * FROM {$prefix}unit WHERE unit_player_id = {$firstOwner} AND unit_type = 400 ORDER BY unit_id LIMIT 1");
  $db->query("UPDATE {$prefix}unit SET unit_level = 5 WHERE unit_id = " . (int) $fleetUnit['unit_id']);
  $db->query("DELETE FROM {$prefix}unit WHERE unit_id = " . (int) $defenceUnit['unit_id']);
  $db->query("UPDATE {$prefix}planets SET metal = 7, crystal = 11, deuterium = 13 WHERE id = {$firstPlanet}");
  $storage->tick($now + 4, $future, 4, 1, true);
  $check((int) $scalar("SELECT unit_level FROM {$prefix}unit WHERE unit_id = " . (int) $fleetUnit['unit_id']) === 5, 'Destroyed ships must never be refilled by a later tick.');
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}unit WHERE unit_id = " . (int) $defenceUnit['unit_id']) === 0, 'A completely removed defence row must never be recreated.');
  $changedLoot = $one("SELECT metal, crystal, deuterium FROM {$prefix}planets WHERE id = {$firstPlanet}");
  $check(array_map('intval', $changedLoot) === ['metal' => 7, 'crystal' => 11, 'deuterium' => 13], 'Loot depletion must survive tick and changed resource balance.');
  $storage->tick($now + 5, $future, 4, 1, true);
  $check((int) $storage->findActive((int) $saved[$firstId]['system'], 1, $now + 5)['planet_id'] === $firstPlanet,
    'The unstable transition must preserve the same physical garrison planet.');
  $check($one("SELECT name FROM {$prefix}planets WHERE id = {$firstPlanet}")['name'] === Storage::UNSTABLE_NAME,
    'Instability must update the display phase while keeping the depleted army.');

  $db->query("INSERT INTO {$prefix}users (username, password, galaxy) VALUES ('Unrelated population-test pilot', '!disabled!', 1)");
  $human = (int) $db->insert_id;
  $db->query("INSERT INTO {$prefix}planets (name, id_owner, galaxy, system, planet, metal) VALUES ('Unrelated test capital', {$human}, 1, 1, 1, 1234)");
  $humanPlanet = (int) $db->insert_id;
  $humanBefore = $one("SELECT * FROM {$prefix}users WHERE id = {$human}");
  $humanPlanetBefore = $one("SELECT * FROM {$prefix}planets WHERE id = {$humanPlanet}");
  $db->query("INSERT INTO {$prefix}fleets (fleet_owner, fleet_target_owner, fleet_mission, fleet_start_planet_id,
    fleet_end_planet_id, fleet_end_galaxy, fleet_end_system, fleet_end_planet, fleet_mess, fleet_start_time, fleet_end_time)
    VALUES ({$human}, {$firstOwner}, 6, {$humanPlanet}, {$firstPlanet}, 0, " . (int) $saved[$firstId]['system'] . ", 1, 0, " . ($now + 1000) . ', ' . ($now + 2000) . ')');
  $pendingFleet = (int) $db->insert_id;
  $fleetBefore = $one("SELECT * FROM {$prefix}fleets WHERE fleet_id = {$pendingFleet}");
  // Closed traces must reserve both slots even when another generation is due.
  $db->query("UPDATE {$prefix}zero_galaxy_state SET next_generation_at = " . ($now + 9));
  $storage->tick($now + 9, $future, 4, 1, true);
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_rifts") === 2, 'Closed traces must continue reserving the global cap.');
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}planets WHERE galaxy = 0") === 0, 'Closure must remove all materialized NPC planets.');
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}unit WHERE unit_location_type = 1") === 0, 'Closure must remove fleet and defence rows with their planets.');
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_npcs") === 2, 'Research owners must remain while their closed traces still exist.');
  $fleetBefore['fleet_end_planet_id'] = null;
  $check($one("SELECT * FROM {$prefix}fleets WHERE fleet_id = {$pendingFleet}") === $fleetBefore,
    'Closing may null the planet link but must retain the target owner and pending fleet schedule.');

  $storage->tick($now + 11, $paused, 4, 1, true);
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_rifts") === 0, 'Final expiration must release both trace reservations.');
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}users WHERE id = {$firstOwner}") === 1,
    'An expired target NPC must survive while a pending fleet still references that owner.');
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}users WHERE id = " . $owners[$secondId]) === 0,
    'An expired NPC without pending references must be retired.');
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_npcs") === 1, 'Only the pending-fleet NPC should retain its module ownership marker.');
  $db->query("DELETE FROM {$prefix}fleets WHERE fleet_id = {$pendingFleet}");
  $storage->tick($now + 12, $paused, 4, 1, true);
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_npcs") === 0, 'The last expired NPC must retire after its pending fleet is gone.');
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}unit") === 0, 'Retirement must clean the expired NPC research.');
  $check($one("SELECT * FROM {$prefix}users WHERE id = {$human}") === $humanBefore
    && $one("SELECT * FROM {$prefix}planets WHERE id = {$humanPlanet}") === $humanPlanetBefore,
    'Population and retirement must leave unrelated users and planets unchanged.');
  $check($one("SELECT * FROM {$prefix}users WHERE id = {$collisionHumanId}") === $collisionHumanBefore,
    'Retiring a suffixed NPC must preserve the original human with a colliding display name.');

  $db->query("UPDATE {$prefix}zero_galaxy_state SET next_generation_at = " . ($now + 13));
  $storage->tick($now + 13, $future, 4, 1, true);
  $replacement = $rows("SELECT * FROM {$prefix}zero_galaxy_rifts ORDER BY rift_id");
  $check(count($replacement) === 2, 'Released global capacity must allow exactly two new great rifts.');
  foreach ($replacement as $rift) {
    $profile = Profile::read($rift);
    $check(!isset($saved[(int) $rift['rift_id']]) && (int) $rift['opens_at'] === $now + 17,
      'A replacement must have a new generation identity and use the changed phase duration.');
    $check($profile['category']['name'] === 'Обновлённый Великий' && $profile['target_points'] === 134077480581000,
      'New births must use changed category metadata and cost.');
    $check($profile['tech'] === [106 => 99, 109 => 99, 110 => 99, 111 => 99] && array_sum($profile['resources']) === 0,
      'New births must use changed research and loot settings.');
  }
  $storage->tick($now + 35, $paused, 4, 1, true);
  $check((int) $scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_rifts") === 0
    && (int) $scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_npcs") === 0,
    'Skipping directly beyond a replacement lifetime must leave no trace or per-rift NPC leaks.');
  echo "Population: {$assertions} assertions passed; native profiles and isolated tables checked.\n";
} finally {
  if ((int) $scalar('SELECT @@in_transaction') !== 0) { $db->rollback(); }
  $db->query('SET FOREIGN_KEY_CHECKS = 0');
  foreach ($tables as $table) { $db->query("DROP TABLE IF EXISTS {$prefix}{$table}"); }
  $db->query('SET FOREIGN_KEY_CHECKS = 1');
  $db->close();
}
