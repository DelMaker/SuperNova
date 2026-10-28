<?php

namespace ZeroGalaxy;

use InvalidArgumentException;

class Settings {
  public static function load(array $overrides = []) {
    $defaults = require dirname(__DIR__, 2) . '/defaults.php';
    if (array_diff_key($overrides, $defaults)) {
      throw new InvalidArgumentException('Unknown zero galaxy setting');
    }
    $settings = array_replace($defaults, $overrides);

    if (!is_numeric($settings['target_active_per_system']) || !is_finite((float) $settings['target_active_per_system'])
      || $settings['target_active_per_system'] < 0 || $settings['target_active_per_system'] > 10000) {
      throw new InvalidArgumentException('Invalid target_active_per_system');
    }
    $settings['target_active_per_system'] = (float) $settings['target_active_per_system'];

    foreach (['tick_seconds', 'generation_seconds', 'refresh_seconds'] as $key) {
      $settings[$key] = self::seconds($settings[$key], $key);
    }
    $settings['refresh_seconds'] = max(2, $settings['refresh_seconds']);

    foreach (['energy_seconds', 'active_seconds', 'unstable_seconds', 'closed_seconds'] as $key) {
      if (!is_array($settings[$key]) || count($settings[$key]) !== 2) {
        throw new InvalidArgumentException('Invalid range: ' . $key);
      }
      $range = array_values($settings[$key]);
      $settings[$key] = [self::seconds($range[0], $key), self::seconds($range[1], $key)];
      if ($settings[$key][0] > $settings[$key][1]) {
        throw new InvalidArgumentException('Reversed range: ' . $key);
      }
    }

    return $settings;
  }

  private static function seconds($value, $key) {
    $value = filter_var($value, FILTER_VALIDATE_INT);
    if ($value === false || $value < 1 || $value > 31536000) {
      throw new InvalidArgumentException('Invalid seconds: ' . $key);
    }

    return $value;
  }
}
