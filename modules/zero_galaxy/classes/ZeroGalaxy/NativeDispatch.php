<?php

namespace ZeroGalaxy;

use DBAL\db_mysql;
use DBAL\OldDbChangeSet;
use Fleet\DbFleetStatic;
use Planet\DBStaticPlanet;
use RuntimeException;
use SN;
use Throwable;
use Unit\DBStaticUnit;

/** One rift flight: native checks, travel, fleet record, ship and fuel debit and captain in one transaction. */
final class NativeDispatch {
  private $db;
  private $sql;

  public function __construct($db = null) {
    $this->db = $db ?? SN::$db;
    $this->sql = new Sql($this->db->link, $this->db->db_prefix);
  }

  public function send(array $actor, DispatchRequest $request, int $now): DispatchResult {
    if (db_mysql::$db_in_transaction || $this->db->transactionCheck()) {
      throw new RuntimeException('Zero Galaxy dispatch cannot start in the current database context');
    }
    // Discover native IDs before SERIALIZABLE reads can lock a planet before its owner.
    $destination = DBStaticPlanet::db_planet_by_gspt(0, $request->system, $request->position,
      $request->planetType === PT_MOON ? PT_MOON : PT_PLANET) ?: null;
    db_mysql::db_transaction_start();
    try {
      $result = $this->sendLocked($actor, $request, $destination, $now);
      db_mysql::db_transaction_commit();
    } catch (Throwable $error) {
      if (db_mysql::$db_in_transaction) { db_mysql::db_transaction_rollback(); }
      throw $error;
    }
    $result->refreshedSource = $this->refreshSource($result->source, (int) $result->travel['consumption']);
    return $result;
  }

  private function sendLocked(array $actor, DispatchRequest $request, ?array $destination, int $now): DispatchResult {
    $target = $this->lockTarget($actor, $request, $destination, $now);
    if (!$target) {
      throw DispatchFailure::forMission($request->mission, 'missing');
    }
    [$actor, $source, $planet, $rift] = $target;
    [$ships, $remaining] = $this->ships($actor, $source, $planet, $rift, $request);
    $allowed = $this->allowed($actor, $source, $planet, $ships, $request->mission, $request->speed);
    $emptyTarget = $allowed === ATTACK_NO_TARGET && $request->allowEmptyTarget && $request->mission !== MT_RECYCLE;
    if ($allowed !== ATTACK_ALLOWED && !$emptyTarget) {
      throw new DispatchFailure('', $allowed);
    }
    $travel = flt_travel_data($actor, RiftGeometry::source($source), $planet, $ships, $request->speed);
    if ($travel['duration'] < 1) {
      throw $request->mission === MT_ATTACK ? new DispatchFailure('', ATTACK_NO_FUEL) : new DispatchFailure('gs_c13');
    }
    if ($request->captainId && !$this->hasCaptain($actor, $source, $request->captainId)) {
      throw new DispatchFailure('zero_galaxy_attack_captain_missing');
    }
    $dispatch = $this->launch($actor, $source, $planet, $request, $ships, $travel, $now);
    if ($request->captainId) { $this->assignCaptain($request->captainId, $dispatch['fleet_id']); }
    return new DispatchResult($dispatch, $ships, $travel, $source, $planet, $remaining);
  }

