<?php

require __DIR__ . '/support/bootstrap.php';
zgCoreConstants();
require __DIR__ . '/support/database.php';
require __DIR__ . '/support/arrival.php';

use ZeroGalaxy\RiftMissionEvent;

class HelperString { public static function numberFloorAndFormat($value) { return (string) floor($value); } }
function sn_get_groups($group) { return $group === 'fleet' ? [202, 209] : [209]; }
function get_unit_param($id, $parameter) { return $id === 209 ? 100 : 50; }
function msg_send_simple_message(...$arguments) { global $messages; $messages[] = $arguments; }
$lang = ['sys_recycler_gotten' => '%s %s; %s %s', 'Metal' => 'Metal', 'Crystal' => 'Crystal',
  'sys_mess_spy_control' => 'Command', 'sys_recycler_report' => 'Recycle report'];
$messages = [];

$worker = ($argv[1] ?? '') === '--collect-worker';
$tables = new ZgTables(zgDb(true), 'recycle', $worker ? ($argv[2] ?? '') : null);
$db = $tables->db;
$prefix = $tables->prefix;
zgArrivalDb($tables);
$one = function (string $table, string $condition = '1=1') use ($tables, $prefix) {
  return $tables->one("SELECT * FROM {$prefix}{$table} WHERE {$condition}");
};
$native = function (int $id = 77) use ($one): \Fleet\FleetDispatchEvent {
  return zgArrivalEvent($one('fleets', 'fleet_id=' . $id), MT_RECYCLE);
};
$run = function (int $id = 77, bool $metadata = true, $event = null) use ($native): RiftMissionEvent {
  $wrapped = new RiftMissionEvent($event ?? $native($id), $metadata);
  $wrapped->lockEventRecords();
  $wrapped->refreshFleet();
  if ((int) $wrapped->fleet['fleet_mess'] !== FLEET_STATUS_FLYING) { return $wrapped; }
  $wrapped->refreshMissionData();
  if ($wrapped->missionId === MT_RECYCLE) {
    require_once SN_ROOT_PHYSICAL . 'includes/includes/flt_mission_recycle.php';
    flt_mission_recycle($wrapped);
  }
  return $wrapped;
};
$reset = function (int $metal = 600, int $crystal = 400) use ($db, $prefix, &$messages) {
  $db->rollback();
  $db->begin_transaction();
  $db->query("UPDATE {$prefix}planets SET debris_metal={$metal},debris_crystal={$crystal} WHERE id=20");
  $db->query("UPDATE {$prefix}fleets SET fleet_mess=0,fleet_group=0,fleet_array='209,2',fleet_end_type=2,
    fleet_resource_metal=0,fleet_resource_crystal=0,fleet_resource_deuterium=0");
  $messages = [];
};

if ($worker) {
  // Native event discovery happens outside its transaction. Under SERIALIZABLE
  // even a plain discovery SELECT would otherwise lock the fleet before state.
  $discovered = $native((int) ($argv[3] ?? 0));
  $db->begin_transaction();
  echo "READY\n";
  fflush(STDOUT);
  $run(0, true, $discovered);
  $db->commit();
  echo "COLLECTED\n";
  exit;
}

try {
  $db->query("CREATE TABLE {$prefix}zero_galaxy_state (id INT PRIMARY KEY) ENGINE=InnoDB");
  $db->query("INSERT INTO {$prefix}zero_galaxy_state VALUES (1)");
  $db->query("CREATE TABLE {$prefix}zero_galaxy_rifts (rift_id INT PRIMARY KEY, planet_id INT, npc_owner_id INT,
    system INT, position INT, born_at BIGINT, opens_at BIGINT, unstable_at BIGINT, closes_at BIGINT, disappears_at BIGINT) ENGINE=InnoDB");
  $db->query("INSERT INTO {$prefix}zero_galaxy_rifts VALUES (1,20,3,3,5," . (SN_TIME_NOW-100) . ',' . (SN_TIME_NOW-50)
    . ',' . (SN_TIME_NOW+10) . ',' . (SN_TIME_NOW+20) . ',' . (SN_TIME_NOW+30) . ')');
  $db->query("CREATE TABLE {$prefix}users (id INT PRIMARY KEY, user_bot INT, authlevel INT,
    parent_account_id INT, parent_account_global INT) ENGINE=InnoDB");
  $db->query("INSERT INTO {$prefix}users VALUES (1,0,0,1,0),(2,200,0,0,0),(3,200,0,0,0),(4,200,0,0,0)");
  $db->query("CREATE TABLE {$prefix}planets (id INT PRIMARY KEY, id_owner INT, galaxy INT, system INT, planet INT,
    planet_type INT, debris_metal DECIMAL(20,2), debris_crystal DECIMAL(20,2), last_update BIGINT DEFAULT 0) ENGINE=InnoDB");
  $db->query("INSERT INTO {$prefix}planets VALUES (10,1,1,2,3,1,1000,500,0),(20,3,0,3,5,1,600,400,0)");
  $db->query("CREATE TABLE {$prefix}fleets (fleet_id INT PRIMARY KEY, fleet_owner INT, fleet_mission INT, fleet_mess INT,
    fleet_group INT, fleet_array TEXT, fleet_start_time BIGINT, fleet_end_time BIGINT,
    fleet_end_galaxy INT, fleet_end_system INT, fleet_end_planet INT, fleet_end_type INT,
    fleet_resource_metal DECIMAL(20,2),fleet_resource_crystal DECIMAL(20,2),fleet_resource_deuterium DECIMAL(20,2),
    fleet_end_planet_id INT NULL, FOREIGN KEY (fleet_end_planet_id) REFERENCES {$prefix}planets(id) ON DELETE SET NULL) ENGINE=InnoDB");
  $db->query("INSERT INTO {$prefix}fleets VALUES (77,1,8,0,0,'209,2',1700000000,1700000100,0,3,5,2,0,0,0,20),
    (78,1,8,0,0,'209,2',1700000000,1700000100,0,3,5,2,0,0,0,20)");

  $reset();
  $riftBefore = $one('zero_galaxy_rifts');
  $event = $run();
  $fleet = $one('fleets', 'fleet_id=77');
  $planet = $one('planets', 'id=20');
  $check((float) $fleet['fleet_resource_metal'] === 100.0 && (float) $fleet['fleet_resource_crystal'] === 100.0,
    'Native partial collection must split recycler capacity between both debris resources');
  $check((float) $planet['debris_metal'] === 500.0 && (float) $planet['debris_crystal'] === 300.0,
    'Collected cargo must be subtracted from the native planet debris');
  $check((int) $fleet['fleet_mess'] === 1 && (int) $fleet['fleet_mission'] === MT_RECYCLE
    && $event->missionId === MT_RECYCLE, 'Native dispatcher retains the ordinary recycle mission and return');
  $check(count($messages) === 1 && $messages[0][3] === MSG_TYPE_RECYCLE, 'Native recycler report must be generated once');
  $check($one('zero_galaxy_rifts') === $riftBefore, 'Recycling must not destabilize or reschedule a rift');

  $reset();
  $db->query("UPDATE {$prefix}zero_galaxy_rifts SET closes_at=" . SN_TIME_NOW . ',unstable_at=' . (SN_TIME_NOW-10));
  $run();
  $check((float) $one('planets','id=20')['debris_metal'] === 500.0 && count($messages) === 1,
    'A closing deadline must not change native collection while the planet still exists');
  $reset();
  $run(77,false);
  $check((float) $one('planets','id=20')['debris_metal'] === 500.0 && count($messages) === 1,
    'Native collection must remain available without module metadata');

  $reset();
  $beforeDeletion = $one('fleets','fleet_id=77');
  $db->query("DELETE FROM {$prefix}planets WHERE id=20");
  $beforeDeletion['fleet_end_planet_id'] = null;
  $check($one('fleets','fleet_id=77') === $beforeDeletion,
    'Deleting a planet must retain inbound recycler composition, cargo and flight times');
  $run();
  $fleet = $one('fleets','fleet_id=77');
  $check((int) $fleet['fleet_mess'] === 1 && (float) $fleet['fleet_resource_metal'] === 0.0
    && (float) $fleet['fleet_resource_crystal'] === 0.0 && !$messages,
    'Native arrival at empty coordinates must turn back without collection or a report');

  $reset();
  $db->query("DELETE FROM {$prefix}planets WHERE id=20");
  $db->query("INSERT INTO {$prefix}planets VALUES (21,4,0,3,5,1,600,400,0)");
  $db->query("UPDATE {$prefix}zero_galaxy_rifts SET planet_id=21,npc_owner_id=4");
  $event=$run();
  $check((int) $event->dstPlanetId===21 && in_array(21,$event->planetIdsToLock,true)
    && in_array(4,$event->userIdsToLock,true), 'Replacement target and owner must join native locks before refreshed collection');
  $check((float) $one('planets','id=21')['debris_metal']===500.0,
    'A replacement at the coordinates must yield its current debris regardless of the old rift');

  $reset();
  $run();
  $collected=$one('fleets','fleet_id=77');
  $db->query("DELETE FROM {$prefix}planets WHERE id=20");
  $collected['fleet_end_planet_id']=null;
  $check($one('fleets','fleet_id=77')===$collected, 'Native target FK must clear after closing while retaining returning cargo and timestamps');
  $run();
  $check($one('fleets','fleet_id=77')===$collected,
    'Already returning fleets must never be collected or redirected a second time');

  $reset(150,150);
  $db->commit();
  $db->begin_transaction();
  $db->query("SELECT id FROM {$prefix}zero_galaxy_state WHERE id=1 FOR UPDATE");
  $workers=[];
  foreach ([77,78] as $fleetId) {
    $workers[] = $worker = zgWorker(['--collect-worker',$prefix,(string) $fleetId]);
    fclose($worker[1][0]);
    $check(trim((string) fgets($worker[1][1]))==='READY', 'Both processes must reach the lifecycle lock before collecting');
  }
  $db->commit();
  foreach ($workers as $worker) {
    [$code,$output,$errors] = zgFinish($worker);
    $check($code===0 && trim($output)==='COLLECTED' && $errors==='',
      'Competing SERIALIZABLE arrivals must finish without deadlock: ' . $errors);
  }
  $planet=$one('planets','id=20');
  $first=$one('fleets','fleet_id=77');$second=$one('fleets','fleet_id=78');
  $total=(float) $first['fleet_resource_metal']+(float) $first['fleet_resource_crystal']
    +(float) $second['fleet_resource_metal']+(float) $second['fleet_resource_crystal'];
  $check($total===300.0 && (float) $planet['debris_metal']===0.0 && (float) $planet['debris_crystal']===0.0,
    'Two actual competing processes must conserve the finite debris without negative balances or duplicate cargo');
} finally {
  $tables->drop();
  $db->close();
}

echo "Zero Galaxy recycling arrivals: {$assertions} assertions passed; native collection and isolated SQL.\n";
