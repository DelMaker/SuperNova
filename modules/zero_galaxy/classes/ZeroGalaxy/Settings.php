<?php

namespace ZeroGalaxy;

use InvalidArgumentException;

class Settings {
  const PHASES = ['energy', 'active', 'unstable', 'closed'];
  const TECH_IDS = [106, 108, 109, 110, 111, 113, 114, 115, 117, 118];
  // Оставляем запас для расчёта границ в 64-bit PHP и целых чисел JSON/JS.
  const MAX_POINTS = 4000000000000000;

  public static function load(array $overrides = []) {
    $defaults = require dirname(__DIR__, 2) . '/defaults.php';
    if (array_diff_key($overrides, $defaults)) {
      throw new InvalidArgumentException('Unknown zero galaxy setting');
    }
    $settings = array_replace($defaults, $overrides);

    if (!is_bool($settings['acs_enabled'])) {
      throw new InvalidArgumentException('Invalid boolean: acs_enabled');
    }

    if (!is_numeric($settings['target_active_per_system']) || !is_finite((float) $settings['target_active_per_system'])
      || $settings['target_active_per_system'] < 0 || $settings['target_active_per_system'] > 10000) {
      throw new InvalidArgumentException('Invalid target_active_per_system');
    }
    $settings['target_active_per_system'] = (float) $settings['target_active_per_system'];

    foreach (['tick_seconds', 'generation_seconds', 'refresh_seconds'] as $key) {
      $settings[$key] = self::seconds($settings[$key], $key);
    }
    $settings['refresh_seconds'] = max(2, $settings['refresh_seconds']);
    $settings['fleet_limit_multiplier'] = self::number($settings['fleet_limit_multiplier'],
      'fleet_limit_multiplier', 0.01, 100);

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

    if (!is_array($settings['categories']) || array_diff_key($settings['categories'], $defaults['categories'])) {
      throw new InvalidArgumentException('Unknown or invalid categories');
    }
    // Только выбранные поля выбранной категории заменяются; соседние сохраняются.
    $categories = [];
    foreach ($defaults['categories'] as $key => $base) {
      $category = array_key_exists($key, $settings['categories']) ? $settings['categories'][$key] : [];
      if (!is_array($category)) {
        throw new InvalidArgumentException('Invalid category: ' . $key);
      }
      $categories[$key] = self::category($base, $category, $key);
    }
    $settings['categories'] = $categories;

    return $settings;
  }

  /** Inclusive seconds ranges suitable for array_replace($settings, $ranges). */
  public static function categoryPhaseRanges(array $category): array {
    $ranges = [];
    foreach (self::PHASES as $phase) {
      $distribution = $category['phases_hours'][$phase];
      $mean = $distribution['mean'] * 3600;
      $spread = $distribution['spread_percent'] / 100;
      $ranges[$phase . '_seconds'] = [
        max(1, (int) floor($mean * (1 - $spread))),
        max(1, (int) ceil($mean * (1 + $spread))),
      ];
    }

    return $ranges;
  }

  /** Inclusive integer garrison cost range, with at least one cost unit. */
  public static function pointRange(array $distribution): array {
    $spread = $distribution['spread_percent'] / 100;

    return [max(1, (int) floor($distribution['mean'] * (1 - $spread))),
      max(1, (int) ceil($distribution['mean'] * (1 + $spread)))];
  }

