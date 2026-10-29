<?php

namespace ZeroGalaxy;

class RiftBattleRules {
  /** Native capture hook: runs after calculation but before report and DB apply. */
  public static function beforeApply(&$fleet, &$combat, $result = null) {
    if (!isset($combat[UBE_OUTCOME][UBE_PLANET][PLANET_GALAXY])
      || (int) $combat[UBE_OUTCOME][UBE_PLANET][PLANET_GALAXY] !== 0) {
      return $result;
    }

    $outcome = &$combat[UBE_OUTCOME];
    // Native moon rolls can spend debris. Restore the original debris before
    // suppressing the moon so the native result application keeps all of it.
    if (isset($outcome[UBE_DEBRIS_ORIGINAL]) && is_array($outcome[UBE_DEBRIS_ORIGINAL])) {
      $outcome[UBE_DEBRIS] = $outcome[UBE_DEBRIS_ORIGINAL];
    }
    $outcome[UBE_DEBRIS_TOTAL] = array_sum($outcome[UBE_DEBRIS] ?? []);
    $outcome[UBE_MOON] = UBE_MOON_NONE;
    $outcome[UBE_MOON_CHANCE] = 0;
    $outcome[UBE_MOON_SIZE] = 0;
    $outcome[UBE_MOON_REAPERS] = UBE_MOON_REAPERS_NONE;
    $combat[UBE_OPTIONS][UBE_DEFENDER_ACTIVE] = true;

    return $result;
  }
}
