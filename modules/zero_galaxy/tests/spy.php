<?php

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

$root = dirname(__DIR__, 3) . '/';
define('SN_ROOT_PHYSICAL', $root);
define('SN_TIME_NOW', 1700000000);
define('PT_PLANET', 1);
define('PT_DEBRIS', 2);
define('LOC_PLANET', 1);
foreach (['NONE' => 0, 'ATTACK' => 1, 'AKS' => 2, 'TRANSPORT' => 3, 'RELOCATE' => 4,
  'HOLD' => 5, 'SPY' => 6, 'COLONIZE' => 7, 'RECYCLE' => 8, 'DESTROY' => 9,
  'MISSILE' => 10, 'EXPLORE' => 15] as $name => $value) {
  define('MT_' . $name, $value);
}
require_once __DIR__ . '/support/autoload.php';
require_once $root . 'classes/Fleet/FleetDispatchEvent.php';
require_once $root . 'classes/Planet/DBStaticPlanet.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Storage.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Lifecycle.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/RiftTarget.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/RiftMissionEvent.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/RiftSpyEvent.php';

use Fleet\FleetDispatchEvent;
use ZeroGalaxy\RiftSpyEvent;

class SN {
  public static $db;
  public static $gc;

  public static function db_get_record_list($location, $filter, $fetch = false) {
    $table = self::$db->db_prefix . 'planets';
    $filter = str_replace('{{planets}}', $table, $filter);
    $result = self::$db->link->query("SELECT * FROM {$table} WHERE {$filter} FOR UPDATE");
    return $fetch ? $result->fetch_assoc() : $result->fetch_all(MYSQLI_ASSOC);
  }
}

class SpyFixtureLocks {
  private $db;
  private $prefix;
  public function __construct(mysqli $db, string $prefix) {
    $this->db = $db;
    $this->prefix = $prefix;
  }
  public function lockRecords($locks) {
    foreach (['users', 'planets', 'fleets'] as $table) {
      if (!empty($locks[$table])) {
        $key = $table === 'fleets' ? 'fleet_id' : 'id';
        $ids = implode(',', array_map('intval', $locks[$table]));
        $this->db->query("SELECT {$key} FROM {$this->prefix}{$table} WHERE {$key} IN ({$ids}) FOR UPDATE");
      }
    }
    return true;
  }
}

function spyTestDb(): mysqli {
  $db = new mysqli(getenv('SUPERNOVA_DB_HOST'), getenv('SUPERNOVA_DB_USER'), getenv('SUPERNOVA_DB_PASSWORD'), getenv('SUPERNOVA_DB_NAME'));
  $db->query('SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE');
  $db->query('SET SESSION innodb_lock_wait_timeout = 3');
  return $db;
}

if (($argv[1] ?? '') === '--lifecycle-worker') {
  $prefix = $argv[2] ?? '';
  if (!preg_match('/^zg_spy_test_[a-f0-9]{10}_$/D', $prefix)) {
    throw new RuntimeException('Spy locking worker requires an isolated test prefix');
  }
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  $db = spyTestDb();
  $db->begin_transaction();
  $db->query("SELECT * FROM {$prefix}zero_galaxy_state WHERE id = 1 FOR UPDATE");
  $db->query("SELECT id FROM {$prefix}users WHERE id = 2 FOR UPDATE");
  $db->query("SELECT * FROM {$prefix}zero_galaxy_rifts FOR UPDATE");
  echo "LOCKED\n";
  fflush(STDOUT);
  if (trim((string) fgets(STDIN)) !== 'GO') {
    throw new RuntimeException('Locking worker did not receive its signal');
  }
  usleep(200000);
  $db->query("SELECT id FROM {$prefix}users WHERE id = 3 FOR UPDATE");
  $db->query("SELECT id FROM {$prefix}planets WHERE id = 20 FOR UPDATE");
  $db->commit();
  echo "COMMITTED\n";
  exit;
}

require __DIR__ . '/support/assertions.php';

