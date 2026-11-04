<?php

require __DIR__ . '/support/bootstrap.php';
require __DIR__ . '/support/database.php';
zgGameRequest('zero-galaxy-population-test');
require getcwd() . '/common.php';

use ZeroGalaxy\Profile;
use ZeroGalaxy\RiftName;
use ZeroGalaxy\Settings;
use ZeroGalaxy\Storage;

$tables = new ZgTables(zgDb(), 'population');
$db = $tables->db;
$prefix = $tables->prefix;
$unitMap = function (int $owner, int $location, int $locationId, int $type) use ($tables, $prefix): array {
  $result = [];
  foreach ($tables->rows("SELECT unit_snid, unit_level FROM {$prefix}unit WHERE unit_player_id = {$owner}
    AND unit_location_type = {$location} AND unit_location_id = {$locationId} AND unit_type = {$type} ORDER BY unit_snid") as $unit) {
    $result[(int) $unit['unit_snid']] = (int) $unit['unit_level'];
  }
  return $result;
};
$rates = Profile::nativeRates();
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
$futureOverride['categories']['great'] += ['name' => 'Обновлённый Великий',
  'garrison_points' => ['mean' => 134077480581000, 'spread_percent' => 0], 'resource_percent' => 0];
$futureOverride['categories']['great']['tech'] = [106 => 99, 109 => 99, 110 => 99, 111 => 99];
$future = Settings::load($futureOverride);
$paused = $future; $paused['target_active_per_system'] = 0;

try {
  $tables->cloneGame(['users', 'planets', 'unit', 'fleets'], SN::$db->db_prefix);
  $db->query("ALTER TABLE {$prefix}fleets ADD CONSTRAINT {$prefix}target
    FOREIGN KEY (fleet_end_planet_id) REFERENCES {$prefix}planets (id) ON DELETE SET NULL");
  Profile::validateSettings($settings);
  $check(Profile::nativeUnit(204, 200)['cost'] === (float) eco_get_total_cost(204, 1)['total'],
    'Garrison budgets must use the real native weighted unit price');
  $now = 1700000000; $storage = new Storage($tables->sql());
  $storage->tick($now, $settings, 4, 1);
  $initial = $tables->rows("SELECT * FROM {$prefix}zero_galaxy_rifts ORDER BY rift_id");
  $check(count($initial) === 2, 'Four eligible systems must reserve only two globally capped great rifts');
  $check((int) $tables->scalar("SELECT COUNT(*) FROM {$prefix}planets") === 0, 'Energy reservations must not create planets early');
  $owner = (int) $tables->scalar("SELECT owner_id FROM {$prefix}zero_galaxy_npcs WHERE category_key = 'great'");
  $saved = array_column($initial, null, 'rift_id');
  $db->query("UPDATE {$prefix}zero_galaxy_state SET next_generation_at = " . ($now + 1));
  $storage->tick($now + 1, $future, 4, 1, true);
  $check((int) $tables->scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_rifts") === 2, 'Energy reservations must consume the global category cap');
  $storage->tick($now + 2, $future, 4, 1, true);
  $planets = [];
  foreach ($tables->rows("SELECT * FROM {$prefix}zero_galaxy_rifts ORDER BY rift_id") as $rift) {
    $id = (int) $rift['rift_id']; $planetId = (int) $rift['planet_id']; $planets[$id] = $planetId;
    $profile = Profile::read($rift); $planet = $tables->one("SELECT * FROM {$prefix}planets WHERE id = {$planetId}");
    $check((int) $rift['npc_owner_id'] === $owner && (int) $planet['id_owner'] === $owner,
      'Every great rift must use the same native research owner');
    $check($planet['name'] === RiftName::forRift($rift, 'active'), 'Native reports must use the saved category-qualified planet name');
    $stableFields = ['born_at', 'opens_at', 'unstable_at', 'closes_at', 'disappears_at', 'config_snapshot'];
    $check(array_intersect_key($rift, array_flip($stableFields)) === array_intersect_key($saved[$id], array_flip($stableFields)),
      'Hot settings must preserve the existing lifecycle, garrison and attack cap');
    foreach ([200 => 'fleet', 400 => 'defence'] as $type => $group) {
      $expected = $profile[$group]; ksort($expected);
      $check($unitMap($owner, 1, $planetId, $type) === $expected, 'Native ' . $group . ' must match its individual initial profile');
    }
    $check(!isset($profile['tech']) && $unitMap($owner, 4, $owner, 100) === [106 => 99, 109 => 99, 110 => 99, 111 => 99],
      'Shared NPC research must use current settings rather than individual random snapshots');
    $equivalent = $nativeCost = 0;
    foreach ([901 => 'metal', 902 => 'crystal', 903 => 'deuterium'] as $resource => $field) {
      $check((int) $planet[$field] === $profile['resources'][$resource], 'Native initial loot must match the snapshot: ' . $field);
      $equivalent += (float) $planet[$field] * $rates[$resource];
    }
    foreach ($profile['fleet'] + $profile['defence'] as $unitId => $count) { $nativeCost += eco_get_total_cost($unitId, $count)['total']; }
    $check(abs($nativeCost - $profile['garrison_points']) < 1 && $profile['fleet_limit'] === (int) floor($nativeCost * 1.25),
      'The attack cap must derive from actual initial native fleet and defence prices');
    $check($equivalent <= $profile['fleet_points'] * .9 + 1 && $equivalent > $profile['fleet_points'] * .6 - array_sum($rates) - 1,
      'Loot must retain the calibrated 60–90% range of initial actual fleet cost');
  }
  $firstId = (int) array_key_first($saved); $firstPlanet = $planets[$firstId];
  $unitId = (int) $tables->scalar("SELECT unit_id FROM {$prefix}unit WHERE unit_player_id = {$owner} AND unit_location_type = 1
    AND unit_location_id = {$firstPlanet} AND unit_type = 200 LIMIT 1");
  $db->query("UPDATE {$prefix}unit SET unit_level = 5 WHERE unit_id = {$unitId}");
  $db->query("DELETE FROM {$prefix}unit WHERE unit_player_id = {$owner} AND unit_location_type = 1
    AND unit_location_id = {$firstPlanet} AND unit_type = 400");
  $db->query("UPDATE {$prefix}planets SET metal = 7, crystal = 11, deuterium = 13, debris_metal = 21000, debris_crystal = 13000 WHERE id = {$firstPlanet}");
  $storage->tick($now + 5, $future, 4, 1, true);
  $changed = $tables->one("SELECT name,metal,crystal,deuterium,debris_metal,debris_crystal FROM {$prefix}planets WHERE id = {$firstPlanet}");
  $check((int) $tables->scalar("SELECT unit_level FROM {$prefix}unit WHERE unit_id = {$unitId}") === 5
    && (int) $tables->scalar("SELECT COUNT(*) FROM {$prefix}unit WHERE unit_location_type = 1 AND unit_location_id = {$firstPlanet} AND unit_type = 400") === 0,
    'Repeated ticks and phase changes must not refill destroyed garrisons');
  $check($changed['name'] === RiftName::forRift($saved[$firstId], 'unstable')
    && array_map('intval', array_diff_key($changed, ['name' => true])) === [
      'metal' => 7, 'crystal' => 11, 'deuterium' => 13, 'debris_metal' => 21000, 'debris_crystal' => 13000],
    'Instability must retain depleted resources and current orbital debris on the same planet');

  $db->query("INSERT INTO {$prefix}users (username,password,galaxy) VALUES ('Unrelated pilot','!disabled!',1)");
  $human = (int) $db->insert_id;
  $flights = [];
  foreach ([[6,0], [8,0], [8,1]] as [$mission, $returning]) {
    $db->query("INSERT INTO {$prefix}fleets
      (fleet_owner,fleet_target_owner,fleet_mission,fleet_end_planet_id,fleet_end_galaxy,fleet_end_system,fleet_end_planet,
       fleet_mess,fleet_start_time,fleet_end_time,fleet_resource_metal,fleet_resource_crystal)
      VALUES ({$human},{$owner},{$mission},{$firstPlanet},0," . (int) $saved[$firstId]['system'] . ",1,{$returning},"
        . ($now + 1000) . ',' . ($now + 2000) . ',' . ($returning * 700) . ',' . ($returning * 300) . ')');
    $id = (int) $db->insert_id; $flights[$id] = $tables->one("SELECT * FROM {$prefix}fleets WHERE fleet_id = {$id}");
  }
  $db->query("UPDATE {$prefix}zero_galaxy_state SET next_generation_at = " . ($now + 9));
  $storage->tick($now + 9, $future, 4, 1, true);
  $check((int) $tables->scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_rifts") === 2
    && (int) $tables->scalar("SELECT COUNT(*) FROM {$prefix}planets") === 0,
    'Closed traces must reserve the category cap while deleting planets and debris');
  $check((int) $tables->scalar("SELECT COUNT(*) FROM {$prefix}unit WHERE unit_location_type = 1") === 0,
    'Closure must delete only the vanished planets’ garrisons');
  foreach ($flights as $id => $before) {
    $before['fleet_end_planet_id'] = null;
    $check($tables->one("SELECT * FROM {$prefix}fleets WHERE fleet_id = {$id}") === $before,
      'Closure must preserve spies, inbound recyclers and returning recyclers with cargo');
  }
  $storage->tick($now + 11, $paused, 4, 1, true);
  $check((int) $tables->scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_rifts") === 0
    && (int) $tables->scalar("SELECT COUNT(*) FROM {$prefix}zero_galaxy_npcs") === 7,
    'Final expiration must release reservations while retaining category NPCs');
  $db->query("UPDATE {$prefix}zero_galaxy_state SET next_generation_at = " . ($now + 12));
  $storage->tick($now + 12, $future, 4, 1, true);
  $replacement = $tables->rows("SELECT * FROM {$prefix}zero_galaxy_rifts ORDER BY rift_id");
  $check(count($replacement) === 2, 'Released capacity must permit two replacement rifts');
  foreach ($replacement as $rift) {
    $profile = Profile::read($rift);
    $check(!isset($saved[(int) $rift['rift_id']]) && (int) $rift['npc_owner_id'] === $owner,
      'New rift generations must have new identities and reuse the category owner');
    $check($profile['category']['name'] === 'Обновлённый Великий' && $profile['target_points'] === 134077480581000
      && array_sum($profile['resources']) === 0, 'Only new garrisons must receive changed category balance');
  }
  echo "Population: {$assertions} assertions passed; shared owners and native garrisons checked.\n";
} finally {
  $tables->drop();
  $db->close();
}
