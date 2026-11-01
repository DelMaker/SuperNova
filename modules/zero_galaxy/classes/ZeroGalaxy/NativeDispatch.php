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

class NativeDispatch implements FleetDispatchGateway {
  private $db;
  private $prefix;

  public function __construct($db = null) {
    $this->db = $db ?? SN::$db;
    $this->prefix = $this->db->db_prefix;
  }

  public function transaction(callable $action) {
    if (!preg_match('/^[a-zA-Z0-9_]*$/D', $this->prefix)
      || db_mysql::$db_in_transaction || $this->db->transactionCheck()) {
      throw new RuntimeException('Zero Galaxy dispatch cannot start in the current database context');
    }
    db_mysql::db_transaction_start();
    try {
      $result = $action();
      db_mysql::db_transaction_commit();
      return $result;
    } catch (Throwable $error) {
      if (db_mysql::$db_in_transaction) { db_mysql::db_transaction_rollback(); }
      throw $error;
    }
  }

  public function lockTarget(DispatchContext $context, DispatchRequest $request): ?LockedRiftTarget {
    $prefix = $this->prefix;
    $state = $this->db->doquery("SELECT * FROM `{$prefix}zero_galaxy_state` WHERE id = 1 FOR UPDATE", true);
    $selector = $request->mission === MT_ATTACK ? 'rift_id=' . (int) $request->riftId
      : 'system=' . $request->system . ' AND position=' . $request->position;
    $rift = $this->db->doquery("SELECT * FROM `{$prefix}zero_galaxy_rifts` WHERE {$selector} FOR UPDATE", true);
    if (!$state || !$rift || empty($rift['planet_id'])
      || ($request->mission === MT_SPY && empty($state['npc_owner_id']))
      || ($request->riftId !== null && $request->riftId !== (int) $rift['rift_id'])
      || !in_array(Lifecycle::phase($rift, $context->now), ['active', 'unstable'], true)) {
      return null;
    }
    $sourceId = $request->sourceId ?: (int) $context->actor['current_planet'];
    $ownerId = RiftTarget::ownerId($rift, (int) $state['npc_owner_id']);
    $targetId = (int) $rift['planet_id'];
    $group = $request->groupId ? AcsService::group($request->groupId,
      (int) $context->actor['id'], $rift, $context->settings, true) : null;
    $owners = [(int) $context->actor['id'], $ownerId];
    if ($group) { $owners = array_merge($owners, array_map('intval', explode(',', $group['eingeladen']))); }
    SN::$gc->db->lockRecords(['users' => array_values(array_unique($owners)), 'planets' => [$sourceId, $targetId]]);
    // The legacy fleet helpers read the current player from the global scope.
    $GLOBALS['user'] = $actor = db_user_by_id($context->actor['id'], true);
    $source = DBStaticPlanet::db_planet_by_id($sourceId, true);
    $planet = DBStaticPlanet::db_planet_by_id($targetId, true);
    $npc = db_user_by_id($ownerId, true);
    if (!$source || (int) $source['id_owner'] !== (int) $actor['id']
      || !RiftTarget::matches($rift, $planet, $npc, (int) $state['npc_owner_id'], $context->now)) { return null; }
    $acs = $group ? AcsService::load($request->groupId, (int) $actor['id'], $rift, $context->settings, true) : null;
    return new LockedRiftTarget($actor, $source, $planet, $rift, $acs);
  }

  public function cost(LockedRiftTarget $target, array $ships): int {
    return $target->acs ? AcsPolicy::playerCost($target->acs['fleets'], (int) $target->actor['id'], $ships)
      : AttackPolicy::cost($ships);
  }

  public function inventory(LockedRiftTarget $target, int $unitId): int {
    return max(0, (int) mrc_get_level($target->actor, $target->source, $unitId, false, true));
  }

  public function recyclerFleet(LockedRiftTarget $target, float $debris): array {
    $from = $target->source;
    $from['galaxy'] = 1;
    $available = [];
    $owned = 0;
    foreach (sn_get_groups('flt_recyclers') as $id) {
      $count = $this->inventory($target, $id);
      $owned += $count;
      $single = flt_travel_data($target->actor, $from, $target->planet, [$id => 1], 10);
      if ($count > 0 && $single['fleet_speed'] > 0 && $single['hold'] > 0 && $single['consumption'] > 0) {
        $available[$id] = $count;
      }
    }
    if (!$available) { throw new DispatchFailure('', $owned ? ATTACK_TOO_FAR : ATTACK_NO_RECYCLERS); }
    $selection = flt_calculate_fleet_to_transport($available, $debris, $from, $target->planet);
    $fleet = array_map('intval', $selection['fleet']);
    if (!$fleet || min($fleet) < 1) { throw new DispatchFailure('', ATTACK_NO_FUEL); }
    return [$fleet, $owned];
  }

