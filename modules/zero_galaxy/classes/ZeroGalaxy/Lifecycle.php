<?php

namespace ZeroGalaxy;

class Lifecycle {
  // These basenames exist in skins/EpicBlue/planeten/ as JPEG images.
  const IMAGES = [
    'normaltempplanet01',
    'dschjungelplanet04',
    'trockenplanet03',
    'wasserplanet05',
    'eisplanet06',
  ];

  /** @param array $ranges Settings::categoryPhaseRanges() of the rift category. */
  public static function create(int $now, array $ranges): array {
    $opensAt = $now + random_int(...$ranges['energy_seconds']);
    $unstableAt = $opensAt + random_int(...$ranges['active_seconds']);
    $closesAt = $unstableAt + random_int(...$ranges['unstable_seconds']);
    $disappearsAt = $closesAt + random_int(...$ranges['closed_seconds']);

    return [
      'born_at' => $now,
      'opens_at' => $opensAt,
      'unstable_at' => $unstableAt,
      'closes_at' => $closesAt,
      'disappears_at' => $disappearsAt,
      'image' => self::IMAGES[random_int(0, count(self::IMAGES) - 1)],
    ];
  }

  public static function phase(array $rift, int $now): string {
    if ($now < $rift['born_at'] || $now >= $rift['disappears_at']) {
      return '';
    }
    if ($now < $rift['opens_at']) {
      return 'energy';
    }
    if ($now < $rift['unstable_at']) {
      return 'active';
    }
    if ($now < $rift['closes_at']) {
      return 'unstable';
    }

    return 'closed';
  }

  /** Only active and unstable rifts have a native planet that can be scouted or attacked. */
  public static function hasPlanet(string $phase): bool {
    return $phase === 'active' || $phase === 'unstable';
  }

  public static function nextTransition(array $rift, int $now): int {
    if (self::phase($rift, $now) === '') {
      return 0;
    }

    foreach (['opens_at', 'unstable_at', 'closes_at', 'disappears_at'] as $deadline) {
      if ($rift[$deadline] > $now) {
        return (int) $rift[$deadline];
      }
    }

    return 0;
  }

  public static function expectedBirths(array $settings, int $interval): float {
    $weightedDuration = $weight = 0;
    foreach ($settings['categories'] as $category) {
      if ($category['weight'] <= 0 || $category['limit'] === 0) { continue; }
      $ranges = Settings::categoryPhaseRanges($category);
      $weightedDuration += $category['weight']
        * (array_sum($ranges['active_seconds']) + array_sum($ranges['unstable_seconds'])) / 2;
      $weight += $category['weight'];
    }
    return $weight > 0 ? $settings['target_active_per_system'] * $interval / ($weightedDuration / $weight) : 0.0;
  }
}