  private function lockTarget(array $actor, DispatchRequest $request, ?array $destination, int $now): ?array {
    if (!$this->sql->one('SELECT id FROM ' . $this->sql->table('zero_galaxy_state') . ' WHERE id = 1 FOR UPDATE')) {
      return null;
    }
    $rift = $request->mission === MT_ATTACK && $destination
      ? $this->sql->one('SELECT * FROM ' . $this->sql->table('zero_galaxy_rifts') . " WHERE system = {$request->system}
        AND position = {$request->position} AND planet_id = " . (int) $destination['id'] . ' FOR UPDATE') : null;
    $sourceId = $request->sourceId ?: (int) $actor['current_planet'];
    $owners = [(int) $actor['id']];
    $planets = [$sourceId];
    if ($destination) {
      $owners[] = (int) $destination['id_owner'];
      $planets[] = (int) $destination['id'];
    }
    SN::$gc->db->lockRecords(['users' => array_values(array_unique($owners)), 'planets' => $planets]);
    $GLOBALS['user'] = $actor = db_user_by_id($actor['id'], true);
    $source = DBStaticPlanet::db_planet_by_id($sourceId, true);
    $planet = DBStaticPlanet::db_planet_by_gspt(0, $request->system, $request->position,
      $request->planetType === PT_MOON ? PT_MOON : PT_PLANET);
    if ((int) ($planet['id'] ?? 0) !== (int) ($destination['id'] ?? 0)
      || (int) ($planet['id_owner'] ?? 0) !== (int) ($destination['id_owner'] ?? 0)) {
      throw DispatchFailure::forMission($request->mission, 'failed');
    }
    if (!$source || (int) $source['id_owner'] !== (int) $actor['id'] || (!$planet && !$request->allowEmptyTarget)) {
      return null;
    }
    // Quick missions run before common.php updates the source economy and queues.
    $updated = sys_o_get_updated((int) $actor['id'], $sourceId, $now);
    $GLOBALS['user'] = $actor = $updated['user'];
    $source = $updated['planet'];
    $planet = $planet ?: ['id' => 0, 'id_owner' => 0, 'galaxy' => 0, 'system' => $request->system,
      'planet' => $request->position, 'planet_type' => $request->planetType, 'debris_metal' => 0, 'debris_crystal' => 0];
    return [$actor, $source, $planet, $rift ?: []];
  }

  /** @return array Ships to send and the remaining owned ships of the mission type. */
  private function ships(array $actor, array $source, array $planet, array $rift, DispatchRequest $request): array {
    if ($request->mission === MT_ATTACK) {
      $limit = AttackPolicy::limit($rift);
      if ($limit !== null && AttackPolicy::cost($request->ships) > $limit) {
        throw new DispatchFailure(AttackPolicy::OVER_LIMIT);
      }
      return [$request->ships, 0];
    }
    if ($request->mission === MT_SPY) {
      $owned = $this->inventory($actor, $source, SHIP_SPY);
      $probes = $request->ships ? ($request->ships[SHIP_SPY] ?? 0) : min($owned, $request->probeCount);
      if ($probes < 1) { throw new DispatchFailure('', ATTACK_NO_SPIES); }
      return [$request->ships ?: [SHIP_SPY => $probes], $owned - $probes];
    }
    $recyclers = sn_get_groups('flt_recyclers');
    if ($request->ships) {
      $ships = $request->ships;
      $owned = 0;
      foreach ($recyclers as $id) { $owned += $this->inventory($actor, $source, $id); }
    } else {
      $debris = (float) $planet['debris_metal'] + (float) $planet['debris_crystal'];
      if (!is_finite($debris) || $debris <= 0) { throw new DispatchFailure('', ATTACK_NO_DEBRIS); }
      [$ships, $owned] = $this->recyclerFleet($actor, $source, $planet, $debris);
    }
    $sent = array_intersect_key($ships, array_flip($recyclers));
    if (!$sent) { throw new DispatchFailure('', ATTACK_NO_RECYCLERS); }
    return [$ships, $owned - array_sum($sent)];
  }

  private function inventory(array $actor, array $source, int $unitId): int {
    return max(0, (int) mrc_get_level($actor, $source, $unitId, false, true));
  }

  private function recyclerFleet(array $actor, array $source, array $planet, float $debris): array {
    $from = RiftGeometry::source($source);
    $available = [];
    $owned = 0;
    foreach (sn_get_groups('flt_recyclers') as $id) {
      $count = $this->inventory($actor, $source, $id);
      $owned += $count;
      $single = flt_travel_data($actor, $from, $planet, [$id => 1], 10);
      if ($count > 0 && $single['fleet_speed'] > 0 && $single['hold'] > 0 && $single['consumption'] > 0) {
        $available[$id] = $count;
      }
    }
    if (!$available) { throw new DispatchFailure('', $owned ? ATTACK_TOO_FAR : ATTACK_NO_RECYCLERS); }
    $selection = flt_calculate_fleet_to_transport($available, $debris, $from, $planet);
    $fleet = array_map('intval', $selection['fleet']);
    if (!$fleet || min($fleet) < 1) { throw new DispatchFailure('', ATTACK_NO_FUEL); }
    return [$fleet, $owned];
  }

  private function allowed(array $actor, array $source, array $planet, array $ships, int $mission, int $speed): int {
    $allowed = flt_can_attack(RiftGeometry::source($source), $planet, $ships + [RES_DEUTERIUM => 0], $mission, [
      P_FLEET_ATTACK_SPEED_PERCENT_TENTH => $speed, P_FLEET_ATTACK_RES_LIST => [],
      P_FLEET_ATTACK_RESOURCES_SUM => 0, P_FLEET_ATTACK_FLEET_GROUP => 0,
      P_FLEET_ATTACK_FLYING_COUNT => DbFleetStatic::fleet_count_flying($actor['id']),
      P_FLEET_ATTACK_TARGET_STRUCTURE => 0, P_FLEET_ATTACK_STAY_TIME => 0,
    ]);
    if ($allowed === ATTACK_NOOB && in_array($mission, [MT_ATTACK, MT_SPY], true)
      && $this->sql->one('SELECT u.id FROM ' . Npc::fromSql($this->sql) . ' AND u.id = ' . (int) $planet['id_owner'])) {
      return ATTACK_ALLOWED;
    }
    return $allowed;
  }

  private function hasCaptain(array $actor, array $source, int $captainId): bool {
    $module = moduleCaptain();
    $captain = is_object($module) ? $module->unit_captain_get($source['id'], LOC_PLANET, true, false, $actor['id']) : null;
    return $captain && (int) $captain['unit_id'] === $captainId
      && (int) $captain['unit_player_id'] === (int) $actor['id']
      && (int) $captain['unit_location_type'] === LOC_PLANET
      && (int) $captain['unit_location_id'] === (int) $source['id'];
  }

  private function assignCaptain(int $captainId, int $fleetId): void {
    if (!DBStaticUnit::db_unit_set_by_id($captainId, 'unit_location_type = ' . LOC_FLEET . ', unit_location_id = ' . $fleetId)) {
      throw new RuntimeException('Zero Galaxy captain was not assigned to the fleet');
    }
  }

  private function launch(array $actor, array $source, array $planet, DispatchRequest $request,
    array $ships, array $travel, int $now): array {
    $arrival = $now + (int) $travel['duration'];
    $returnAt = $arrival + (int) $travel['duration'];
    $fleetId = DbFleetStatic::fleet_insert_set_dbq([
      'fleet_owner' => (int) $actor['id'], 'fleet_mission' => $request->mission,
      'fleet_amount' => array_sum($ships), 'fleet_array' => sys_unit_arr2str($ships),
      'fleet_start_time' => $arrival, 'fleet_start_planet_id' => (int) $source['id'],
      'fleet_start_galaxy' => (int) $source['galaxy'], 'fleet_start_system' => (int) $source['system'],
      'fleet_start_planet' => (int) $source['planet'], 'fleet_start_type' => (int) $source['planet_type'],
      'fleet_end_time' => $returnAt, 'fleet_end_planet_id' => $planet['id'] ? (int) $planet['id'] : null,
      'fleet_end_galaxy' => 0, 'fleet_end_system' => (int) $planet['system'],
      'fleet_end_planet' => (int) $planet['planet'],
      'fleet_end_type' => $request->planetType,
      'fleet_target_owner' => (int) $planet['id_owner'], 'start_time' => $now,
    ]);
    if (!$fleetId) { throw new RuntimeException('Zero Galaxy fleet was not created'); }
    $changes = [];
    foreach ($ships as $id => $count) {
      $changes[] = OldDbChangeSet::db_changeset_prepare_unit($id, -$count, $actor, $source);
    }
    $fuel = (int) $travel['consumption'];
    if (!DBStaticPlanet::db_planet_set_by_id($source['id'], "deuterium = deuterium - {$fuel}")
      || !OldDbChangeSet::db_changeset_apply(['unit' => $changes])) {
      throw new RuntimeException('Zero Galaxy dispatch resources were not deducted');
    }
    return ['fleet_id' => (int) $fleetId, 'arrival' => $arrival, 'return_at' => $returnAt, 'mission' => $request->mission];
  }

  /** The flight is committed: a failed display refresh must not turn it into a reported failure. */
  private function refreshSource(array $source, int $fuel): array {
    try {
      $current = DBStaticPlanet::db_planet_by_id((int) $source['id']);
      if ($current) { return $current; }
    } catch (Throwable $error) { error_log('Zero Galaxy committed dispatch refresh: ' . $error->getMessage()); }
    $source['deuterium'] -= $fuel;
    return $source;
  }
}
