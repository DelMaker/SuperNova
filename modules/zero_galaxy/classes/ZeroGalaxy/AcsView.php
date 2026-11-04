<?php

namespace ZeroGalaxy;

class AcsView {
  public static function decorate($module, $template) {
    if (!is_object($template)) { return $template; }
    $adapter = new NativeTemplateAdapter($template);
    if ($adapter->value('ZERO_GALAXY_ACS_FILTERED')) { return $template; }
    if ($adapter->isNative('fleet1')) {
      $adapter->replaceRows('acss', array_filter($adapter->rows('acss'), static function (array $row): bool {
        return !isset($row['GALAXY']) || !is_numeric($row['GALAXY']) || (int) $row['GALAXY'] !== 0;
      }));
      $template->assign_var('ZERO_GALAXY_ACS_FILTERED', true);
    } elseif ($adapter->isNative('flying_fleets')) {
      $zeroFleets = [];
      foreach ($adapter->rows('fleets') as $row) {
        if (preg_match('/^\[0:[0-9]+:[0-9]+\]$/D', (string) ($row['END_COORDS'] ?? ''))) {
          $zeroFleets[] = (int) $row['ID'];
        }
      }
      if ($zeroFleets) {
        // The native list stays intact; the wrapper only hides its group buttons for these fleets.
        $template->assign_vars(['ZERO_GALAXY_ACS_FILTERED' => true, 'ZERO_ACS_BLOCKED_JSON' => json_encode($zeroFleets)]);
        return $adapter->wrap($module, 'zero_galaxy_flying_fleets');
      }
    }
    return $template;
  }
}
