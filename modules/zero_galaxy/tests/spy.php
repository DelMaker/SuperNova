<?php

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

$root = dirname(__DIR__, 3) . '/';
define('SN_ROOT_PHYSICAL', $root);
define('SN_TIME_NOW', 1700000000);
define('PT_NONE', 0);
define('PT_PLANET', 1);
define('PT_DEBRIS', 2);
define('PT_MOON', 3);
define('LOC_PLANET', 1);
foreach (['NONE' => 0, 'ATTACK' => 1, 'AKS' => 2, 'TRANSPORT' => 3, 'RELOCATE' => 4,
  'HOLD' => 5, 'SPY' => 6, 'COLONIZE' => 7, 'RECYCLE' => 8, 'DESTROY' => 9,
  'MISSILE' => 10, 'EXPLORE' => 15] as $name => $value) {
  define('MT_' . $name, $value);
}
require_once $root . 'includes/constants/constants_units.php';
require_once $root . 'classes/Common/Traits/TJsonSerializable.php';
require_once $root . 'classes/Fleet/MissionData.php';
require_once $root . 'classes/Fleet/MissionEspionageReport.php';
require_once $root . 'classes/Fleet/MissionEspionage.php';
require_once $root . 'classes/Fleet/FleetDispatchEvent.php';
require_once $root . 'classes/Planet/DBStaticPlanet.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Storage.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/RiftEspionage.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/RiftSpyEvent.php';

use Fleet\FleetDispatchEvent;
use Fleet\MissionEspionageReport;
use ZeroGalaxy\RiftEspionage;
use ZeroGalaxy\RiftSpyEvent;

// Only the unit-storage and owner lookups are fixtures. The native report,
// visibility thresholds and detection methods below are the actual game code.
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

$fixtureUnits = [];
$fixtureOwners = [];
function GetSpyLevel(&$user) {
  return $user['spy_level'];
}
function sys_unit_str2arr($text) {
  $result = [];
  foreach (explode(';', $text) as $part) {
    $pair = explode(',', $part);
    $result[(int) $pair[0]] = (int) $pair[1];
  }
  return $result;
}
function sn_get_groups($group) {
  return $group === 'fleet' ? [SHIP_SMALL_FIGHTER_LIGHT] : [];
}
function mrc_get_level(&$user, $planet, $unit, $update = false, $plain = false) {
  global $fixtureUnits;
  if (in_array($unit, [TECH_SPY, TECH_WEAPON, TECH_SHIELD, TECH_ARMOR], true)) {
    return $user['tech'][$unit] ?? 0;
  }
  return $fixtureUnits[(int) $planet['id']][$unit] ?? 0;
}
function db_user_by_id($ownerId) {
  global $fixtureOwners;
  return $fixtureOwners[$ownerId] ?? null;
}