  public function allowed(LockedRiftTarget $target, array $ships, int $mission, int $speed): int {
    // Keep native fuel admission on the same one-galaxy distance as the actual flight.
    $source = $target->source;
    $source['galaxy'] = 1;
    return flt_can_attack($source, $target->planet, $ships + [RES_DEUTERIUM => 0], $mission, [
      P_FLEET_ATTACK_SPEED_PERCENT_TENTH => $speed, P_FLEET_ATTACK_RES_LIST => [],
      P_FLEET_ATTACK_RESOURCES_SUM => 0, P_FLEET_ATTACK_FLEET_GROUP => 0,
      P_FLEET_ATTACK_FLYING_COUNT => DbFleetStatic::fleet_count_flying($target->actor['id']),
      P_FLEET_ATTACK_TARGET_STRUCTURE => 0, P_FLEET_ATTACK_STAY_TIME => 0,
    ]);
  }

  public function travel(LockedRiftTarget $target, array $ships, int $speed): array {
    return flt_travel_data($target->actor, $target->source, $target->planet, $ships, $speed,
      [], \Universe::getBaseGalaxyDistance());
  }

  public function hasCaptain(LockedRiftTarget $target, int $captainId): bool {
    $module = moduleCaptain();
    $captain = is_object($module) ? $module->unit_captain_get($target->source['id'], LOC_PLANET,
      true, false, $target->actor['id']) : null;
    return $captain && (int) $captain['unit_id'] === $captainId
      && (int) $captain['unit_player_id'] === (int) $target->actor['id']
      && (int) $captain['unit_location_type'] === LOC_PLANET
      && (int) $captain['unit_location_id'] === (int) $target->source['id'];
  }

  public function assignCaptain(int $captainId, int $fleetId): void {
    if (!DBStaticUnit::db_unit_set_by_id($captainId, 'unit_location_type = ' . LOC_FLEET . ', unit_location_id = ' . $fleetId)) {
      throw new RuntimeException('Zero Galaxy captain was not assigned to the fleet');
    }
  }

  public function launch(LockedRiftTarget $target, DispatchRequest $request, array $ships, array $travel, int $now): array {
    if (!db_mysql::$db_in_transaction || !$this->db->transactionCheck()) {
      throw new RuntimeException('Zero Galaxy dispatch requires the caller transaction');
    }
    $groupId = $request->groupId;
    $groupArrival = $target->acs ? (int) $target->acs['group']['ankunft'] : 0;
    $mission = $groupId ? MT_AKS : $request->mission;
    $naturalArrival = $now + (int) $travel['duration'];
    if ($mission === MT_AKS ? ($groupId < 1 || $groupArrival < $naturalArrival)
      : ($groupId !== 0 || $groupArrival !== 0)) { throw new RuntimeException('Invalid Zero Galaxy ACS dispatch'); }
    $arrival = $groupId ? $groupArrival : $naturalArrival;
    $returnAt = $arrival + (int) $travel['duration'];
    $source = $target->source;
    $planet = $target->planet;
    $fleetId = DbFleetStatic::fleet_insert_set_dbq([
      'fleet_owner' => (int) $target->actor['id'], 'fleet_mission' => $mission,
      'fleet_amount' => array_sum($ships), 'fleet_array' => sys_unit_arr2str($ships),
      'fleet_start_time' => $arrival, 'fleet_start_planet_id' => (int) $source['id'],
      'fleet_start_galaxy' => (int) $source['galaxy'], 'fleet_start_system' => (int) $source['system'],
      'fleet_start_planet' => (int) $source['planet'], 'fleet_start_type' => (int) $source['planet_type'],
      'fleet_end_time' => $returnAt, 'fleet_end_planet_id' => (int) $planet['id'],
      'fleet_end_galaxy' => 0, 'fleet_end_system' => (int) $planet['system'],
      'fleet_end_planet' => (int) $planet['planet'], 'fleet_end_type' => $mission === MT_RECYCLE ? PT_DEBRIS : PT_PLANET,
      'fleet_target_owner' => (int) $planet['id_owner'], 'fleet_group' => $groupId, 'start_time' => $now,
    ]);
    if (!$fleetId) { throw new RuntimeException('Zero Galaxy fleet was not created'); }
    $changes = [];
    foreach ($ships as $id => $count) {
      $changes[] = OldDbChangeSet::db_changeset_prepare_unit($id, -$count, $target->actor, $source);
    }
    $fuel = (int) $travel['consumption'];
    if (!DBStaticPlanet::db_planet_set_by_id($source['id'], "deuterium = deuterium - {$fuel}")
      || !OldDbChangeSet::db_changeset_apply(['unit' => $changes])) {
      throw new RuntimeException('Zero Galaxy dispatch resources were not deducted');
    }
    return ['fleet_id' => (int) $fleetId, 'arrival' => $arrival, 'return_at' => $returnAt, 'mission' => $mission];
  }

  public function refreshSource(array $source, int $fuel): array {
    try {
      $current = DBStaticPlanet::db_planet_by_id((int) $source['id']);
      if ($current) { return $current; }
    } catch (Throwable $error) { error_log('Zero Galaxy committed dispatch refresh: ' . $error->getMessage()); }
    $source['deuterium'] -= $fuel;
    return $source;
  }
}