$eventReflection = new ReflectionClass(FleetDispatchEvent::class);
if (getenv('SUPERNOVA_DB_HOST')) {
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  $db = spyTestDb();
  $prefix = 'zg_spy_test_' . bin2hex(random_bytes(5)) . '_';
  SN::$db = (object) ['db_prefix' => $prefix, 'link' => $db];
  $table = $prefix . 'zero_galaxy_rifts';
  try {
    $db->query("CREATE TABLE {$table} (rift_id INT PRIMARY KEY, planet_id INT, npc_owner_id INT NULL,
      system INT, position INT, born_at BIGINT, opens_at BIGINT, unstable_at BIGINT,
      closes_at BIGINT, disappears_at BIGINT) ENGINE=InnoDB");
    $timeline = (SN_TIME_NOW - 100) . ',' . (SN_TIME_NOW - 1) . ',' . SN_TIME_NOW . ',' . (SN_TIME_NOW + 1) . ',' . (SN_TIME_NOW + 100);
    $db->query("CREATE TABLE {$prefix}zero_galaxy_state (id INT PRIMARY KEY) ENGINE=InnoDB");
    $db->query("INSERT INTO {$prefix}zero_galaxy_state VALUES (1)");
    $db->query("CREATE TABLE {$prefix}users (id INT PRIMARY KEY) ENGINE=InnoDB");
    $db->query("INSERT INTO {$prefix}users VALUES (1),(2),(3),(4)");
    $db->query("CREATE TABLE {$prefix}planets (id INT PRIMARY KEY, id_owner INT, galaxy INT,
      system INT, planet INT, planet_type INT) ENGINE=InnoDB");
    $db->query("INSERT INTO {$prefix}planets VALUES (10,1,1,2,3,1),(20,3,0,3,5,1)");
    $db->query("CREATE TABLE {$prefix}fleets (fleet_id INT PRIMARY KEY) ENGINE=InnoDB");
    $db->query("INSERT INTO {$prefix}fleets VALUES (77)");
    $db->query("INSERT INTO {$table} VALUES (1,20,3,3,5,{$timeline})");
    SN::$gc = (object) ['db' => new SpyFixtureLocks($db, $prefix)];
    $event = $eventReflection->newInstanceWithoutConstructor();
    $event->fleet = ['fleet_id' => 77, 'fleet_mission' => MT_SPY, 'fleet_end_galaxy' => 0,
      'fleet_end_system' => 3, 'fleet_end_planet' => 5, 'fleet_end_type' => PT_PLANET];
    $event->event = 'EVENT_FLT_ARRIVE';
    $event->missionId = MT_SPY;
    $event->fleetId = 77;
    $event->userIdsToLock = [1,3];
    $event->planetIdsToLock = [10,20];
    $event->fleetIdsToLock = [77];

    $nativeDb = SN::$db;
    SN::$db = (object) ['db_prefix' => $prefix, 'link' => new class {
      public $error = 'Synthetic transaction deadlock';
      public $errno = 1213;
      public function query($sql) { return false; }
    }];
    try {
      (new RiftSpyEvent($event, 2))->lockEventRecords();
      $check(false, 'A failed lock must never fall through to native espionage');
    } catch (RuntimeException $error) {
      $check($error->getCode() === 1213, 'A failed shared lock must propagate its database error and stop processing');
    } finally { SN::$db = $nativeDb; }

    $worker = proc_open([PHP_BINARY, __FILE__, '--lifecycle-worker', $prefix],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($worker)) {
      throw new RuntimeException('Could not start lifecycle locking worker');
    }
    $check(trim((string) fgets($pipes[1])) === 'LOCKED', 'Lifecycle worker must hold state and rifts first');
    $db->begin_transaction();
    fwrite($pipes[0], "GO\n");
    fflush($pipes[0]);
    // This is the actual module/native lockEventRecords implementation. It
    // must wait for state BEFORE taking user 3, letting lifecycle commit.
    $concurrentEvent = new RiftSpyEvent($event, 2);
    $check($concurrentEvent->lockEventRecords(), 'Concurrent native event must acquire its records without deadlock');
    $db->commit();
    fclose($pipes[0]);
    $workerOutput = stream_get_contents($pipes[1]);
    $workerErrors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $check(proc_close($worker) === 0 && strpos($workerOutput, 'COMMITTED') !== false,
      'Lifecycle must commit under SERIALIZABLE: ' . $workerOutput . $workerErrors);

    // Metadata can point to a new planet/owner while an event snapshot still
    // contains the previous IDs. Both current records must enter native locks.
    $db->query("DELETE FROM {$prefix}planets WHERE id = 20");
    $db->query("INSERT INTO {$prefix}planets VALUES (21,4,0,3,5,1)");
    $db->query("UPDATE {$table} SET planet_id = 21, npc_owner_id = 4");
    $db->begin_transaction();
    $replacementEvent = new RiftSpyEvent($event, 2);
    $replacementEvent->lockEventRecords();
    $check(in_array(21, $replacementEvent->planetIdsToLock, true)
      && in_array(4, $replacementEvent->userIdsToLock, true), 'Replacement target IDs must enter the native lock set');
    $check($replacementEvent->dstPlanetId === 21 && $replacementEvent->dstPlanetOwnerId === 4,
      'Current target must be refreshed after acquiring native locks');
    $check($replacementEvent->missionId === MT_SPY && $replacementEvent->fleet['fleet_mission'] === MT_SPY,
      'Refreshed spy arrivals must retain their native mission');
    $db->rollback();
  } finally {
    $db->rollback();
    foreach (['zero_galaxy_state', 'fleets', 'planets', 'users'] as $extra) {
      $db->query("DROP TABLE IF EXISTS {$prefix}{$extra}");
    }
    $db->query("DROP TABLE IF EXISTS {$table}");
    $db->close();
  }
} else {
  echo "Spy metadata integration tests skipped: SUPERNOVA_DB_HOST is not set\n";
}

echo "Zero Galaxy spy: {$assertions} assertions passed\n";
