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
  require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Profile.php';
  require_once dirname(__DIR__) . '/classes/ZeroGalaxy/RiftName.php';
  require_once dirname(__DIR__) . '/classes/ZeroGalaxy/AttackPolicy.php';
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
    global $functions, $battleCalls, $battleThrows, $captain;
    $battleCalls[] = ['fleet' => $event->fleet, 'planet' => $event->dstPlanetRow];
    $combat = attackCombat();
    $fleet = $event->fleet;
    $result = null;
    foreach ($functions['flt_planet_capture'] as $handler) {
      $result = call_user_func_array($handler, [&$fleet, &$combat, &$result]);
    }
    if ($battleThrows) { throw new RuntimeException('Fixture battle failed'); }
    if (isset($captain)) { $captain->unit_captain_ube_combat_result_apply($combat); }
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
    $run = function ($event, bool $metadata = true) use ($db, $prefix, &$battleCalls, &$messages, &$captainWrites, $originalFunctions, $check) {
      $battleCalls = $messages = $captainWrites = [];
      $db->begin_transaction();
      try {
        $wrapped = new RiftAttackEvent($event, 2, $metadata);
        $wrapped->lockEventRecords();
        $wrapped->refreshFleet();
        $wrapped->refreshMissionData();
        $wrapped->refreshMissionData(); // A native event must never be applied twice.
        global $functions;
        $check($functions === $originalFunctions, 'Every event must restore the exact hook registry');
        return ['event' => $wrapped, 'calls' => $battleCalls, 'messages' => $messages, 'xp' => $captainWrites,
          'fleet' => $db->query("SELECT * FROM {$prefix}fleets WHERE fleet_id = 77")->fetch_assoc(),
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
      $db->query("INSERT INTO {$prefix}users VALUES (1),(2),(3),(4)");
      $db->query("CREATE TABLE {$prefix}planets (id INT PRIMARY KEY, id_owner INT, galaxy INT,
        system INT, planet INT, planet_type INT, name VARCHAR(100), metal BIGINT DEFAULT 0) ENGINE=InnoDB");
      $db->query("INSERT INTO {$prefix}planets (id,id_owner,galaxy,system,planet,planet_type,name)
        VALUES (10,1,1,2,3,1,'Home'),(20,3,0,3,5,1,'Rift')");
      $db->query("CREATE TABLE {$prefix}fleets (fleet_id INT PRIMARY KEY, fleet_owner INT, fleet_mission INT, fleet_mess INT,
        fleet_group INT, fleet_array TEXT, fleet_end_galaxy INT, fleet_end_system INT, fleet_end_planet INT, fleet_end_type INT) ENGINE=InnoDB");
      $db->query("INSERT INTO {$prefix}fleets VALUES (77,1,1,0,0,'202,100',0,3,5,1)");
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
      foreach (['zero_galaxy_state', 'zero_galaxy_rifts', 'fleets', 'planets', 'users'] as $table) {
        $db->query("DROP TABLE IF EXISTS {$prefix}{$table}");
      }
      $db->close();
    }
  } else {
    echo "Attack metadata integration skipped: SUPERNOVA_DB_HOST is not set\n";
  }
  echo "Zero Galaxy attack: {$assertions} assertions passed\n";
}
