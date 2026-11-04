<?php

require __DIR__ . '/support/bootstrap.php';
zgCoreConstants();
require __DIR__ . '/support/database.php';
require __DIR__ . '/support/arrival.php';

use ZeroGalaxy\RiftMissionEvent;

if (($argv[1] ?? '') === '--lifecycle-worker') {
  $tables = new ZgTables(zgDb(true), 'spy', $argv[2] ?? '');
  $prefix = $tables->prefix;
  $tables->db->begin_transaction();
  $tables->db->query("SELECT * FROM {$prefix}zero_galaxy_state WHERE id = 1 FOR UPDATE");
  $tables->db->query("SELECT id FROM {$prefix}users WHERE id = 2 FOR UPDATE");
  $tables->db->query("SELECT * FROM {$prefix}zero_galaxy_rifts FOR UPDATE");
  echo "LOCKED\n";
  fflush(STDOUT);
  if (trim((string) fgets(STDIN)) !== 'GO') {
    throw new RuntimeException('Locking worker did not receive its signal');
  }
  usleep(200000);
  $tables->db->query("SELECT id FROM {$prefix}users WHERE id = 3 FOR UPDATE");
  $tables->db->query("SELECT id FROM {$prefix}planets WHERE id = 20 FOR UPDATE");
  $tables->db->commit();
  echo "COMMITTED\n";
  exit;
}

$tables = new ZgTables(zgDb(true), 'spy');
$db = $tables->db;
$prefix = $tables->prefix;
zgArrivalDb($tables);
try {
  $db->query("CREATE TABLE {$prefix}zero_galaxy_rifts (rift_id INT PRIMARY KEY, planet_id INT, npc_owner_id INT NULL,
    system INT, position INT, born_at BIGINT, opens_at BIGINT, unstable_at BIGINT,
    closes_at BIGINT, disappears_at BIGINT) ENGINE=InnoDB");
  $db->query("CREATE TABLE {$prefix}zero_galaxy_state (id INT PRIMARY KEY) ENGINE=InnoDB");
  $db->query("INSERT INTO {$prefix}zero_galaxy_state VALUES (1)");
  $db->query("CREATE TABLE {$prefix}users (id INT PRIMARY KEY) ENGINE=InnoDB");
  $db->query("INSERT INTO {$prefix}users VALUES (1),(2),(3),(4)");
  $db->query("CREATE TABLE {$prefix}planets (id INT PRIMARY KEY, id_owner INT, galaxy INT,
    system INT, planet INT, planet_type INT) ENGINE=InnoDB");
  $db->query("INSERT INTO {$prefix}planets VALUES (10,1,1,2,3,1),(20,3,0,3,5,1)");
  $db->query("CREATE TABLE {$prefix}fleets (fleet_id INT PRIMARY KEY) ENGINE=InnoDB");
  $db->query("INSERT INTO {$prefix}fleets VALUES (77)");
  $timeline = (SN_TIME_NOW - 100) . ',' . (SN_TIME_NOW - 1) . ',' . SN_TIME_NOW . ',' . (SN_TIME_NOW + 1) . ',' . (SN_TIME_NOW + 100);
  $db->query("INSERT INTO {$prefix}zero_galaxy_rifts VALUES (1,20,3,3,5,{$timeline})");
  $event = zgArrivalEvent(['fleet_id' => 77, 'fleet_mission' => MT_SPY, 'fleet_end_galaxy' => 0,
    'fleet_end_system' => 3, 'fleet_end_planet' => 5, 'fleet_end_type' => PT_PLANET], MT_SPY);

  $nativeDb = SN::$db;
  SN::$db = (object) ['db_prefix' => $prefix, 'link' => new class {
    public $error = 'Synthetic transaction deadlock';
    public $errno = 1213;
    public function query($sql) { return false; }
  }];
  try {
    $error = zgExpect(function () use ($event) { (new RiftMissionEvent($event))->lockEventRecords(); },
      RuntimeException::class, 'A failed lock must never fall through to native espionage');
    $check($error && $error->getCode() === 1213, 'A failed shared lock must propagate its database error and stop processing');
  } finally { SN::$db = $nativeDb; }

  $worker = zgWorker(['--lifecycle-worker', $prefix]);
  $check(trim((string) fgets($worker[1][1])) === 'LOCKED', 'Lifecycle worker must hold state and rifts first');
  $db->begin_transaction();
  fwrite($worker[1][0], "GO\n");
  fflush($worker[1][0]);
  // This is the actual module/native lockEventRecords implementation. It
  // must wait for state BEFORE taking user 3, letting lifecycle commit.
  $check((new RiftMissionEvent($event))->lockEventRecords(), 'Concurrent native event must acquire its records without deadlock');
  $db->commit();
  [$code, $output, $errors] = zgFinish($worker);
  $check($code === 0 && strpos($output, 'COMMITTED') !== false, 'Lifecycle must commit under SERIALIZABLE: ' . $output . $errors);

  // Metadata can point to a new planet/owner while an event snapshot still
  // contains the previous IDs. Both current records must enter native locks.
  $db->query("DELETE FROM {$prefix}planets WHERE id = 20");
  $db->query("INSERT INTO {$prefix}planets VALUES (21,4,0,3,5,1)");
  $db->query("UPDATE {$prefix}zero_galaxy_rifts SET planet_id = 21, npc_owner_id = 4");
  $db->begin_transaction();
  $replacementEvent = new RiftMissionEvent($event);
  $replacementEvent->lockEventRecords();
  $check(in_array(21, $replacementEvent->planetIdsToLock, true)
    && in_array(4, $replacementEvent->userIdsToLock, true), 'Replacement target IDs must enter the native lock set');
  $check($replacementEvent->dstPlanetId === 21 && $replacementEvent->dstPlanetOwnerId === 4,
    'Current target must be refreshed after acquiring native locks');
  $check($replacementEvent->missionId === MT_SPY && $replacementEvent->fleet['fleet_mission'] === MT_SPY,
    'Refreshed spy arrivals must retain their native mission');
  $db->rollback();
} finally {
  $tables->drop();
  $db->close();
}

echo "Zero Galaxy spy: {$assertions} assertions passed\n";
