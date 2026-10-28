<?php

namespace ZeroGalaxy;

use RuntimeException;
use InvalidArgumentException;

/** An immutable initial garrison; later missions change the native planet, not this snapshot. */
class Profile {
  public static function validateSettings(array $settings): void {
    try {
      foreach ($settings['categories'] as $category) {
        foreach (['fleet_weights' => 200, 'defence_weights' => 400] as $key => $type) {
          foreach ($category[$key] as $id => $weight) {
            if ($weight > 0) { self::nativeUnit((int) $id, $type); }
          }
        }
      }
    } catch (RuntimeException $error) {
      throw new InvalidArgumentException($error->getMessage(), 0, $error);
    }
  }
  public static function read(array $rift): ?array {
    $snapshot = json_decode($rift['config_snapshot'] ?? '', true);
    return isset($snapshot['profile']['version']) && $snapshot['profile']['version'] === 1
      ? $snapshot['profile'] : null;
  }

  public static function choose(array $categories, array $counts): ?string {
    $available = [];
    $total = 0;
    foreach ($categories as $key => $category) {
      if ($category['weight'] <= 0 || ($category['limit'] !== -1 && ($counts[$key] ?? 0) >= $category['limit'])) {
        continue;
      }
      $total += $category['weight'];
      $available[$key] = $total;
    }
    if (!$available) { return null; }
    $roll = random_int(1, 1000000000) / 1000000000 * $total;
    foreach ($available as $key => $boundary) {
      if ($roll <= $boundary) { return $key; }
    }
    return array_key_last($available);
  }

  /** Uses the same weighted resource cost as the native statistics. */
  public static function nativeUnit(int $id, int $type): array {
    $unit = get_unit_param($id);
    if (!$unit || (int) ($unit['type'] ?? 0) !== $type || (float) ($unit['cost']['factor'] ?? 1) !== 1.0) {
      throw new RuntimeException('Invalid zero galaxy garrison unit: ' . $id);
    }
    $cost = eco_get_total_cost($id, 1)['total'] ?? 0;
    if (!is_numeric($cost) || !is_finite((float) $cost) || $cost <= 0) {
      throw new RuntimeException('Zero galaxy unit has no finite resource cost: ' . $id);
    }
    return ['cost' => (float) $cost, 'max' => max(0, (int) ($unit['max'] ?? 0))];
  }

  private static function army(array $weights, float $budget, int $type, callable $unitInfo): array {
    $prices = $limits = $drawn = [];
    foreach ($weights as $id => $weight) {
      if ($weight <= 0) { continue; }
      $info = $unitInfo((int) $id, $type);
      $prices[$id] = $info['cost'];
      $limits[$id] = $info['max'];
      // Independent compositions make reconnaissance useful within one category.
      $drawn[$id] = $weight * random_int(50, 150) / 100;
    }
    if (!$drawn) { throw new RuntimeException('Zero galaxy army has no enabled units'); }
    $totalWeight = array_sum($drawn);
    $army = [];
    $spent = 0;
    foreach ($drawn as $id => $weight) {
      $count = (int) floor($budget * $weight / $totalWeight / $prices[$id]);
      if ($limits[$id] > 0) { $count = min($count, $limits[$id]); }
      if ($count > 0) { $army[$id] = $count; $spent += $count * $prices[$id]; }
    }
    asort($prices, SORT_NUMERIC);
    foreach ($prices as $id => $price) {
      $additional = (int) floor(max(0, $budget - $spent) / $price);
      if ($limits[$id] > 0) { $additional = min($additional, $limits[$id] - ($army[$id] ?? 0)); }
      if ($additional > 0) { $army[$id] = ($army[$id] ?? 0) + $additional; $spent += $additional * $price; }
    }
    return ['units' => $army, 'points' => (int) round($spent)];
  }

  /** Unit resolver and exchange rates are explicit so generation can be checked without a game bootstrap. */
  public static function create(string $key, array $category, float $limitMultiplier, array $rates,
    callable $unitInfo = null): array {
    $unitInfo = $unitInfo ?? [self::class, 'nativeUnit'];
    [$minimum, $maximum] = Settings::pointRange($category['garrison_points']);
    $target = random_int($minimum, $maximum);
    $fleetBudget = floor($target * $category['fleet_share_percent'] / 100);
    $fleet = self::army($category['fleet_weights'], $fleetBudget, 200, $unitInfo);
    $defence = self::army($category['defence_weights'], $target - $fleetBudget, 400, $unitInfo);
    $tech = [];
    foreach ($category['tech'] as $id => $distribution) {
      $tech[$id] = random_int(max(0, $distribution['mean'] - $distribution['spread']),
        $distribution['mean'] + $distribution['spread']);
    }
    $spread = $category['resource_spread_percent'] / 100;
    $resourceRatio = $category['resource_percent'] / 100 * (1 + random_int(-1000000, 1000000) / 1000000 * $spread);
    $equivalent = $fleet['points'] * $resourceRatio;
    $resources = [];
    foreach ([901 => .60, 902 => .25, 903 => .15] as $id => $share) {
      if (!isset($rates[$id]) || !is_finite((float) $rates[$id]) || $rates[$id] <= 0) {
        throw new RuntimeException('Invalid zero galaxy resource exchange rate');
      }
      $resources[$id] = (int) floor($equivalent * $share / $rates[$id]);
    }
    $points = $fleet['points'] + $defence['points'];
    return [
      'version' => 1,
      'category' => ['key' => $key, 'name' => $category['name'], 'color' => $category['color']],
      'target_points' => $target, 'garrison_points' => $points,
      'fleet_points' => $fleet['points'], 'defence_points' => $defence['points'],
      'fleet_limit' => (int) floor($points * $limitMultiplier),
      'fleet' => $fleet['units'], 'defence' => $defence['units'], 'tech' => $tech,
      'resources' => $resources,
    ];
  }
}
