<?php

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Lifecycle.php';

use ZeroGalaxy\Lifecycle;

require __DIR__ . '/support/assertions.php';
$near = function ($actual, $expected, $message) use ($check) {
  $check(abs($actual - $expected) < 0.000000001, $message);
};

$settings = [
  'energy_seconds' => [10, 10],
  'active_seconds' => [20, 20],
  'unstable_seconds' => [30, 30],
  'closed_seconds' => [40, 40],
  'target_active_per_system' => 2,
];
$rift = Lifecycle::create(1000, $settings);
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
] as $case) {
  list($now, $phase, $transition) = $case;
  $check(Lifecycle::phase($rift, $now) === $phase, 'Incorrect phase at ' . $now);
  $check(Lifecycle::nextTransition($rift, $now) === $transition, 'Incorrect next transition at ' . $now);
}

$varyingSettings = [
  'energy_seconds' => [1, 19],
  'active_seconds' => [2, 41],
  'unstable_seconds' => [3, 37],
  'closed_seconds' => [4, 53],
  'target_active_per_system' => 2,
];
$sampleRift = Lifecycle::create(10000, $varyingSettings);
foreach ([
  ['born_at', 'opens_at', 'energy_seconds'],
  ['opens_at', 'unstable_at', 'active_seconds'],
  ['unstable_at', 'closes_at', 'unstable_seconds'],
  ['closes_at', 'disappears_at', 'closed_seconds'],
] as [$starts, $ends, $rangeKey]) {
  $duration = $sampleRift[$ends] - $sampleRift[$starts];
  [$minimum, $maximum] = $varyingSettings[$rangeKey];
  $check($duration >= $minimum && $duration <= $maximum, 'Duration outside inclusive range: ' . $rangeKey);
}

$birthSettings = [
  'energy_seconds' => [1, 1000],
  'active_seconds' => [20, 40],
  'unstable_seconds' => [10, 30],
  'closed_seconds' => [1, 2000],
  'target_active_per_system' => 2,
];
$near(Lifecycle::expectedBirths($birthSettings, 60), 2.4, 'Birth rate must use both active and unstable average durations.');
$birthSettings['energy_seconds'] = [10000, 20000];
$birthSettings['closed_seconds'] = [30000, 40000];
$near(Lifecycle::expectedBirths($birthSettings, 60), 2.4, 'Energy and closed phases must not affect active population density.');
$birthSettings['target_active_per_system'] = 0;
$near(Lifecycle::expectedBirths($birthSettings, 60), 0.0, 'Zero target must stop generation.');
$birthSettings['target_active_per_system'] = 2.5;
$near(Lifecycle::expectedBirths($birthSettings, 60), 3.0, 'Fractional population targets must remain supported.');

echo 'Lifecycle: ' . $assertions . " assertions passed.\n";
