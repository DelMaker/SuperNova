<?php

namespace Modules {
  // The optional captain module is loaded without the game bootstrap below.
  class sn_module {}
}

namespace {
  require __DIR__ . '/support/bootstrap.php';
  zgCoreConstants();
  require __DIR__ . '/support/database.php';
  require __DIR__ . '/support/arrival.php';

  use ZeroGalaxy\AttackPolicy;
  use ZeroGalaxy\RiftAttackEvent;
  use ZeroGalaxy\RiftBattleRules;
  use ZeroGalaxy\RiftName;

  SN::$lang = zgLanguage('ru') + ['sys_mess_qg' => 'Командование флотом'];
  $battleCalls = $messages = $captainWrites = [];
  $battleResult = UBE_COMBAT_RESULT_WIN;
  $battleRounds = 3;
  $battleThrows = false;
  function doUpdate($sql) {
    global $captainWrites;
    $captainWrites[] = $sql;
    return true;
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
    global $functions, $battleCalls, $battleThrows, $captain;
    $battleCalls[] = ['fleet' => $event->fleet, 'planet' => $event->dstPlanetRow,
      'attackers' => [$event->fleet], 'mission' => $event->missionId];
    $combat = attackCombat();
    $combat[UBE_OPTIONS][UBE_MISSION_TYPE] = $event->missionId;
    $fleet = $event->fleet;
    $result = null;
    foreach ($functions['flt_planet_capture'] as $handler) {
      $result = call_user_func_array($handler, [&$fleet, &$combat, &$result]);
    }
    if ($battleThrows) { throw new RuntimeException('Fixture battle failed'); }
    if (isset($captain)) { $captain->unit_captain_ube_combat_result_apply($combat); }
    return $combat;
  }

  if (($argv[1] ?? '') === '--lifecycle-worker') {
    $tables = new ZgTables(zgDb(true), 'attack', $argv[2] ?? '');
    $prefix = $tables->prefix;
    $tables->db->begin_transaction();
    $tables->db->query("SELECT * FROM {$prefix}zero_galaxy_state WHERE id = 1 FOR UPDATE");
    $tables->db->query("SELECT * FROM {$prefix}zero_galaxy_rifts FOR UPDATE");
    $tables->db->query("SELECT id FROM {$prefix}users WHERE id = 2 FOR UPDATE");
    echo "LOCKED\n";
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') { throw new \RuntimeException('Missing locking signal'); }
    usleep(200000);
    $tables->db->query("SELECT id FROM {$prefix}users WHERE id = 3 FOR UPDATE");
    $tables->db->query("SELECT id FROM {$prefix}planets WHERE id = 20 FOR UPDATE");
    $tables->db->commit();
    echo "COMMITTED\n";
    exit;
  }

  $fleet = [];
  $combat = attackCombat();
  RiftBattleRules::beforeApply($fleet, $combat);
  $check($combat[UBE_OUTCOME][UBE_MOON] === UBE_MOON_NONE && $combat[UBE_OUTCOME][UBE_MOON_SIZE] === 0
    && $combat[UBE_OUTCOME][UBE_MOON_CHANCE] === 0, 'Even a successful native moon roll must be suppressed before DB apply');
  $check($combat[UBE_OUTCOME][UBE_DEBRIS] === [RES_METAL => 10000, RES_CRYSTAL => 20000]
    && $combat[UBE_OUTCOME][UBE_DEBRIS_TOTAL] === 30000, 'Debris spent by the moon roll must be restored');
  $check($combat[UBE_OPTIONS][UBE_DEFENDER_ACTIVE] === true, 'The unchanged captain hook must treat an eligible NPC as active');
  $ordinary = attackCombat(1);
  $before = $ordinary;
  RiftBattleRules::beforeApply($fleet, $ordinary);
  $check($ordinary === $before, 'The battle hook must never change ordinary galaxy outcomes');

