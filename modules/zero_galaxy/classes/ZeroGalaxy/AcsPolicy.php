<?php

namespace ZeroGalaxy;

use InvalidArgumentException;

/** Pure checks shared by ACS previews, launch validation and arrivals. */
class AcsPolicy {
  public static function enabled(array $settings): bool {
    return ($settings['acs_enabled'] ?? false) === true;
  }

  /** Pass one group's current rows; a player's several fleets share one cap. */
  public static function playerCost(array $fleetRows, int $userId, array $extraShips = [], callable $cost = null): int {
    if ($userId < 1) { throw new InvalidArgumentException('Invalid ACS player'); }
    $ships = [];
    foreach ($fleetRows as $fleet) {
      if (!is_array($fleet)) { throw new InvalidArgumentException('Invalid ACS fleet row'); }
      if (!self::integerEquals($fleet['fleet_owner'] ?? null, $userId)) { continue; }
      if (!self::nonnegativeInteger($fleet['fleet_mess'] ?? null)) {
        throw new InvalidArgumentException('Invalid ACS fleet status');
      }
      if ((int) $fleet['fleet_mess'] !== FLEET_STATUS_FLYING) { continue; }
      self::mergeShips($ships, self::fleetShips($fleet['fleet_array'] ?? null));
    }
    if ($extraShips) { self::mergeShips($ships, AttackPolicy::ships($extraShips)); }
    // Rounding each subfleet separately would let fractional prices evade the cap.
    return AttackPolicy::cost($ships, $cost);
  }

  public static function validMember(array $fleet, array $rift, int $groupId, int $arrival): bool {
    if ($groupId < 1 || $arrival < 1
      || !self::nonnegativeInteger($fleet['fleet_id'] ?? null) || (int) $fleet['fleet_id'] < 1
      || !self::nonnegativeInteger($fleet['fleet_owner'] ?? null) || (int) $fleet['fleet_owner'] < 1
      || !self::nonnegativeInteger($rift['system'] ?? null) || (int) $rift['system'] < 1
      || !self::nonnegativeInteger($rift['position'] ?? null) || (int) $rift['position'] < 1) {
      return false;
    }
    foreach (['fleet_group' => $groupId, 'fleet_mission' => MT_AKS, 'fleet_mess' => FLEET_STATUS_FLYING,
      'fleet_end_galaxy' => 0, 'fleet_end_system' => (int) $rift['system'],
      'fleet_end_planet' => (int) $rift['position'], 'fleet_end_type' => PT_PLANET,
      'fleet_start_time' => $arrival] as $field => $expected) {
      if (!self::integerEquals($fleet[$field] ?? null, $expected)) { return false; }
    }
    // Native ACS has no holding stage; ETA may already be due at dispatch.
    return !isset($fleet['fleet_end_stay']) || self::integerEquals($fleet['fleet_end_stay'], 0);
  }

  private static function fleetShips($encoded): array {
    if (!is_string($encoded) || $encoded === '' || strlen($encoded) > 16000) {
      throw new InvalidArgumentException('Invalid ACS fleet composition');
    }
    $ships = [];
    foreach (explode(';', $encoded) as $stack) {
      if ($stack === '') { continue; }
      if (!preg_match('/^([1-9][0-9]*),([0-9]+)$/D', $stack, $match)) {
        throw new InvalidArgumentException('Invalid ACS fleet composition');
      }
      self::mergeShips($ships, AttackPolicy::ships([$match[1] => $match[2]]));
    }
    return AttackPolicy::ships($ships);
  }

  private static function mergeShips(array &$ships, array $additional): void {
    foreach ($additional as $id => $count) {
      $before = $ships[$id] ?? 0;
      if ($count > PHP_INT_MAX - $before) {
        throw new InvalidArgumentException('ACS fleet quantity exceeds the supported range');
      }
      $ships[$id] = $before + $count;
    }
  }

  private static function nonnegativeInteger($value): bool {
    return is_int($value) ? $value >= 0 : is_string($value)
      && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1
      && filter_var($value, FILTER_VALIDATE_INT) !== false;
  }

  private static function integerEquals($value, int $expected): bool {
    return self::nonnegativeInteger($value) && (int) $value === $expected;
  }
}
