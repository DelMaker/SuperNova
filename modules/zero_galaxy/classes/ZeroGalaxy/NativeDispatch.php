<?php

namespace ZeroGalaxy;

use DBAL\db_mysql;
use DBAL\OldDbChangeSet;
use Fleet\DbFleetStatic;
use Planet\DBStaticPlanet;
use RuntimeException;
use SN;
use Throwable;

/** Shared native operations; callers retain their lifecycle locks and transaction. */
class NativeDispatch {
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
    int $targetType = PT_PLANET): array {
    if (!db_mysql::$db_in_transaction || !SN::$db->transactionCheck()) {
      throw new RuntimeException('Zero Galaxy dispatch requires the caller transaction');
    }
    if ($targetType !== ($mission === MT_RECYCLE ? PT_DEBRIS : PT_PLANET)) {
      throw new RuntimeException('Zero Galaxy dispatch target type does not match its mission');
    }
    $arrival = SN_TIME_NOW + (int) $travel['duration'];
    $returnAt = $arrival + (int) $travel['duration'];
    $fleetId = DbFleetStatic::fleet_insert_set_dbq([
      'fleet_owner' => (int) $user['id'], 'fleet_mission' => $mission,
      'fleet_amount' => array_sum($ships), 'fleet_array' => sys_unit_arr2str($ships),
      'fleet_start_time' => $arrival, 'fleet_start_planet_id' => (int) $source['id'],
      'fleet_start_galaxy' => (int) $source['galaxy'], 'fleet_start_system' => (int) $source['system'],
      'fleet_start_planet' => (int) $source['planet'], 'fleet_start_type' => (int) $source['planet_type'],
      'fleet_end_time' => $returnAt, 'fleet_end_planet_id' => (int) $target['id'],
      'fleet_end_galaxy' => 0, 'fleet_end_system' => (int) $target['system'],
      'fleet_end_planet' => (int) $target['planet'], 'fleet_end_type' => $targetType,
      'fleet_target_owner' => (int) $target['id_owner'], 'fleet_group' => 0, 'start_time' => SN_TIME_NOW,
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
    return ['fleet_id' => (int) $fleetId, 'arrival' => $arrival, 'return_at' => $returnAt];
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
