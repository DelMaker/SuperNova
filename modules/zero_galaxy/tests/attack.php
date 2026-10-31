<?php

namespace {
  if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
  }
}

namespace Modules {
  // The optional captain module is loaded without the game bootstrap below.
  class sn_module {}
}

namespace {
  $root = dirname(__DIR__, 3) . '/';
  define('SN_ROOT_PHYSICAL', $root);
  define('SN_TIME_NOW', 1700000000);
  define('PT_PLANET', 1);
  define('PT_DEBRIS', 2);
  define('PT_MOON', 3);
  define('LOC_NONE', 0);
  define('LOC_PLANET', 1);
  define('FLEET_STATUS_FLYING', 0);
  define('MSG_TYPE_COMBAT', 3);
  define('STRING_NEED_ESCAPING', false);
  foreach (['ARRIVE', 'ACCOMPLISH', 'RETURN'] as $event) { define('EVENT_FLT_' . $event, 'EVENT_FLT_' . $event); }
  foreach (['NONE' => 0, 'ATTACK' => 1, 'AKS' => 2, 'TRANSPORT' => 3, 'RELOCATE' => 4,
    'HOLD' => 5, 'SPY' => 6, 'COLONIZE' => 7, 'RECYCLE' => 8, 'DESTROY' => 9,
    'MISSILE' => 10, 'EXPLORE' => 15] as $name => $value) { define('MT_' . $name, $value); }
  require_once $root . 'includes/constants/constants_units.php';
  require_once $root . 'includes/constants/constants_ube.php';
  require_once $root . 'classes/Fleet/FleetDispatchEvent.php';
  require_once $root . 'classes/Fleet/DbFleetStatic.php';
  require_once $root . 'classes/Planet/DBStaticPlanet.php';
  require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Storage.php';
  require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Lifecycle.php';
  require_once dirname(__DIR__) . '/classes/ZeroGalaxy/RiftTarget.php';
  require_once dirname(__DIR__) . '/classes/ZeroGalaxy/RiftMissionEvent.php';
  require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Profile.php';
  require_once dirname(__DIR__) . '/classes/ZeroGalaxy/RiftName.php';
  require_once dirname(__DIR__) . '/classes/ZeroGalaxy/AttackPolicy.php';
  require_once dirname(__DIR__) . '/classes/ZeroGalaxy/AcsPolicy.php';
  require_once dirname(__DIR__) . '/classes/ZeroGalaxy/RiftBattleRules.php';
  require_once dirname(__DIR__) . '/classes/ZeroGalaxy/RiftAttackEvent.php';

  use Fleet\FleetDispatchEvent;
  use ZeroGalaxy\AttackPolicy;
  use ZeroGalaxy\RiftAttackEvent;
  use ZeroGalaxy\RiftBattleRules;
  use ZeroGalaxy\RiftName;
  use ZeroGalaxy\Storage;

  class SN {
    public static $db;
    public static $gc;
    public static $lang = [];
    public static function db_get_record_list($location, $filter, $fetch = false) {
      $table = self::$db->db_prefix . 'planets';
      $filter = str_replace('{{planets}}', $table, $filter);
      $result = self::$db->link->query("SELECT * FROM {$table} WHERE {$filter} FOR UPDATE");
      return $fetch ? $result->fetch_assoc() : $result->fetch_all(MYSQLI_ASSOC);
    }
  }

  $fixtureOwners = [];
  $battleCalls = $messages = $captainWrites = [];
  $battleResult = UBE_COMBAT_RESULT_WIN;
  $battleRounds = 3;
  $battleThrows = false;
  $battleApplyReturns = false;
  function idval($id) { return (int) $id; }
  function db_set_make_safe_string($values, $delta = false) {
    $parts = [];
    foreach ($values as $key => $value) { $parts[] = "`{$key}` = " . (int) $value; }
    return implode(',', $parts);
  }
  function doquery($sql, $fetch = false) {
    $sql = preg_replace_callback('/\{\{([a-z_]+)\}\}/', function ($match) {
      return SN::$db->db_prefix . $match[1];
    }, $sql);
    $result = SN::$db->link->query($sql);
    return $fetch ? $result->fetch_assoc() : $result;
  }
  function doUpdate($sql) {
    global $captainWrites;
    $captainWrites[] = $sql;
    return true;
  }
  function db_user_by_id($id) {
    global $fixtureOwners;
    return $fixtureOwners[$id] ?? null;
  }
  function sys_o_get_updated($ownerId, $planetId, $time) {
    $row = SN::$db->link->query('SELECT * FROM ' . SN::$db->db_prefix . 'planets WHERE id = ' . (int) $planetId)->fetch_assoc();
    return ['planet' => $row];
  }
  function sys_unit_str2arr($text) {
    $ships = [];
    foreach (explode(';', $text) as $part) {
      if ($part === '') { continue; }
      [$id, $count] = explode(',', $part);
      $ships[(int) $id] = (int) $count;
    }
    return $ships;
  }
  function eco_get_total_cost($id, $count) {
    return ['total' => $count];
  }
  function sn_get_groups($group) {
    if ($group !== 'fleet') { throw new RuntimeException('Unexpected fixture group'); }
    return [202 => 202, 203 => 203, 204 => 204];
  }
  function msg_send_simple_message(...$args) {
    global $messages;
    $messages[] = $args;
  }

