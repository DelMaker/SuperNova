<?php

require __DIR__ . '/support/bootstrap.php';

use ZeroGalaxy\Lifecycle;
use ZeroGalaxy\Settings;

$near = function ($actual, $expected, $message) use ($check) {
  $check(abs($actual - $expected) < 0.000000001, $message);
};

$rift = Lifecycle::create(1000, ['energy_seconds' => [10, 10], 'active_seconds' => [20, 20],
  'unstable_seconds' => [30, 30], 'closed_seconds' => [40, 40]]);
foreach ([
  'born_at' => 1000,
  'opens_at' => 1010,
  'unstable_at' => 1030,
  'closes_at' => 1060,
  'disappears_at' => 1100,
] as $key => $expected) {
  $check($rift[$key] === $expected, 'Incorrect consecutive deadline: ' . $key);
}

foreach ([
  [999, '', 0],
  [1000, 'energy', 1010],
  [1009, 'energy', 1010],
  [1010, 'active', 1030],
  [1029, 'active', 1030],
  [1030, 'unstable', 1060],
  [1059, 'unstable', 1060],
  [1060, 'closed', 1100],
  [1099, 'closed', 1100],
  [1100, '', 0],
] as [$now, $phase, $transition]) {
  $check(Lifecycle::phase($rift, $now) === $phase, 'Incorrect phase at ' . $now);
  $check(Lifecycle::nextTransition($rift, $now) === $transition, 'Incorrect next transition at ' . $now);
  $check(Lifecycle::hasPlanet($phase) === in_array($phase, ['active', 'unstable'], true),
    'Only active and unstable phases have a planet: ' . $phase);
}

$ranges = ['energy_seconds' => [1, 19], 'active_seconds' => [2, 41], 'unstable_seconds' => [3, 37], 'closed_seconds' => [4, 53]];
$sampleRift = Lifecycle::create(10000, $ranges);
foreach ([
  ['born_at', 'opens_at', 'energy_seconds'],
  ['opens_at', 'unstable_at', 'active_seconds'],
  ['unstable_at', 'closes_at', 'unstable_seconds'],
  ['closes_at', 'disappears_at', 'closed_seconds'],
] as [$starts, $ends, $rangeKey]) {
  $duration = $sampleRift[$ends] - $sampleRift[$starts];
  [$minimum, $maximum] = $ranges[$rangeKey];
  $check($duration >= $minimum && $duration <= $maximum, 'Duration outside inclusive range: ' . $rangeKey);
}

// Hours below are exact binary fractions, so each phase has a single whole-second duration.
$category = function (float $weight, float $active, float $unstable, int $limit = -1) {
  $category = Settings::load()['categories']['tiny'];
  foreach (['energy' => 0.125, 'active' => $active, 'unstable' => $unstable, 'closed' => 0.25] as $phase => $hours) {
    $category['phases_hours'][$phase] = ['mean' => $hours, 'spread_percent' => 0];
  }
  return ['weight' => $weight, 'limit' => $limit] + $category;
};
$settings = ['target_active_per_system' => 2, 'categories' => ['one' => $category(1, 0.5, 0.25)]];
$near(Lifecycle::expectedBirths($settings, 2700), 2.0, 'Birth rate must use both active and unstable durations.');
$settings['categories']['one']['phases_hours']['energy']['mean'] = 4;
$settings['categories']['one']['phases_hours']['closed']['mean'] = 8;
$near(Lifecycle::expectedBirths($settings, 2700), 2.0, 'Energy and closed phases must not affect active population density.');
$settings['categories']['two'] = $category(3, 1, 0.5);
$near(Lifecycle::expectedBirths($settings, 2700), 2 * 2700 / ((2700 + 3 * 5400) / 4),
  'Categories must contribute by their configured weight.');
$settings['categories']['disabled'] = $category(0, 100, 100);
$settings['categories']['closed'] = $category(5, 100, 100, 0);
$near(Lifecycle::expectedBirths($settings, 2700), 2 * 2700 / ((2700 + 3 * 5400) / 4),
  'Categories that cannot be born must not dilute the population target.');
$settings['target_active_per_system'] = 0;
$near(Lifecycle::expectedBirths($settings, 2700), 0.0, 'Zero target must stop generation.');
$near(Lifecycle::expectedBirths(['target_active_per_system' => 2, 'categories' => []], 2700), 0.0,
  'An empty catalog must not produce births.');
$settings = ['target_active_per_system' => 2.5, 'categories' => ['one' => $category(1, 0.5, 0.25)]];
$near(Lifecycle::expectedBirths($settings, 2700), 2.5, 'Fractional population targets must remain supported.');

echo "Lifecycle: {$assertions} assertions passed.\n";
