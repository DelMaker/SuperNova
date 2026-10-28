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

  /**
   * Settings contain normalized, positive inclusive duration ranges.
   */
  public static function create(int $now, array $settings): array {
    $opensAt = $now + random_int($settings['energy_seconds'][0], $settings['energy_seconds'][1]);
    $unstableAt = $opensAt + random_int($settings['active_seconds'][0], $settings['active_seconds'][1]);
    $closesAt = $unstableAt + random_int($settings['unstable_seconds'][0], $settings['unstable_seconds'][1]);
    $disappearsAt = $closesAt + random_int($settings['closed_seconds'][0], $settings['closed_seconds'][1]);

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
    $activeAverage = ($settings['active_seconds'][0] + $settings['active_seconds'][1]) / 2;
    $unstableAverage = ($settings['unstable_seconds'][0] + $settings['unstable_seconds'][1]) / 2;
    if (!empty($settings['categories'])) {
      $weightedDuration = $weight = 0;
      foreach ($settings['categories'] as $category) {
        if ($category['weight'] <= 0 || $category['limit'] === 0) { continue; }
        $ranges = Settings::categoryPhaseRanges($category);
        $weightedDuration += $category['weight'] * (($ranges['active_seconds'][0] + $ranges['active_seconds'][1]
          + $ranges['unstable_seconds'][0] + $ranges['unstable_seconds'][1]) / 2);
        $weight += $category['weight'];
      }
      return $weight > 0 ? $settings['target_active_per_system'] * $interval / ($weightedDuration / $weight) : 0.0;
    }

    return $settings['target_active_per_system'] * $interval / ($activeAverage + $unstableAverage);
  }
}