  private static function category(array $defaults, array $override, string $key): array {
    self::knownKeys($override, $defaults, $key);
    $category = array_replace($defaults, $override);
    if (!is_string($category['name']) || trim($category['name']) === ''
      || !preg_match('/^[^<>\x00-\x1F]{1,80}$/uD', $category['name'])) {
      throw new InvalidArgumentException('Invalid category name: ' . $key);
    }
    if (!is_string($category['color']) || !preg_match('/^#[0-9a-f]{6}$/iD', $category['color'])) {
      throw new InvalidArgumentException('Invalid category color: ' . $key);
    }
    $category['color'] = strtoupper($category['color']);
    $category['weight'] = self::number($category['weight'], $key . '.weight', 0, 1000000);
    $category['limit'] = self::integer($category['limit'], $key . '.limit', -1, 2147483647);
    $category['garrison_points'] = self::distribution($defaults['garrison_points'],
      $category['garrison_points'], $key . '.garrison_points', 1, self::MAX_POINTS, true);
    $category['fleet_share_percent'] = self::number($category['fleet_share_percent'],
      $key . '.fleet_share_percent', 0, 100);
    $category['resource_percent'] = self::number($category['resource_percent'],
      $key . '.resource_percent', 0, 10000);
    $category['resource_spread_percent'] = self::number($category['resource_spread_percent'],
      $key . '.resource_spread_percent', 0, 100);

    foreach (['fleet_weights', 'defence_weights'] as $weights) {
      if (!is_array($category[$weights])) {
        throw new InvalidArgumentException('Invalid unit weights: ' . $key . '.' . $weights);
      }
      $category[$weights] = self::unitWeights(array_replace($defaults[$weights], $category[$weights]),
        $key . '.' . $weights);
    }

    if (!is_array($category['tech'])) {
      throw new InvalidArgumentException('Invalid technology settings: ' . $key);
    }
    $tech = array_replace($defaults['tech'], $category['tech']);
    foreach ($tech as $id => $distribution) {
      $id = self::integer($id, $key . '.tech ID', 1, 2147483647);
      if (!in_array($id, self::TECH_IDS, true) || !is_array($distribution)) {
        throw new InvalidArgumentException('Unknown technology: ' . $id);
      }
      $base = $defaults['tech'][$id] ?? ['mean' => 0, 'spread' => 0];
      self::knownKeys($distribution, $base, $key . '.tech.' . $id);
      $distribution = array_replace($base, $distribution);
      $distribution['mean'] = self::integer($distribution['mean'], $key . '.tech.mean', 0, 1000);
      $distribution['spread'] = self::integer($distribution['spread'], $key . '.tech.spread', 0, 1000);
      if ($distribution['mean'] + $distribution['spread'] > 1000) {
        throw new InvalidArgumentException('Technology upper level exceeds 1000: ' . $id);
      }
      $tech[$id] = $distribution;
    }
    $category['tech'] = $tech;

    if (!is_array($category['phases_hours'])) {
      throw new InvalidArgumentException('Invalid phase settings: ' . $key);
    }
    self::knownKeys($category['phases_hours'], $defaults['phases_hours'], $key . '.phases_hours');
    $phases = array_replace($defaults['phases_hours'], $category['phases_hours']);
    foreach (self::PHASES as $phase) {
      $phases[$phase] = self::distribution($defaults['phases_hours'][$phase], $phases[$phase],
        $key . '.phases_hours.' . $phase, 0.000000001, 8760, false);
    }
    $category['phases_hours'] = $phases;
    foreach (self::categoryPhaseRanges($category) as $range) {
      self::seconds($range[1], $key . '.phases_hours upper bound');
    }

    return $category;
  }

  private static function distribution(array $defaults, $value, string $key, $minimum, $maximum, bool $integer): array {
    if (!is_array($value)) {
      throw new InvalidArgumentException('Invalid distribution: ' . $key);
    }
    self::knownKeys($value, $defaults, $key);
    $value = array_replace($defaults, $value);
    $value['mean'] = $integer ? self::integer($value['mean'], $key . '.mean', $minimum, $maximum)
      : self::number($value['mean'], $key . '.mean', $minimum, $maximum);
    $value['spread_percent'] = self::number($value['spread_percent'], $key . '.spread_percent', 0, 100);

    return $value;
  }

  private static function unitWeights(array $weights, string $key): array {
    $normalized = [];
    foreach ($weights as $id => $weight) {
      $id = self::integer($id, $key . ' unit ID', 1, 2147483647);
      $normalized[$id] = self::number($weight, $key . '.' . $id, 0, 1000000);
    }
    if (array_sum($normalized) <= 0) {
      throw new InvalidArgumentException('At least one positive unit weight is required: ' . $key);
    }

    return $normalized;
  }

  private static function knownKeys(array $value, array $defaults, string $key): void {
    if (array_diff_key($value, $defaults)) {
      throw new InvalidArgumentException('Unknown setting: ' . $key);
    }
  }

  private static function number($value, string $key, $minimum, $maximum): float {
    if (!is_numeric($value) || !is_finite((float) $value) || $value < $minimum || $value > $maximum) {
      throw new InvalidArgumentException('Invalid number: ' . $key);
    }

    return (float) $value;
  }

  private static function integer($value, string $key, $minimum, $maximum): int {
    if (is_bool($value) || !is_scalar($value)) {
      throw new InvalidArgumentException('Invalid integer: ' . $key);
    }
    $value = filter_var($value, FILTER_VALIDATE_INT);
    if ($value === false || $value < $minimum || $value > $maximum) {
      throw new InvalidArgumentException('Invalid integer: ' . $key);
    }

    return $value;
  }

  private static function seconds($value, $key) {
    return self::integer($value, $key, 1, 31536000);
  }
}
