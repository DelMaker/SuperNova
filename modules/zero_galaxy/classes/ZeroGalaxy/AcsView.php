<?php

namespace ZeroGalaxy;

class AcsView {
  public static function decorate($module, $template, array $settings) {
    if (!is_object($template) || !empty($settings['acs_enabled'])) { return $template; }
    $adapter = new NativeTemplateAdapter($template);
    if ($adapter->value('ZERO_GALAXY_ACS_FILTERED')) { return $template; }
    if ($adapter->isNative('fleet1')) {
      $adapter->replaceRows('acss', array_filter($adapter->rows('acss'), static function (array $row): bool {
        return !isset($row['GALAXY']) || !is_numeric($row['GALAXY']) || (int) $row['GALAXY'] !== 0;
      }));
      $template->assign_var('ZERO_GALAXY_ACS_FILTERED', true);
    } elseif ($adapter->isNative('flying_fleets')) {
      $hasZeroFleet = false;
      $adapter->mapRows('fleets', static function (array $row) use (&$hasZeroFleet): array {
        $zero = preg_match('/^\[0:[0-9]+:[0-9]+\]$/D', (string) ($row['END_COORDS'] ?? '')) === 1;
        $row['ZERO_ACS_ALLOWED'] = !$zero;
        $hasZeroFleet = $hasZeroFleet || $zero;
        return $row;
      });
      if ($hasZeroFleet) {
        $template->assign_var('ZERO_GALAXY_ACS_FILTERED', true);
        return $adapter->wrap($module, 'zero_galaxy_flying_fleets');
      }
    }
    return $template;
  }
}
