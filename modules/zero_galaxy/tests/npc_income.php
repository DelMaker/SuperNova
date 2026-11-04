<?php

require __DIR__ . '/support/bootstrap.php';
require __DIR__ . '/support/database.php';
zgGameRequest('zero-galaxy-npc-income-test');
require getcwd() . '/common.php';

use DBAL\db_mysql;
use Fleet\FleetDispatchEvent;
use ZeroGalaxy\RiftMissionEvent;
use ZeroGalaxy\Settings;
use ZeroGalaxy\Storage;

$tables = new ZgTables(SN::$db->link, 'npc_income');
$db = $tables->db;
$prefix = $tables->prefix;
try {
  $tables->cloneGame(['users', 'planets', 'unit', 'fleets', 'que'], SN::$db->db_prefix);
  $tables->useInGame();
  $settings = Settings::load(['target_active_per_system' => 0]);
  $storage = new Storage($tables->sql());
  $then = SN_TIME_NOW - 10;
  $storage->tick($then, $settings, 1, 1, true);
  $npc = (int) $tables->scalar("SELECT owner_id FROM {$prefix}zero_galaxy_npcs WHERE category_key = 'tiny'");
  $snapshot = $db->real_escape_string(zgSnapshot(['resources' => [901 => 7, 902 => 11, 903 => 13]]));
  $db->query("INSERT INTO {$prefix}zero_galaxy_rifts
    (system,position,born_at,opens_at,unstable_at,closes_at,disappears_at,image,config_snapshot,npc_owner_id)
    VALUES (1,1," . ($then - 100) . ',' . ($then - 90) . ',' . ($then + 1000) . ','
      . ($then + 2000) . ',' . ($then + 3000) . ",'normaltempplanet01','{$snapshot}',{$npc})");
  $storage->tick($then, $settings, 1, 1, true);
  $target = (int) $tables->scalar("SELECT planet_id FROM {$prefix}zero_galaxy_rifts WHERE system = 1 AND position = 1");
  $db->query("INSERT INTO {$prefix}users (username,password,galaxy) VALUES ('Income control','!disabled!',1)");
  $pilot = (int) $db->insert_id;
  $db->query("INSERT INTO {$prefix}planets (name,id_owner,galaxy,system,planet,planet_type,last_update)
    VALUES ('Income control',{$pilot},1,1,1,1,{$then})");
  $source = (int) $db->insert_id;
  $check(!$storage->tick(SN_TIME_NOW, $settings, 1, 1), 'Arrival between ticks must not need a world reconciliation');

  $event = (new ReflectionClass(FleetDispatchEvent::class))->newInstanceWithoutConstructor();
  $event->fleet = ['fleet_end_galaxy' => 0, 'fleet_end_system' => 1, 'fleet_end_planet' => 1, 'fleet_end_type' => PT_PLANET];
  $event->srcPlanetId = $source;
  $event->fleetOwnerId = $pilot;
  $event->eventTimeStamp = SN_TIME_NOW;
  $event->userIdsToLock = [$pilot, $npc];
  $event->planetIdsToLock = [$source, $target];
  $refresh = function () use ($event): void {
    db_mysql::db_transaction_start();
    try {
      $arrival = new RiftMissionEvent($event);
      $arrival->lockEventRecords();
      $arrival->refreshMissionData();
      db_mysql::db_transaction_commit();
    } catch (Throwable $error) {
      db_mysql::db_transaction_rollback();
      throw $error;
    }
  };
  $resources = function (int $id) use ($tables, $prefix): array {
    return array_map('floatval', $tables->one("SELECT metal,crystal,deuterium FROM {$prefix}planets WHERE id = {$id}"));
  };
  $before = $resources($target);
  $refresh();
  $check($resources($target) === $before, 'Native arrival refresh must not produce NPC resources between scheduled ticks');
  $check(array_sum($resources($source)) > 0, 'The same native refresh must retain ordinary source planet production');

  $event->eventTimeStamp = SN_TIME_NOW - 1;
  $refresh();
  $check((int) $tables->scalar("SELECT last_update FROM {$prefix}planets WHERE id = {$target}") === SN_TIME_NOW,
    'An older arrival must never move the NPC income clock backwards');
  $db->query("UPDATE {$prefix}users SET user_bot = 0 WHERE id = {$npc}");
  $db->query("UPDATE {$prefix}planets SET last_update = {$then} WHERE id = {$target}");
  $event->eventTimeStamp = SN_TIME_NOW;
  $refresh();
  $check(array_sum($resources($target)) > array_sum($before), 'An ordinary player at the coordinates must keep native production');
  echo "NPC income: {$assertions} assertions passed; real native economy, isolated SQL tables.\n";
} finally {
  $tables->restoreGame();
  $tables->drop();
}
