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
  private $coordinateDestination;

  public function __construct($db = null) {
    $this->db = $db ?? SN::$db;
    $this->prefix = $this->db->db_prefix;
  }

  public function transaction(callable $action, DispatchRequest $request = null) {
    if (!preg_match('/^[a-zA-Z0-9_]*$/D', $this->prefix)
      || db_mysql::$db_in_transaction || $this->db->transactionCheck()) {
      throw new RuntimeException('Zero Galaxy dispatch cannot start in the current database context');
    }
    // Discover native IDs before SERIALIZABLE reads can lock a planet before its owner.
    $this->coordinateDestination = $request && ($request->nativeForm || in_array($request->mission, [MT_SPY, MT_RECYCLE], true))
      ? DBStaticPlanet::db_planet_by_gspt(0, $request->system, $request->position,
        $request->planetType === PT_MOON ? PT_MOON : PT_PLANET) : null;
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
    if ($request->nativeForm || $request->mission === MT_SPY || $request->mission === MT_RECYCLE) {
      return $state ? $this->lockCoordinateTarget($context, $request) : null;
    }
    $selector = $request->mission === MT_ATTACK ? 'rift_id=' . (int) $request->riftId
      : 'system=' . $request->system . ' AND position=' . $request->position;
    $rift = $this->db->doquery("SELECT * FROM `{$prefix}zero_galaxy_rifts` WHERE {$selector} FOR UPDATE", true);
    if (!$state || !$rift || empty($rift['planet_id'])
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

  private function lockCoordinateTarget(DispatchContext $context, DispatchRequest $request): ?LockedRiftTarget {
    $prefix = $this->prefix;
    $sourceId = $request->sourceId ?: (int) $context->actor['current_planet'];
    $destination = $this->coordinateDestination;
    $rift = $request->mission === MT_ATTACK && $destination
      ? $this->db->doquery("SELECT * FROM `{$prefix}zero_galaxy_rifts` WHERE system={$request->system}
        AND position={$request->position} AND planet_id=" . (int) $destination['id'] . " FOR UPDATE", true) : null;
    $group = $request->groupId ? AcsService::group($request->groupId,
      (int) $context->actor['id'], $rift ?: [], $context->settings, true) : null;
    $owners = [(int) $context->actor['id']];
    if ($group) { $owners = array_merge($owners, array_map('intval', explode(',', $group['eingeladen']))); }
    $planets = [$sourceId];
    if ($destination) {
      $owners[] = (int) $destination['id_owner'];
      $planets[] = (int) $destination['id'];
    }
    SN::$gc->db->lockRecords(['users' => array_values(array_unique($owners)), 'planets' => $planets]);
    $GLOBALS['user'] = $actor = db_user_by_id($context->actor['id'], true);
    $source = DBStaticPlanet::db_planet_by_id($sourceId, true);
    $planet = DBStaticPlanet::db_planet_by_gspt(0, $request->system, $request->position,
      $request->planetType === PT_MOON ? PT_MOON : PT_PLANET);
    if ((int) ($planet['id'] ?? 0) !== (int) ($destination['id'] ?? 0)
      || (int) ($planet['id_owner'] ?? 0) !== (int) ($destination['id_owner'] ?? 0)) {
      $mission = $request->mission === MT_ATTACK ? 'attack' : ($request->mission === MT_SPY ? 'spy' : 'recycle');
      throw new DispatchFailure('zero_galaxy_' . $mission . '_failed');
    }
    if (!$source || (int) $source['id_owner'] !== (int) $actor['id']
      || ($request->mission === MT_SPY && !$planet && !$request->nativeForm)) { return null; }
    $planet = $planet ?: ['id' => 0, 'id_owner' => 0, 'galaxy' => 0, 'system' => $request->system,
      'planet' => $request->position, 'planet_type' => $request->planetType, 'debris_metal' => 0, 'debris_crystal' => 0];
    $acs = $group ? AcsService::load($request->groupId, (int) $actor['id'], $rift, $context->settings, true) : null;
    return new LockedRiftTarget($actor, $source, $planet, $rift ?: [], $acs);
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
    $allowed = flt_can_attack($source, $target->planet, $ships + [RES_DEUTERIUM => 0], $mission, [
      P_FLEET_ATTACK_SPEED_PERCENT_TENTH => $speed, P_FLEET_ATTACK_RES_LIST => [],
      P_FLEET_ATTACK_RESOURCES_SUM => 0, P_FLEET_ATTACK_FLEET_GROUP => 0,
      P_FLEET_ATTACK_FLYING_COUNT => DbFleetStatic::fleet_count_flying($target->actor['id']),
      P_FLEET_ATTACK_TARGET_STRUCTURE => 0, P_FLEET_ATTACK_STAY_TIME => 0,
    ]);
    if ($allowed === ATTACK_NOOB && in_array($mission, [MT_ATTACK, MT_SPY], true)
      && $this->isNpcOwner((int) $target->planet['id_owner'])) {
      return ATTACK_ALLOWED;
    }
    return $allowed;
  }

  private function isNpcOwner(int $ownerId): bool {
    $prefix = $this->prefix;
    return (bool) $this->db->doquery("SELECT u.id FROM `{$prefix}users` u
      WHERE u.id = {$ownerId} AND u.user_bot = " . Storage::NPC_BOT_TYPE . " AND u.authlevel = 0
        AND u.parent_account_id = 0 AND u.parent_account_global = 0
        AND (EXISTS (SELECT 1 FROM `{$prefix}zero_galaxy_npcs` n WHERE n.owner_id = u.id)
          OR u.id = (SELECT npc_owner_id FROM `{$prefix}zero_galaxy_state` WHERE id = 1))", true);
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
      'fleet_end_time' => $returnAt, 'fleet_end_planet_id' => $planet['id'] ? (int) $planet['id'] : null,
      'fleet_end_galaxy' => 0, 'fleet_end_system' => (int) $planet['system'],
      'fleet_end_planet' => (int) $planet['planet'],
      'fleet_end_type' => $mission === MT_RECYCLE ? PT_DEBRIS : ($request->nativeForm ? $request->planetType : PT_PLANET),
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
