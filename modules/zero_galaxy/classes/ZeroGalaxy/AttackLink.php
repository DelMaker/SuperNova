<?php

namespace ZeroGalaxy;

/** Recognize the native attack URL used by existing espionage reports. */
class AttackLink {
  public static function nativeCoordinates(string $page, string $method, array $parameters,
    int $maxSystem, int $maxPlanet): ?array {
    if ($page !== 'fleet' || $method !== 'GET') { return null; }
    $galaxy = self::integer($parameters['galaxy'] ?? null);
    $mission = self::integer($parameters['target_mission'] ?? null);
    $type = self::integer($parameters['planet_type'] ?? $parameters['planettype'] ?? 1);
    $step = self::integer($parameters['fleet_page'] ?? $parameters['mode'] ?? 0);
    $system = self::integer($parameters['system'] ?? null);
    $position = self::integer($parameters['planet'] ?? null);
    if ($galaxy !== 0 || $mission !== 1 || $type !== 1 || $step !== 0
      || $system === null || $system < 1 || $system > $maxSystem
      || $position === null || $position < 1 || $position > $maxPlanet) {
      return null;
    }
    return ['system' => $system, 'position' => $position];
  }

  private static function integer($value): ?int {
    if (!is_scalar($value) || is_bool($value)) { return null; }
    $integer = filter_var($value, FILTER_VALIDATE_INT);
    return $integer === false ? null : $integer;
  }
}