  if (is_file(SN_ROOT_PHYSICAL . 'modules/unit_captain/unit_captain.php')) {
    require_once SN_ROOT_PHYSICAL . 'modules/unit_captain/unit_captain.php';
    require_once SN_ROOT_PHYSICAL . 'includes/functions/rpg_points.php';
    $captain = new \unit_captain();
    foreach ([[2, UBE_COMBAT_RESULT_WIN, 0], [3, UBE_COMBAT_RESULT_WIN, 1],
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

  $tables = new ZgTables(zgDb(true), 'attack');
  $db = $tables->db;
  $prefix = $tables->prefix;
  zgArrivalDb($tables);
  $nativeEvent = zgArrivalEvent(['fleet_id' => 77, 'fleet_owner' => 1, 'fleet_mission' => MT_ATTACK,
    'fleet_mess' => FLEET_STATUS_FLYING, 'fleet_group' => 0, 'fleet_array' => '202,100',
    'fleet_end_galaxy' => 0, 'fleet_end_system' => 3, 'fleet_end_planet' => 5, 'fleet_end_type' => PT_PLANET], MT_ATTACK);
  $snapshot = $db->real_escape_string(zgSnapshot(['fleet_limit' => 100]));
  $functions = ['flt_planet_capture' => ['unrelated-capture-hook'], 'ube_combat_result_apply' => ['unchanged-captain-hook']];
  $originalFunctions = $functions;
  $run = function ($event, bool $metadata = true)
    use ($tables, $prefix, &$battleCalls, &$messages, &$captainWrites, $originalFunctions, $check) {
    $battleCalls = $messages = $captainWrites = [];
    $tables->db->begin_transaction();
    try {
      $wrapped = new RiftAttackEvent($event, $metadata);
      $wrapped->lockEventRecords();
      $wrapped->refreshFleet();
      $wrapped->refreshMissionData();
      $wrapped->refreshMissionData(); // A native event must never be applied twice.
      global $functions;
      $check($functions === $originalFunctions, 'Every event must restore the exact hook registry');
      return ['event' => $wrapped, 'calls' => $battleCalls, 'messages' => $messages, 'xp' => $captainWrites,
        'fleet' => $tables->one("SELECT * FROM {$prefix}fleets WHERE fleet_id = 77"),
        'rift' => $tables->one("SELECT * FROM {$prefix}zero_galaxy_rifts WHERE rift_id = 1"),
        'planet' => $tables->one("SELECT * FROM {$prefix}planets WHERE galaxy = 0")];
    } finally { $tables->db->rollback(); }
  };
  try {
    $db->query("CREATE TABLE {$prefix}zero_galaxy_state (id INT PRIMARY KEY, next_tick_at BIGINT) ENGINE=InnoDB");
    $db->query("INSERT INTO {$prefix}zero_galaxy_state VALUES (1, " . (SN_TIME_NOW + 60) . ')');
    $db->query("CREATE TABLE {$prefix}zero_galaxy_rifts (rift_id INT PRIMARY KEY, planet_id INT, npc_owner_id INT NULL,
      system INT, position INT, born_at BIGINT, opens_at BIGINT, unstable_at BIGINT, closes_at BIGINT,
      disappears_at BIGINT, config_snapshot TEXT) ENGINE=InnoDB");
    $db->query("INSERT INTO {$prefix}zero_galaxy_rifts VALUES (1,20,3,3,5," . (SN_TIME_NOW - 100) . ',' . (SN_TIME_NOW - 50)
      . ',' . (SN_TIME_NOW + 10) . ',' . (SN_TIME_NOW + 20) . ',' . (SN_TIME_NOW + 30) . ", '{$snapshot}')");
    $db->query("CREATE TABLE {$prefix}users (id INT PRIMARY KEY, user_bot INT DEFAULT 0, authlevel INT DEFAULT 0,
      parent_account_id INT DEFAULT 0, parent_account_global INT DEFAULT 0) ENGINE=InnoDB");
    $db->query("INSERT INTO {$prefix}users (id, user_bot) VALUES (1,0),(2,0),(3,200),(4,200)");
    $db->query("CREATE TABLE {$prefix}planets (id INT PRIMARY KEY, id_owner INT, galaxy INT,
      system INT, planet INT, planet_type INT, name VARCHAR(100), metal BIGINT DEFAULT 0, last_update BIGINT DEFAULT 0) ENGINE=InnoDB");
    $db->query("INSERT INTO {$prefix}planets (id,id_owner,galaxy,system,planet,planet_type,name)
      VALUES (10,1,1,2,3,1,'Home'),(20,3,0,3,5,1,'Rift')");
    $db->query("CREATE TABLE {$prefix}fleets (fleet_id INT PRIMARY KEY, fleet_owner INT, fleet_mission INT, fleet_mess INT,
      fleet_group INT, fleet_array TEXT, fleet_end_galaxy INT, fleet_end_system INT, fleet_end_planet INT, fleet_end_type INT,
      fleet_start_time BIGINT DEFAULT " . SN_TIME_NOW . ", fleet_end_stay BIGINT DEFAULT 0,
      fleet_start_planet_id INT DEFAULT 10, fleet_end_planet_id INT DEFAULT 20,
      start_time BIGINT DEFAULT " . (SN_TIME_NOW - 10) . ', fleet_end_time BIGINT DEFAULT ' . (SN_TIME_NOW + 10) . ') ENGINE=InnoDB');
    $db->query("INSERT INTO {$prefix}fleets
      (fleet_id,fleet_owner,fleet_mission,fleet_mess,fleet_group,fleet_array,fleet_end_galaxy,fleet_end_system,fleet_end_planet,fleet_end_type)
      VALUES (77,1,1,0,0,'202,100',0,3,5,1)");
    foreach ([99, 100, 101] as $cost) {
      $db->query("UPDATE {$prefix}fleets SET fleet_array = '202,{$cost}'");
      $data = $run($nativeEvent);
      $check(count($data['calls']) === ($cost <= 100 ? 1 : 0), 'Arrival must allow equality and reject only a strictly greater cost');
      $check($data['event']->missionId === MT_NONE && (int) $data['fleet']['fleet_mission'] === MT_ATTACK,
        'An already processed event must suppress a second native attack without changing its stored mission');
      if ($cost > 100) {
        $check((int) $data['fleet']['fleet_mess'] === 1 && count($data['messages']) === 1
          && $data['messages'][0][6] === SN::$lang[AttackPolicy::OVER_LIMIT] && $data['messages'][0][8] === true,
          'An oversized fleet must turn back and always receive the explanatory message in the server language');
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
    $check(!$data['calls'] && (int) $data['fleet']['fleet_mess'] === 1
      && (int) $data['fleet']['fleet_group'] === 0 && (int) $data['fleet']['fleet_end_time'] === SN_TIME_NOW + 10,
      'A grouped attack must return without combat, detach its group and preserve the native return time');
    $db->query("UPDATE {$prefix}fleets SET fleet_group = 0");
    $data = $run($nativeEvent, false);
    $check(!$data['calls'] && (int) $data['fleet']['fleet_mess'] === 1, 'Unavailable module metadata must fail closed without unrestricted native combat');
    $db->query("UPDATE {$prefix}zero_galaxy_rifts SET config_snapshot = '{}'");
    $data = $run($nativeEvent);
    $check(!$data['calls'] && (int) $data['fleet']['fleet_mess'] === 1, 'A rift without a valid profile must return without combat');
    $db->query("UPDATE {$prefix}zero_galaxy_rifts SET config_snapshot = '{$snapshot}', closes_at = " . SN_TIME_NOW);
    $data = $run($nativeEvent);
    $check(!$data['calls'] && (int) $data['fleet']['fleet_mess'] === 1, 'A closed target must return without combat');
    $db->query("UPDATE {$prefix}zero_galaxy_rifts SET closes_at = " . (SN_TIME_NOW + 20));
    $db->query("UPDATE {$prefix}users SET user_bot = 0 WHERE id = 3");
    $data = $run($nativeEvent);
    $check(!$data['calls'], 'A human-owned target at galaxy zero must never become an eligible NPC battle');
    $db->query("UPDATE {$prefix}users SET user_bot = 200 WHERE id = 3");
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
    $lowerSnapshot = $db->real_escape_string(zgSnapshot(['fleet_limit' => 99]));
    $db->query("UPDATE {$prefix}zero_galaxy_rifts SET config_snapshot = '{$lowerSnapshot}'");
    $data = $run($nativeEvent);
    $check(!$data['calls'] && $data['messages'][0][6] === SN::$lang[AttackPolicy::OVER_LIMIT],
      'An old flight must be checked against the replacement target cap');
    $db->query("UPDATE {$prefix}zero_galaxy_rifts SET config_snapshot = '{$snapshot}'");
    $battleThrows = true;
    $error = zgExpect(function () use ($run, $nativeEvent) { $run($nativeEvent); }, \RuntimeException::class,
      'The simulated native failure must propagate');
    $check($error && $error->getMessage() === 'Fixture battle failed', 'Only the intended native failure is expected');
    $check($functions === $originalFunctions, 'Hook registry must also be restored if native battle code throws');
    $battleThrows = false;

    $worker = zgWorker(['--lifecycle-worker', $prefix]);
    $check(trim((string) fgets($worker[1][1])) === 'LOCKED', 'Lifecycle must hold state/rifts before native owners');
    $db->begin_transaction();
    fwrite($worker[1][0], "GO\n");
    fflush($worker[1][0]);
    $check((new RiftAttackEvent($nativeEvent))->lockEventRecords(),
      'An attack must wait for module state before taking native user locks under SERIALIZABLE');
    $db->commit();
    [$code, $output, $errors] = zgFinish($worker);
    $check($code === 0 && strpos($output, 'COMMITTED') !== false,
      'Lifecycle and native attack locking must both complete without deadlock: ' . $output . $errors);
  } finally {
    $tables->drop();
    $db->close();
  }
  echo "Zero Galaxy attack: {$assertions} assertions passed\n";
}
