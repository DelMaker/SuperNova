<?php

namespace ZeroGalaxy;

use SN;

/** Strict request integers: no booleans, arrays, decimals or exponent notation. */
final class Input {
  public static function int($value): ?int {
    if (!is_scalar($value) || is_bool($value)) {
      return null;
    }
    $value = filter_var($value, FILTER_VALIDATE_INT);
    return $value === false ? null : $value;
  }

  public static function system($value): ?int {
    return self::range($value, (int) SN::$config->game_maxSystem);
  }

  public static function position($value): ?int {
    return self::range($value, (int) SN::$config->game_maxPlanet);
  }

  private static function range($value, int $maximum): ?int {
    $value = self::int($value);
    return $value !== null && $value >= 1 && $value <= $maximum ? $value : null;
  }
}