  function attackCombat(int $galaxy = 0): array {
    global $battleResult, $battleRounds;
    return [
      UBE_OPTIONS => [UBE_MISSION_TYPE => MT_ATTACK, UBE_DEFENDER_ACTIVE => false],
      UBE_OUTCOME => [UBE_PLANET => [PLANET_GALAXY => $galaxy], UBE_COMBAT_RESULT => $battleResult,
        UBE_MOON => UBE_MOON_CREATE_SUCCESS, UBE_MOON_SIZE => 4000, UBE_MOON_CHANCE => 20,
        UBE_MOON_REAPERS => UBE_MOON_REAPERS_NONE, UBE_DEBRIS => [], UBE_DEBRIS_TOTAL => 0,
        UBE_DEBRIS_ORIGINAL => [RES_METAL => 10000, RES_CRYSTAL => 20000]],
      UBE_ROUNDS => array_fill(0, $battleRounds, []),
      UBE_FLEETS => [77 => [UBE_FLEET_TYPE => UBE_ATTACKERS,
        UBE_CAPTAIN => ['captain_id' => 88, 'unit_id' => 99, 'captain_xp' => 0, 'captain_level' => 0]]],
    ];
  }

  // The expensive native battle engine is a boundary fixture here. Record the
  // event and execute the same capture/apply hook boundary to test adapter
  // isolation. Actual damage, loot and return behavior need the Docker game
  // end-to-end checks; these tests deliberately make no claim about them.
  function flt_mission_attack($event) {
    global $functions, $battleCalls, $battleThrows, $battleApplyReturns, $captain;
    $group = (int) ($event->fleet['fleet_group'] ?? 0);
    // Mirror the native unfiltered group read. The adapter must have removed
    // recalled, forbidden or oversized participants before this boundary.
    $attackers = $group ? SN::$db->link->query('SELECT * FROM ' . SN::$db->db_prefix
      . "fleets WHERE fleet_group={$group} ORDER BY fleet_id FOR UPDATE")->fetch_all(MYSQLI_ASSOC) : [$event->fleet];
    $battleCalls[] = ['fleet' => $event->fleet, 'planet' => $event->dstPlanetRow,
      'attackers' => $attackers, 'mission' => $event->missionId];
    $combat = attackCombat();
    $combat[UBE_OPTIONS][UBE_MISSION_TYPE] = $event->missionId;
    $fleet = $event->fleet;
    $result = null;
    foreach ($functions['flt_planet_capture'] as $handler) {
      $result = call_user_func_array($handler, [&$fleet, &$combat, &$result]);
    }
    if ($battleThrows) { throw new RuntimeException('Fixture battle failed'); }
    if (isset($captain)) { $captain->unit_captain_ube_combat_result_apply($combat); }
    if ($battleApplyReturns) {
      // Optional replay boundary: emulate only the native applied return flags
      // and ACS-row cleanup, never damage, loot, survival or report generation.
      foreach ($attackers as $attacker) {
        SN::$db->link->query('UPDATE ' . SN::$db->db_prefix . 'fleets SET fleet_mess=1 WHERE fleet_id=' . (int) $attacker['fleet_id']);
      }
      if ($group) { SN::$db->link->query('DELETE FROM ' . SN::$db->db_prefix . "aks WHERE id={$group}"); }
    }
    return $combat;
  }

  class AttackFixtureLocks {
    public function lockRecords($locks) {
      foreach (['users', 'planets', 'fleets'] as $table) {
        if (empty($locks[$table])) { continue; }
        $key = $table === 'fleets' ? 'fleet_id' : 'id';
        $ids = implode(',', array_map('intval', $locks[$table]));
        SN::$db->link->query('SELECT ' . $key . ' FROM ' . SN::$db->db_prefix . $table . " WHERE {$key} IN ({$ids}) FOR UPDATE");
      }
      return true;
    }
  }

