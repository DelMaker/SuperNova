<?php

namespace ZeroGalaxy;

use DBAL\db_mysql;
use DBAL\OldDbChangeSet;
use Fleet\DbFleetStatic;
use Planet\DBStaticPlanet;
use RuntimeException;
use SN;
use Throwable;

class NativeDispatch {
  public static function lockTarget(array $user, array $rift, int $legacyOwnerId, int $sourceId,
    array $additionalOwners = []): ?array {
    $ownerId = RiftTarget::ownerId($rift, $legacyOwnerId);
    $targetId = (int) $rift['planet_id'];
    SN::$gc->db->lockRecords([
      'users' => array_values(array_unique(array_merge([(int) $user['id'], $ownerId], $additionalOwners))),
      'planets' => [$sourceId, $targetId],
    ]);
    $user = db_user_by_id($user['id'], true);
    $source = DBStaticPlanet::db_planet_by_id($sourceId, true);
    $target = DBStaticPlanet::db_planet_by_id($targetId, true);
    $npc = db_user_by_id($ownerId, true);
    return $source && (int) $source['id_owner'] === (int) $user['id']
      && RiftTarget::matches($rift, $target, $npc, $legacyOwnerId, SN_TIME_NOW)
      ? [$user, $source, $target] : null;
  }

  public static function allowed(array $user, array $source, array $target, array $ships, int $mission, int $speed): int {
    // Native fuel validation uses the same one-galaxy distance as our travel
    // calculation. Never persist these temporary source coordinates.
    $source['galaxy'] = 1;
    return flt_can_attack($source, $target, $ships + [RES_DEUTERIUM => 0], $mission, [
      P_FLEET_ATTACK_SPEED_PERCENT_TENTH => $speed, P_FLEET_ATTACK_RES_LIST => [],
      P_FLEET_ATTACK_RESOURCES_SUM => 0, P_FLEET_ATTACK_FLEET_GROUP => 0,
      P_FLEET_ATTACK_FLYING_COUNT => DbFleetStatic::fleet_count_flying($user['id']),
      P_FLEET_ATTACK_TARGET_STRUCTURE => 0, P_FLEET_ATTACK_STAY_TIME => 0,
    ]);
  }

  public static function launch(array $user, array $source, array $target, array $ships, int $mission, array $travel,
    int $groupId = 0, int $groupArrival = 0): array {
    if (!db_mysql::$db_in_transaction || !SN::$db->transactionCheck()) {
      throw new RuntimeException('Zero Galaxy dispatch requires the caller transaction');
    }
    $naturalArrival = SN_TIME_NOW + (int) $travel['duration'];
    if ($mission === MT_AKS ? ($groupId < 1 || $groupArrival < $naturalArrival)
      : ($groupId !== 0 || $groupArrival !== 0)) {
      throw new RuntimeException('Invalid Zero Galaxy ACS dispatch');
    }
    $arrival = $groupId ? $groupArrival : $naturalArrival;
    $returnAt = $arrival + (int) $travel['duration'];
    $fleetId = DbFleetStatic::fleet_insert_set_dbq([
      'fleet_owner' => (int) $user['id'], 'fleet_mission' => $mission,
      'fleet_amount' => array_sum($ships), 'fleet_array' => sys_unit_arr2str($ships),
      'fleet_start_time' => $arrival, 'fleet_start_planet_id' => (int) $source['id'],
      'fleet_start_galaxy' => (int) $source['galaxy'], 'fleet_start_system' => (int) $source['system'],
      'fleet_start_planet' => (int) $source['planet'], 'fleet_start_type' => (int) $source['planet_type'],
      'fleet_end_time' => $returnAt, 'fleet_end_planet_id' => (int) $target['id'],
      'fleet_end_galaxy' => 0, 'fleet_end_system' => (int) $target['system'],
      'fleet_end_planet' => (int) $target['planet'], 'fleet_end_type' => $mission === MT_RECYCLE ? PT_DEBRIS : PT_PLANET,
      'fleet_target_owner' => (int) $target['id_owner'], 'fleet_group' => $groupId, 'start_time' => SN_TIME_NOW,
    ]);
    if (!$fleetId) { throw new RuntimeException('Zero Galaxy fleet was not created'); }
    $changes = [];
    foreach ($ships as $id => $count) {
      $changes[] = OldDbChangeSet::db_changeset_prepare_unit($id, -$count, $user, $source);
    }
    $fuel = (int) $travel['consumption'];
    if (!DBStaticPlanet::db_planet_set_by_id($source['id'], "deuterium = deuterium - {$fuel}")
      || !OldDbChangeSet::db_changeset_apply(['unit' => $changes])) {
      throw new RuntimeException('Zero Galaxy dispatch resources were not deducted');
    }
    return ['fleet_id' => (int) $fleetId, 'arrival' => $arrival, 'return_at' => $returnAt, 'mission' => $mission];
  }

  /** A failed display refresh must never turn a committed launch into failure. */
  public static function refreshSource(array $source, int $fuel): array {
    try {
      $current = DBStaticPlanet::db_planet_by_id((int) $source['id']);
      if ($current) { return $current; }
    } catch (Throwable $error) {
      error_log('Zero Galaxy committed dispatch refresh: ' . $error->getMessage());
    }
    $source['deuterium'] -= $fuel;
    return $source;
  }
}
