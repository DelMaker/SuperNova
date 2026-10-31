<?php

namespace ZeroGalaxy;

/** Hide native ACS controls for zero-galaxy targets while keeping mission data intact. */
class AcsView {
  public static function decorate($module, $template, array $settings) {
    if (!is_object($template) || !empty($settings['acs_enabled'])
      || !empty($template->_rootref['ZERO_GALAXY_ACS_FILTERED'])) {
      return $template;
    }
    if (self::nativeTemplate($template, 'fleet1')) {
      $rows = $template->_tpldata['acss'] ?? [];
      $template->_tpldata['acss'] = [];
      foreach ($rows as $row) {
        if (isset($row['GALAXY']) && is_numeric($row['GALAXY']) && (int) $row['GALAXY'] === 0) {
          continue;
        }
        unset($row['S_ROW_COUNT'], $row['S_FIRST_ROW'], $row['S_LAST_ROW']);
        $template->assign_block_vars('acss', $row);
      }
      $template->assign_var('ZERO_GALAXY_ACS_FILTERED', true);
    } elseif (self::nativeTemplate($template, 'flying_fleets')) {
      $hasZeroFleet = false;
      foreach ($template->_tpldata['fleets'] ?? [] as $index => $row) {
        $zero = preg_match('/^\[0:[0-9]+:[0-9]+\]$/D', (string) ($row['END_COORDS'] ?? '')) === 1;
        $template->_tpldata['fleets'][$index]['ZERO_ACS_ALLOWED'] = !$zero;
        $hasZeroFleet = $hasZeroFleet || $zero;
      }
      if ($hasZeroFleet) {
        $template->assign_var('ZERO_GALAXY_ACS_FILTERED', true);
        // Preserve native mission IDs, labels, CSS classes, timers and recall controls.
        $template->files = $template->filename = $template->files_inherit = [];
        return $module->addModuleTemplate('zero_galaxy_flying_fleets', $template);
      }
    }
    return $template;
  }

  private static function nativeTemplate($template, string $name): bool {
    return isset($template->files[$name]) && is_string($template->files[$name])
      && preg_match('~(?:^|/)' . $name . '\.tpl\.html$~D', str_replace('\\', '/', $template->files[$name])) === 1;
  }
}