  function attackTestDb(): \mysqli {
    $db = new \mysqli(getenv('SUPERNOVA_DB_HOST'), getenv('SUPERNOVA_DB_USER'), getenv('SUPERNOVA_DB_PASSWORD'), getenv('SUPERNOVA_DB_NAME'));
    $db->query('SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    $db->query('SET SESSION innodb_lock_wait_timeout = 3');
    return $db;
  }

  if (($argv[1] ?? '') === '--lifecycle-worker') {
    $prefix = $argv[2] ?? '';
    if (!preg_match('/^zg_attack_test_[a-f0-9]{10}_$/D', $prefix)) { throw new \RuntimeException('Isolated prefix required'); }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = attackTestDb();
    $db->begin_transaction();
    $db->query("SELECT * FROM {$prefix}zero_galaxy_state WHERE id = 1 FOR UPDATE");
    $db->query("SELECT * FROM {$prefix}zero_galaxy_rifts FOR UPDATE");
    $db->query("SELECT id FROM {$prefix}users WHERE id = 2 FOR UPDATE");
    echo "LOCKED\n";
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') { throw new \RuntimeException('Missing locking signal'); }
    usleep(200000);
    $db->query("SELECT id FROM {$prefix}users WHERE id = 3 FOR UPDATE");
    $db->query("SELECT id FROM {$prefix}planets WHERE id = 20 FOR UPDATE");
    $db->commit();
    echo "COMMITTED\n";
    exit;
  }

  $assertions = 0;
  $check = function ($condition, $message) use (&$assertions) {
    $assertions++;
    if (!$condition) { throw new \RuntimeException($message); }
  };

  $fleet = [];
  $combat = attackCombat();
  $check(RiftBattleRules::beforeApply($fleet, $combat, 'existing-result') === 'existing-result', 'The hook must preserve its cumulative result');
  $check($combat[UBE_OUTCOME][UBE_MOON] === UBE_MOON_NONE && $combat[UBE_OUTCOME][UBE_MOON_SIZE] === 0
    && $combat[UBE_OUTCOME][UBE_MOON_CHANCE] === 0, 'Even a successful native moon roll must be suppressed before DB apply');
  $check($combat[UBE_OUTCOME][UBE_DEBRIS] === [RES_METAL => 10000, RES_CRYSTAL => 20000]
    && $combat[UBE_OUTCOME][UBE_DEBRIS_TOTAL] === 30000, 'Debris spent by the moon roll must be restored');
  $check($combat[UBE_OPTIONS][UBE_DEFENDER_ACTIVE] === true, 'The unchanged captain hook must treat an eligible NPC as active');
  $ordinary = attackCombat(1);
  $before = $ordinary;
  RiftBattleRules::beforeApply($fleet, $ordinary);
  $check($ordinary === $before, 'The battle hook must never change ordinary galaxy outcomes');

  if (is_file($root . 'modules/unit_captain/unit_captain.php')) {
    require_once $root . 'modules/unit_captain/unit_captain.php';
    require_once $root . 'includes/functions/rpg_points.php';
    $captain = new \unit_captain();
    foreach ([[1, UBE_COMBAT_RESULT_WIN, 0], [2, UBE_COMBAT_RESULT_WIN, 0],
      [3, UBE_COMBAT_RESULT_WIN, 1], [8, UBE_COMBAT_RESULT_WIN, 1],
      [3, UBE_COMBAT_RESULT_DRAW, 0], [3, UBE_COMBAT_RESULT_LOSS, 0]] as [$rounds, $outcome, $xp]) {
      $battleRounds = $rounds;
      $battleResult = $outcome;
      $combat = attackCombat();
      RiftBattleRules::beforeApply($fleet, $combat);
      $captainWrites = [];
      $captain->unit_captain_ube_combat_result_apply($combat);
      $check((int) $combat[UBE_FLEETS][77][UBE_CAPTAIN]['captain_xp'] === $xp && count($captainWrites) === $xp,
        'Real captain rules must award exactly once only for a win with at least three stored rounds');
    }
  } else {
    echo "Captain integration skipped: optional unit_captain module is absent\n";
  }
  $battleResult = UBE_COMBAT_RESULT_WIN;
  $battleRounds = 3;

  $nativeReflection = new \ReflectionClass(FleetDispatchEvent::class);
  $nativeEvent = $nativeReflection->newInstanceWithoutConstructor();
  $nativeEvent->fleet = ['fleet_id' => 77, 'fleet_owner' => 1, 'fleet_mission' => MT_ATTACK,
    'fleet_mess' => FLEET_STATUS_FLYING, 'fleet_group' => 0, 'fleet_array' => '202,100',
    'fleet_end_galaxy' => 0, 'fleet_end_system' => 3, 'fleet_end_planet' => 5, 'fleet_end_type' => PT_PLANET];
  $nativeEvent->event = EVENT_FLT_ARRIVE;
  $nativeEvent->missionId = MT_ATTACK;
  $nativeEvent->fleetId = 77;
  $nativeEvent->fleetOwnerId = 1;
  $nativeEvent->srcPlanetId = 10;
  $nativeEvent->dstPlanetId = 20;
  $nativeEvent->dstPlanetOwnerId = 3;
  $nativeEvent->eventTimeStamp = SN_TIME_NOW;
  $nativeEvent->userIdsToLock = [1,3];
  $nativeEvent->planetIdsToLock = [10,20];
  $nativeEvent->fleetIdsToLock = [77];
  $wrapped = new RiftAttackEvent($nativeEvent, 2);
  foreach (get_object_vars($nativeEvent) as $key => $value) { $check($wrapped->$key === $value, 'Attack wrapping must preserve native field ' . $key); }
  foreach ([MT_AKS, MT_HOLD, MT_EXPLORE, MT_DESTROY, MT_COLONIZE, MT_RECYCLE, MT_TRANSPORT, MT_RELOCATE, MT_MISSILE] as $mission) {
    $event = clone $nativeEvent;
    $event->missionId = $event->fleet['fleet_mission'] = $mission;
    $wrapped = new RiftAttackEvent($event, 2);
    $check($wrapped->missionId === MT_NONE && $wrapped->fleet['fleet_mission'] === $mission,
      'Forbidden missions must reach module handling without modifying their stored mission ID');
  }

  if (getenv('SUPERNOVA_DB_HOST')) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = attackTestDb();
    $prefix = 'zg_attack_test_' . bin2hex(random_bytes(5)) . '_';
    SN::$db = (object) ['db_prefix' => $prefix, 'link' => $db];
    SN::$gc = (object) ['db' => new AttackFixtureLocks()];
    $fixtureOwners = [3 => ['id' => 3, 'user_bot' => 200, 'authlevel' => 0, 'parent_account_id' => 0, 'parent_account_global' => 0],
      4 => ['id' => 4, 'user_bot' => 200, 'authlevel' => 0, 'parent_account_id' => 0, 'parent_account_global' => 0]];
    $snapshot = $db->real_escape_string(json_encode(['profile' => ['version' => 1, 'fleet_limit' => 100,
      'category' => ['key' => 'tiny', 'name' => 'Крошечный', 'color' => '#ADFF2F']]]));
    $functions = ['flt_planet_capture' => ['unrelated-capture-hook'], 'ube_combat_result_apply' => ['unchanged-captain-hook']];
    $originalFunctions = $functions;
    $run = function ($event, bool $metadata = true, bool $acsEnabled = false, int $followFleet = 0)
      use ($db, $prefix, &$battleCalls, &$messages, &$captainWrites, $originalFunctions, $check) {
      $battleCalls = $messages = $captainWrites = [];
      $db->begin_transaction();
      try {
        $wrapped = new RiftAttackEvent($event, 2, $metadata, $acsEnabled);
        $wrapped->lockEventRecords();
        $wrapped->refreshFleet();
        $wrapped->refreshMissionData();
        $wrapped->refreshMissionData(); // A native event must never be applied twice.
        if ($followFleet) {
          $next = clone $event;
          $next->fleet = $db->query("SELECT * FROM {$prefix}fleets WHERE fleet_id={$followFleet}")->fetch_assoc();
          $next->fleetId = $followFleet;
          $next->fleetOwnerId = (int) $next->fleet['fleet_owner'];
          $next->missionId = (int) $next->fleet['fleet_mission'];
          $next->srcPlanetId = (int) $next->fleet['fleet_start_planet_id'];
          $next->userIdsToLock = [1,3,5];
          $next->fleetIdsToLock = [$followFleet];
          $nextWrapped = new RiftAttackEvent($next, 2, $metadata, $acsEnabled);
          $nextWrapped->lockEventRecords();
          $nextWrapped->refreshFleet();
          $nextWrapped->refreshMissionData();
        }
        global $functions;
        $check($functions === $originalFunctions, 'Every event must restore the exact hook registry');
        return ['event' => $wrapped, 'calls' => $battleCalls, 'messages' => $messages, 'xp' => $captainWrites,
          'fleet' => $db->query("SELECT * FROM {$prefix}fleets WHERE fleet_id = 77")->fetch_assoc(),
          'fleets' => $db->query("SELECT * FROM {$prefix}fleets ORDER BY fleet_id")->fetch_all(MYSQLI_ASSOC),
          'acs' => $db->query("SELECT * FROM {$prefix}aks WHERE id=99")->fetch_assoc(),
          'rift' => $db->query("SELECT * FROM {$prefix}zero_galaxy_rifts WHERE rift_id = 1")->fetch_assoc(),
          'planet' => $db->query("SELECT * FROM {$prefix}planets WHERE galaxy = 0")->fetch_assoc()];
      } finally { $db->rollback(); }
    };
    try {
      $db->query("CREATE TABLE {$prefix}zero_galaxy_state (id INT PRIMARY KEY, next_tick_at BIGINT) ENGINE=InnoDB");
      $db->query("INSERT INTO {$prefix}zero_galaxy_state VALUES (1, " . (SN_TIME_NOW + 60) . ')');
      $db->query("CREATE TABLE {$prefix}zero_galaxy_rifts (rift_id INT PRIMARY KEY, planet_id INT, npc_owner_id INT NULL,
        system INT, position INT, born_at BIGINT, opens_at BIGINT, unstable_at BIGINT, closes_at BIGINT,
        disappears_at BIGINT, config_snapshot TEXT) ENGINE=InnoDB");
      $db->query("INSERT INTO {$prefix}zero_galaxy_rifts VALUES (1,20,3,3,5," . (SN_TIME_NOW - 100) . ',' . (SN_TIME_NOW - 50)
        . ',' . (SN_TIME_NOW + 10) . ',' . (SN_TIME_NOW + 20) . ',' . (SN_TIME_NOW + 30) . ", '{$snapshot}')");
      $db->query("CREATE TABLE {$prefix}users (id INT PRIMARY KEY) ENGINE=InnoDB");
      $db->query("INSERT INTO {$prefix}users VALUES (1),(2),(3),(4),(5)");
      $db->query("CREATE TABLE {$prefix}planets (id INT PRIMARY KEY, id_owner INT, galaxy INT,
        system INT, planet INT, planet_type INT, name VARCHAR(100), metal BIGINT DEFAULT 0) ENGINE=InnoDB");
      $db->query("INSERT INTO {$prefix}planets (id,id_owner,galaxy,system,planet,planet_type,name)
        VALUES (10,1,1,2,3,1,'Home'),(11,5,1,2,4,1,'Partner home'),(20,3,0,3,5,1,'Rift')");
      $db->query("CREATE TABLE {$prefix}fleets (fleet_id INT PRIMARY KEY, fleet_owner INT, fleet_mission INT, fleet_mess INT,
        fleet_group INT, fleet_array TEXT, fleet_end_galaxy INT, fleet_end_system INT, fleet_end_planet INT, fleet_end_type INT,
        fleet_start_time BIGINT DEFAULT " . SN_TIME_NOW . ", fleet_end_stay BIGINT DEFAULT 0,
        fleet_start_planet_id INT DEFAULT 10, fleet_end_planet_id INT DEFAULT 20,
        start_time BIGINT DEFAULT " . (SN_TIME_NOW - 10) . ', fleet_end_time BIGINT DEFAULT ' . (SN_TIME_NOW + 10) . ') ENGINE=InnoDB');
      $db->query("INSERT INTO {$prefix}fleets
        (fleet_id,fleet_owner,fleet_mission,fleet_mess,fleet_group,fleet_array,fleet_end_galaxy,fleet_end_system,fleet_end_planet,fleet_end_type)
        VALUES (77,1,1,0,0,'202,100',0,3,5,1)");
      $db->query("CREATE TABLE {$prefix}aks (id INT PRIMARY KEY, galaxy INT, system INT, planet INT, planet_type INT,
        ankunft BIGINT, eingeladen VARCHAR(255), flotten INT, teilnehmer VARCHAR(255), name VARCHAR(100), fleet_end_time BIGINT) ENGINE=InnoDB");
      foreach ([99, 100, 101] as $cost) {
        $db->query("UPDATE {$prefix}fleets SET fleet_array = '202,{$cost}'");
        $data = $run($nativeEvent);
        $check(count($data['calls']) === ($cost <= 100 ? 1 : 0), 'Arrival must allow equality and reject only a strictly greater cost');
        $check($data['event']->missionId === MT_NONE && (int) $data['fleet']['fleet_mission'] === MT_ATTACK,
          'An already processed event must suppress a second native attack without changing its stored mission');
        if ($cost > 100) {
          $check((int) $data['fleet']['fleet_mess'] === 1 && count($data['messages']) === 1
            && $data['messages'][0][6] === AttackPolicy::OVER_LIMIT && $data['messages'][0][8] === true,
            'An oversized fleet must turn back and always receive the exact explanatory message');
          $check((int) $data['rift']['unstable_at'] === SN_TIME_NOW + 10, 'A rejected attack must not destabilize the planet');
        } else {
          $check((int) $data['rift']['unstable_at'] === SN_TIME_NOW && (int) $data['rift']['closes_at'] === SN_TIME_NOW + 20,
            'An attacker win must destabilize immediately while preserving its original closing deadline');
          $check($data['planet']['name'] === RiftName::forRift($data['rift'], 'unstable'),
            'An immediate win must update the native visible name with the saved size and unstable phase');
          $check(AttackPolicy::limit($data['rift']) === 100, 'Battle handling must never reduce the immutable initial fleet cap');
          if (isset($captain)) { $check(count($data['xp']) === 1, 'The adapter must execute the unchanged captain XP hook exactly once'); }
        }
      }
      $db->query("UPDATE {$prefix}fleets SET fleet_array = '202,100'");
      foreach ([UBE_COMBAT_RESULT_DRAW, UBE_COMBAT_RESULT_LOSS] as $result) {
        $battleResult = $result;
        $data = $run($nativeEvent);
        $check(count($data['calls']) === 1 && (int) $data['rift']['unstable_at'] === SN_TIME_NOW + 10,
          'A native draw/loss must run once without turning the planet unstable');
      }
      $battleResult = UBE_COMBAT_RESULT_WIN;
      $db->query("UPDATE {$prefix}planets SET metal = 1 WHERE id = 20");
      $data = $run($nativeEvent);
      $check(count($data['calls']) === 1 && AttackPolicy::limit($data['rift']) === 100,
        'A nearly drained target must still accept the full original cap');
      $db->query("UPDATE {$prefix}zero_galaxy_rifts SET unstable_at = " . (SN_TIME_NOW - 1));
      $data = $run($nativeEvent);
      $check((int) $data['rift']['unstable_at'] === SN_TIME_NOW - 1, 'Another win must never postpone an existing unstable start');

      foreach ([MT_AKS, MT_HOLD, MT_EXPLORE, MT_DESTROY, MT_COLONIZE, MT_RECYCLE, MT_TRANSPORT, MT_RELOCATE, MT_MISSILE] as $mission) {
        $event = clone $nativeEvent;
        $event->missionId = $event->fleet['fleet_mission'] = $mission;
        $db->query("UPDATE {$prefix}fleets SET fleet_mission = {$mission}");
        foreach ([EVENT_FLT_ARRIVE, EVENT_FLT_ACCOMPLISH] as $type) {
          $event->event = $type;
          $data = $run($event);
          $check(!$data['calls'] && (int) $data['fleet']['fleet_mess'] === 1, 'Every unsupported zero-galaxy mission must return at arrival/accomplishment');
        }
      }
      $db->query("UPDATE {$prefix}fleets SET fleet_mission = 1, fleet_group = 99");
      $data = $run($nativeEvent);
      $check(!$data['calls'] && (int) $data['fleet']['fleet_mess'] === 1, 'A grouped native attack must return while ACS is disabled');
      $db->query("UPDATE {$prefix}fleets SET fleet_group = 0");
      $data = $run($nativeEvent, false);
      $check(!$data['calls'] && (int) $data['fleet']['fleet_mess'] === 1, 'Unavailable module metadata must fail closed without unrestricted native combat');
      $db->query("UPDATE {$prefix}zero_galaxy_rifts SET config_snapshot = '{}'");
      $data = $run($nativeEvent);
      $check(!$data['calls'] && (int) $data['fleet']['fleet_mess'] === 1, 'Legacy empty profiles must return without combat');
      $db->query("UPDATE {$prefix}zero_galaxy_rifts SET config_snapshot = '{$snapshot}', closes_at = " . SN_TIME_NOW);
      $data = $run($nativeEvent);
      $check(!$data['calls'] && (int) $data['fleet']['fleet_mess'] === 1, 'A closed target must return without combat');
      $db->query("UPDATE {$prefix}zero_galaxy_rifts SET closes_at = " . (SN_TIME_NOW + 20));
      $fixtureOwners[3]['user_bot'] = 0;
      $data = $run($nativeEvent);
      $check(!$data['calls'], 'A human-owned target at galaxy zero must never become an eligible NPC battle');
      $fixtureOwners[3]['user_bot'] = 200;
      $db->query("DELETE FROM {$prefix}planets WHERE id = 20");
      $data = $run($nativeEvent);
      $check(!$data['calls'] && (int) $data['fleet']['fleet_mess'] === 1, 'A vanished target must return without combat');
      $db->query("INSERT INTO {$prefix}planets (id,id_owner,galaxy,system,planet,planet_type,name) VALUES (21,4,0,3,5,1,'New Rift')");
      $db->query("UPDATE {$prefix}zero_galaxy_rifts SET planet_id = 21, npc_owner_id = 4");
      $data = $run($nativeEvent);
      $check(count($data['calls']) === 1 && (int) $data['calls'][0]['planet']['id'] === 21
        && (int) $data['calls'][0]['planet']['id_owner'] === 4, 'An old flight must attack the current replacement planet at its coordinates');
      $check(in_array(21, $data['event']->planetIdsToLock, true) && in_array(4, $data['event']->userIdsToLock, true),
        'The replacement planet and its owner must enter native locks before refresh');
      $lowerSnapshot = $db->real_escape_string(json_encode(['profile' => ['version' => 1, 'fleet_limit' => 99]]));
      $db->query("UPDATE {$prefix}zero_galaxy_rifts SET config_snapshot = '{$lowerSnapshot}'");
      $data = $run($nativeEvent);
      $check(!$data['calls'] && $data['messages'][0][6] === AttackPolicy::OVER_LIMIT,
        'An old flight must be checked against the replacement target cap');
      $db->query("UPDATE {$prefix}zero_galaxy_rifts SET config_snapshot = '{$snapshot}'");
      $battleThrows = true;
      try { $run($nativeEvent); $check(false, 'The simulated native failure must propagate'); }
      catch (\RuntimeException $error) { $check($error->getMessage() === 'Fixture battle failed', 'Only the intended native failure is expected'); }
      $check($functions === $originalFunctions, 'Hook registry must also be restored if native battle code throws');
      $battleThrows = false;

      $prepareAcs = function () use ($db, $prefix, $snapshot, $nativeEvent) {
        $db->query("DELETE FROM {$prefix}planets WHERE galaxy=0");
        $db->query("INSERT INTO {$prefix}planets (id,id_owner,galaxy,system,planet,planet_type,name)
          VALUES (20,3,0,3,5,1,'Rift')");
        $db->query("UPDATE {$prefix}zero_galaxy_rifts SET planet_id=20,npc_owner_id=3,
          config_snapshot='{$snapshot}',unstable_at=" . (SN_TIME_NOW + 10) . ',closes_at=' . (SN_TIME_NOW + 20));
        $db->query("DELETE FROM {$prefix}fleets WHERE fleet_id<>77");
        $db->query("UPDATE {$prefix}fleets SET fleet_owner=1,fleet_mission=" . MT_AKS . ",fleet_mess=0,
          fleet_group=99,fleet_array='202,100',fleet_end_galaxy=0,fleet_end_system=3,fleet_end_planet=5,
          fleet_end_type=" . PT_PLANET . ',fleet_start_time=' . SN_TIME_NOW . ',fleet_end_stay=0');
        $db->query("INSERT INTO {$prefix}fleets
          (fleet_id,fleet_owner,fleet_mission,fleet_mess,fleet_group,fleet_array,fleet_end_galaxy,
            fleet_end_system,fleet_end_planet,fleet_end_type,fleet_start_planet_id)
          VALUES (78,5," . MT_AKS . ",0,99,'202,100',0,3,5,1,11)");
        $db->query("DELETE FROM {$prefix}aks");
        $db->query("INSERT INTO {$prefix}aks VALUES (99,0,3,5,1," . SN_TIME_NOW
          . ",'1,5',77,'1','Fixture ACS'," . (SN_TIME_NOW + 10) . ')');
        $event = clone $nativeEvent;
        $event->missionId = MT_AKS;
        $event->fleet = $db->query("SELECT * FROM {$prefix}fleets WHERE fleet_id=77")->fetch_assoc();
        return $event;
      };
      $addMember = function (int $id, int $owner, int $amount) use ($db, $prefix) {
        $source = $owner === 1 ? 10 : 11;
        $db->query("INSERT INTO {$prefix}fleets
          (fleet_id,fleet_owner,fleet_mission,fleet_mess,fleet_group,fleet_array,fleet_end_galaxy,
            fleet_end_system,fleet_end_planet,fleet_end_type,fleet_start_planet_id)
          VALUES ({$id},{$owner}," . MT_AKS . ",0,99,'202,{$amount}',0,3,5,1,{$source})");
      };
      $byId = function (array $data): array {
        return array_column($data['fleets'], null, 'fleet_id');
      };

      // Each participant may spend the full cap; the group itself may exceed it.
      $event = $prepareAcs();
      $data = $run($event, true, true);
      $check(count($data['calls']) === 1 && count($data['calls'][0]['attackers']) === 2,
        'Two different owners at cap 100 must enter one ACS battle at combined cost 200');
      $check($data['calls'][0]['mission'] === MT_ATTACK && (int) $data['fleet']['fleet_mission'] === MT_AKS,
        'The native XP boundary must see MT_ATTACK while the stored ACS mission remains MT_AKS');
      $check((int) $data['rift']['unstable_at'] === SN_TIME_NOW && AttackPolicy::limit($data['rift']) === 100,
        'One successful ACS must destabilize the rift without changing its immutable cap');
      $check(in_array(5, $data['event']->userIdsToLock, true) && in_array(78, $data['event']->fleetIdsToLock, true),
        'A group member absent from the initial event snapshot must enter native owner and fleet locks');
      if (isset($captain)) { $check(count($data['xp']) === 1, 'An eligible ACS reaches the unchanged captain XP boundary once'); }

      $event = $prepareAcs();
      $db->query("UPDATE {$prefix}fleets SET fleet_array='202,50' WHERE fleet_id=77");
      $addMember(79,1,50);
      $data = $run($event, true, true);
      $check(count($data['calls']) === 1 && count($data['calls'][0]['attackers']) === 3,
        'Two same-owner fleets whose combined cost equals the personal cap must remain eligible');

      $event = $prepareAcs();
      $db->query("UPDATE {$prefix}fleets SET fleet_array='202,60' WHERE fleet_id=77");
      $addMember(79,1,60);
      $data = $run($event, true, true);
      $members = $byId($data);
      $check(!$data['calls'] && (int) $members[77]['fleet_mess'] === 1 && (int) $members[79]['fleet_mess'] === 1
        && (int) $members[77]['fleet_group'] === 0 && (int) $members[79]['fleet_group'] === 0,
        'Splitting a personal 120-point army across two fleets cannot evade a 100-point cap');
      $check((int) $members[78]['fleet_mess'] === 0 && (int) $members[78]['fleet_group'] === 99
        && (int) $data['rift']['unstable_at'] === SN_TIME_NOW + 10,
        'Rejecting the triggering owner must retain another eligible participant without an early battle');
      $check(count($data['messages']) > 0 && $data['messages'][0][6] === AttackPolicy::OVER_LIMIT,
        'Rejected ACS participants receive the same fleet-too-large explanation');
      $data = $run($event, true, true, 78);
      $check(count($data['calls']) === 1 && array_map('intval', array_column($data['calls'][0]['attackers'], 'fleet_id')) === [78],
        'The next eligible arrival must fight after an oversized triggering owner has been removed');

      $event = $prepareAcs();
      $db->query("UPDATE {$prefix}fleets SET fleet_array='202,60' WHERE fleet_id=78");
      $addMember(79,5,60);
      $data = $run($event, true, true);
      $members = $byId($data);
      $check(count($data['calls']) === 1 && array_map('intval', array_column($data['calls'][0]['attackers'], 'fleet_id')) === [77]
        && (int) $members[78]['fleet_mess'] === 1 && (int) $members[79]['fleet_mess'] === 1,
        'An oversized fellow participant must return all their fleets while an eligible leader still fights');

      $event = $prepareAcs();
      $db->query("UPDATE {$prefix}fleets SET fleet_mess=1,fleet_array='202,10000' WHERE fleet_id=78");
      $data = $run($event, true, true);
      $members = $byId($data);
      $check(count($data['calls']) === 1 && count($data['calls'][0]['attackers']) === 1
        && (int) $members[78]['fleet_mess'] === 1 && (int) $members[78]['fleet_group'] === 0,
        'A recalled fleet must leave the native unfiltered group before combat without reversing its return');

      $event = $prepareAcs();
      $data = $run($event, true, false);
      $members = $byId($data);
      $check(!$data['calls'] && !$data['acs'] && (int) $members[77]['fleet_mess'] === 1
        && (int) $members[78]['fleet_mess'] === 1 && (int) $members[77]['fleet_group'] === 0
        && (int) $members[78]['fleet_group'] === 0,
        'Disabling ACS after launch must return every grouped attacker and remove the group');

      $event = $prepareAcs();
      $db->query("DELETE FROM {$prefix}planets WHERE id=20");
      $data = $run($event, true, true);
      $members = $byId($data);
      $check(!$data['calls'] && !$data['acs'] && (int) $members[77]['fleet_mess'] === 1
        && (int) $members[78]['fleet_mess'] === 1, 'A vanished rift must return the entire ACS without combat');
      $check((int) $members[77]['fleet_end_time'] === SN_TIME_NOW + 10
        && (int) $members[78]['fleet_end_time'] === SN_TIME_NOW + 10,
        'Rejecting a whole group must preserve the native fleet return timestamps');

      $event = $prepareAcs();
      $db->query("UPDATE {$prefix}zero_galaxy_rifts SET unstable_at=" . (SN_TIME_NOW - 1) . ',closes_at=' . SN_TIME_NOW);
      $data = $run($event, true, true);
      $members = $byId($data);
      $check(!$data['calls'] && !$data['acs'] && (int) $members[77]['fleet_mess'] === 1
        && (int) $members[78]['fleet_mess'] === 1, 'The exact rift closing boundary must reject the whole ACS');

      $event = $prepareAcs();
      $db->query("UPDATE {$prefix}fleets SET fleet_mission=" . MT_SPY . ' WHERE fleet_id=78');
      $data = $run($event, true, true);
      $members = $byId($data);
      $check(count($data['calls']) === 1 && count($data['calls'][0]['attackers']) === 1
        && (int) $members[78]['fleet_mess'] === 1 && (int) $members[78]['fleet_group'] === 0,
        'An unrelated mission inserted into an ACS cannot enter the native attack list');

      $event = $prepareAcs();
      $db->query("UPDATE {$prefix}fleets SET fleet_start_time=" . (SN_TIME_NOW + 1) . ' WHERE fleet_id=78');
      $data = $run($event, true, true);
      $members = $byId($data);
      $check(count($data['calls']) === 1 && count($data['calls'][0]['attackers']) === 1
        && (int) $members[78]['fleet_mess'] === 1, 'A member with a different ETA cannot join the current native battle');

      $event = $prepareAcs();
      $db->query("UPDATE {$prefix}fleets SET fleet_end_galaxy=1 WHERE fleet_id=78");
      $data = $run($event, true, true);
      $members = $byId($data);
      $check(count($data['calls']) === 1 && count($data['calls'][0]['attackers']) === 1
        && (int) $members[78]['fleet_mess'] === 0 && (int) $members[78]['fleet_group'] === 0,
        'A corrupt foreign-galaxy group member must be detached without changing that ordinary flight status');

      $event = $prepareAcs();
      $db->query("UPDATE {$prefix}fleets SET fleet_array='202,1'");
      foreach ([79,80,81,82] as $id) { $addMember($id, $id % 2 ? 1 : 5, 1); }
      $data = $run($event, true, true);
      $check(!$data['calls'] && !$data['acs'] && count($data['fleets']) === 6
        && count(array_filter($data['fleets'], function ($member) {
          return (int) $member['fleet_mess'] === 1 && (int) $member['fleet_group'] === 0;
        })) === 6, 'A corrupt group beyond the native five-fleet maximum must fail closed as a whole');

      $event = $prepareAcs();
      $db->query("UPDATE {$prefix}fleets SET fleet_array='202,1'");
      foreach ([79,80,81,82] as $id) { $addMember($id, $id % 2 ? 1 : 5, 1); }
      $db->query("UPDATE {$prefix}fleets SET fleet_mess=1 WHERE fleet_id=82");
      $data = $run($event, true, true);
      $members = $byId($data);
      $check(count($data['calls']) === 1
        && array_map('intval', array_column($data['calls'][0]['attackers'], 'fleet_id')) === [77,78,79,80,81]
        && (int) $members[82]['fleet_mess'] === 1 && (int) $members[82]['fleet_group'] === 0,
        'Five flying ACS fleets plus one recalled row must fight once; the recalled fleet stays returning and detached');

      $event = $prepareAcs();
      $battleApplyReturns = true;
      $data = $run($event, true, true, 78);
      $battleApplyReturns = false;
      $check(count($data['calls']) === 1 && !$data['acs']
        && count(array_filter($data['fleets'], function ($member) { return (int) $member['fleet_mess'] === 1; })) === 2,
        'After the native boundary marks participants returning, another queued arrival must not replay the battle');
      // Restore a normal attack for the independent lifecycle lock-order test.
      $prepareAcs();
      $db->query("DELETE FROM {$prefix}aks");
      $db->query("DELETE FROM {$prefix}fleets WHERE fleet_id<>77");
      $db->query("UPDATE {$prefix}fleets SET fleet_mission=" . MT_ATTACK . ',fleet_group=0');

      $worker = proc_open([PHP_BINARY, __FILE__, '--lifecycle-worker', $prefix],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
      if (!is_resource($worker)) { throw new \RuntimeException('Could not start locking worker'); }
      $check(trim((string) fgets($pipes[1])) === 'LOCKED', 'Lifecycle must hold state/rifts before native owners');
      $db->begin_transaction();
      fwrite($pipes[0], "GO\n");
      fflush($pipes[0]);
      $concurrent = new RiftAttackEvent($nativeEvent, 2);
      $check($concurrent->lockEventRecords(), 'An attack must wait for module state before taking native user locks under SERIALIZABLE');
      $db->commit();
      fclose($pipes[0]);
      $workerOutput = stream_get_contents($pipes[1]);
      $workerErrors = stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      $check(proc_close($worker) === 0 && strpos($workerOutput, 'COMMITTED') !== false,
        'Lifecycle and native attack locking must both complete without deadlock: ' . $workerOutput . $workerErrors);
    } finally {
      $db->rollback();
      foreach (['zero_galaxy_state', 'zero_galaxy_rifts', 'aks', 'fleets', 'planets', 'users'] as $table) {
        $db->query("DROP TABLE IF EXISTS {$prefix}{$table}");
      }
      $db->close();
    }
  } else {
    echo "Attack metadata integration skipped: SUPERNOVA_DB_HOST is not set\n";
  }
  echo "Zero Galaxy attack: {$assertions} assertions passed\n";
}