class SpyFixtureGeneral {
  public function getGroupsByName($group) {
    return [
      'fleet' => [SHIP_SMALL_FIGHTER_LIGHT],
      'defense' => [UNIT_DEF_TURRET_LASER_SMALL],
      'structures' => [STRUC_MINE_METAL],
      'tech' => [TECH_SPY, TECH_WEAPON, TECH_SHIELD, TECH_ARMOR],
    ][$group];
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

class SpyFixtureMission extends RiftEspionage {
  public $scanned = [];

  public function __construct(int $attackerLevel, int $defenderLevel, int $probes, int $satellites, int $ships) {
    global $fixtureUnits;
    $this->general = new SpyFixtureGeneral();
    $this->fleet = ['fleet_end_time' => SN_TIME_NOW, 'fleet_array' => SHIP_SPY . ',' . $probes];
    $this->fleetOwnerRow = ['id' => 1, 'username' => 'Attacker', 'ally_tag' => '', 'spy_level' => $attackerLevel];
    $this->dstUserRow = ['id' => 2, 'username' => 'NPC', 'ally_tag' => '', 'spy_level' => $defenderLevel,
      'tech' => [TECH_SPY => $defenderLevel, TECH_WEAPON => 27, TECH_SHIELD => 25, TECH_ARMOR => 26]];
    $this->srcPlanetRow = ['id' => 10, 'name' => 'Home', 'galaxy' => 1, 'system' => 2, 'planet' => 3, 'planet_type' => PT_PLANET];
    $this->dstPlanetRow = ['id' => 20, 'id_owner' => 2, 'name' => 'Rift', 'galaxy' => 0, 'system' => 3,
      'planet' => 5, 'planet_type' => PT_PLANET, 'metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'energy_max' => 0];
    $fixtureUnits[20] = [SHIP_SMALL_FIGHTER_LIGHT => $ships, SHIP_SATELLITE_SPUTNIK => $satellites,
      UNIT_DEF_TURRET_LASER_SMALL => 30, STRUC_MINE_METAL => 17];
  }

  protected function scanGroup($group) {
    $this->scanned[] = $group;
    parent::scanGroup($group);
  }

  public function run(): MissionEspionageReport {
    $this->doSpying();
    return $this->missionReport;
  }
}

$assertions = 0;
$check = function ($condition, $message) use (&$assertions) {
  $assertions++;
  if (!$condition) {
    throw new RuntimeException($message);
  }
};

foreach ([
  [10, 20, 9, 0, 40, ['fleet', 'tech']],
  [10, 20, 16, 0, 40, ['fleet', 'defense', 'tech']],
  [10, 20, 36, 0, 40, ['fleet', 'defense', 'structures', 'tech']],
  [10, 20, 121, 100, 40, ['fleet', 'defense', 'structures', 'tech']],
  [10, 21, 9, 0, 40, []],
  [0, 0, 1, 0, 0, ['tech']],
] as $case) {
  [$attacker, $defender, $probes, $satellites, $ships, $expectedGroups] = $case;
  $mission = new SpyFixtureMission($attacker, $defender, $probes, $satellites, $ships);
  $report = $mission->run();
  $check($mission->scanned === $expectedGroups, 'Half concealment must select the expected groups at exact thresholds');
  $check($report->targetSpyLevel === $defender && $report->attackerSpyLevel === $attacker, 'Report must retain full actual spy levels');
  $check($report->getEmpireSpyDiff() === $attacker - $defender, 'Native empire difference must remain unchanged');
  $check($report->getDetectionTrashold() == $probes * $ships / 4 * pow(2, $defender - $attacker), 'Detection threshold must use full defender technology');
  $native = MissionEspionageReport::fromJson(json_encode($report));
  $check($report->getDetectionTrashold() === $native->getDetectionTrashold(), 'Serialized native report must preserve the exact detection threshold');
  foreach ([0, 25, 99] as $roll) {
    $report->rolledChance = $native->rolledChance = $roll;
    $check($report->isSpyDetected() === $native->isSpyDetected(), 'Probe losses must match native detection for the same roll');
  }
  if (in_array('tech', $expectedGroups, true)) {
    $check($defender === 0 ? !isset($report->spiedUnits[TECH_SPY]) : (int) $report->spiedUnits[TECH_SPY] === $defender,
      'Revealed technology must not be halved');
    $check((int) $report->spiedUnits[TECH_WEAPON] === 27 && (int) $report->spiedUnits[TECH_SHIELD] === 25
      && (int) $report->spiedUnits[TECH_ARMOR] === 26, 'Revealed combat technologies must retain their real values');
  }
}

// Wrapping preserves all native public event state and the stored MT_SPY value.
$eventReflection = new ReflectionClass(FleetDispatchEvent::class);
$nativeEvent = $eventReflection->newInstanceWithoutConstructor();
$nativeEvent->fleet = ['fleet_id' => 77, 'fleet_mission' => MT_SPY, 'fleet_end_galaxy' => 0];
$nativeEvent->event = 'EVENT_FLT_ARRIVE';
$nativeEvent->missionId = MT_SPY;
$nativeEvent->fleetId = 77;
$nativeEvent->userIdsToLock = [1, 2];
$nativeEvent->planetIdsToLock = [10, 20];
$wrapped = new RiftSpyEvent($nativeEvent, 2);
foreach (get_object_vars($nativeEvent) as $key => $value) {
  $check($wrapped->$key === $value, 'Event wrapping must preserve native field ' . $key);
}
$check($wrapped->fleet['fleet_mission'] === MT_SPY, 'Wrapping must never change the persisted mission type');

if (getenv('SUPERNOVA_DB_HOST')) {
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  $db = spyTestDb();
  $prefix = 'zg_spy_test_' . bin2hex(random_bytes(5)) . '_';
  SN::$db = (object) ['db_prefix' => $prefix, 'link' => $db];
  $table = $prefix . 'zero_galaxy_rifts';
  $fixtureOwners = [2 => ['id' => 2, 'user_bot' => 200, 'authlevel' => 0, 'parent_account_id' => 0, 'parent_account_global' => 0]];
  $reflection = new ReflectionClass(RiftSpyEvent::class);
  $owned = $reflection->getMethod('isModuleTarget');
  $owned->setAccessible(true);
  $ownershipEvent = $reflection->newInstanceWithoutConstructor();
  $legacy = $reflection->getProperty('legacyOwnerId');
  $legacy->setAccessible(true);
  $legacy->setValue($ownershipEvent, 2);
  $moduleLocked = $reflection->getProperty('moduleRecordsLocked');
  $moduleLocked->setAccessible(true);
  $planet = ['id' => 20, 'id_owner' => 2, 'galaxy' => 0, 'system' => 3, 'planet' => 5, 'planet_type' => PT_PLANET];
  $ownershipEvent->dstPlanetRow = $planet;
  try {
    $db->query("CREATE TABLE {$table} (rift_id INT PRIMARY KEY, planet_id INT, npc_owner_id INT NULL,
      system INT, position INT, opens_at BIGINT, closes_at BIGINT) ENGINE=InnoDB");
    $db->query("INSERT INTO {$table} VALUES (1, 20, 2, 3, 5, " . (SN_TIME_NOW - 1) . ', ' . (SN_TIME_NOW + 1) . ')');
    $check(!$owned->invoke($ownershipEvent), 'The modifier must not read membership without acquiring module locks first');
    $moduleLocked->setValue($ownershipEvent, true);
    $check($owned->invoke($ownershipEvent), 'A live independently owned NPC rift must receive half concealment');
    $db->query("UPDATE {$table} SET npc_owner_id = NULL");
    $check($owned->invoke($ownershipEvent), 'Legacy shared-owner rifts must retain module ownership');
    $legacy->setValue($ownershipEvent, 9);
    $check(!$owned->invoke($ownershipEvent), 'Legacy null ownership must not match an unrelated NPC');
    $db->query("UPDATE {$table} SET npc_owner_id = 9");
    $check(!$owned->invoke($ownershipEvent), 'Another NPC owner must not receive the module modifier');
    $db->query("UPDATE {$table} SET npc_owner_id = 2, closes_at = " . SN_TIME_NOW);
    $check(!$owned->invoke($ownershipEvent), 'Closed targets must fall back to native handling');
    $db->query("UPDATE {$table} SET closes_at = " . (SN_TIME_NOW + 1));
    $ownershipEvent->dstPlanetRow['galaxy'] = 1;
    $check(!$owned->invoke($ownershipEvent), 'Ordinary galaxies must remain native');
    $ownershipEvent->dstPlanetRow = $planet;
    $fixtureOwners[2]['user_bot'] = 0;
    $check(!$owned->invoke($ownershipEvent), 'A human owner must never receive the module modifier');
    $fixtureOwners[2]['user_bot'] = 200;
    $db->query("DELETE FROM {$table}");
    $check(!$owned->invoke($ownershipEvent), 'A foreign planet at zero coordinates must remain native');
    $ownershipEvent->dstPlanetRow = null;
    $check(!$owned->invoke($ownershipEvent), 'A missing target must retain native probe-return handling');

    $db->query("CREATE TABLE {$prefix}zero_galaxy_state (id INT PRIMARY KEY) ENGINE=InnoDB");
    $db->query("INSERT INTO {$prefix}zero_galaxy_state VALUES (1)");
    $db->query("CREATE TABLE {$prefix}users (id INT PRIMARY KEY) ENGINE=InnoDB");
    $db->query("INSERT INTO {$prefix}users VALUES (1),(2),(3),(4)");
    $db->query("CREATE TABLE {$prefix}planets (id INT PRIMARY KEY, id_owner INT, galaxy INT,
      system INT, planet INT, planet_type INT) ENGINE=InnoDB");
    $db->query("INSERT INTO {$prefix}planets VALUES (10,1,1,2,3,1),(20,3,0,3,5,1)");
    $db->query("CREATE TABLE {$prefix}fleets (fleet_id INT PRIMARY KEY) ENGINE=InnoDB");
    $db->query("INSERT INTO {$prefix}fleets VALUES (77)");
    $db->query("INSERT INTO {$table} VALUES (1,20,3,3,5," . (SN_TIME_NOW - 1) . ',' . (SN_TIME_NOW + 1) . ')');
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
      'Taking locks must not change either event or persisted mission type');
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
