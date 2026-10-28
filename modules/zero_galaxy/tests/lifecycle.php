<?php

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Lifecycle.php';

use ZeroGalaxy\Lifecycle;

$assertions = 0;
$check = function ($condition, $message) use (&$assertions) {
  $assertions++;
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
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
$settingsBefore = $settings;
$rift = Lifecycle::create(1000, $settings);
$check($settings === $settingsBefore, 'Creating a rift must not mutate settings.');
foreach ([
  'born_at' => 1000,
  'opens_at' => 1010,
  'unstable_at' => 1030,
  'closes_at' => 1060,
  'disappears_at' => 1100,
] as $key => $expected) {
  $check($rift[$key] === $expected, 'Incorrect consecutive deadline: ' . $key);
}

$riftBefore = $rift;
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
  [1200, '', 0],
] as $case) {
  list($now, $phase, $transition) = $case;
  $check(Lifecycle::phase($rift, $now) === $phase, 'Incorrect phase at ' . $now);
  $check(Lifecycle::nextTransition($rift, $now) === $transition, 'Incorrect next transition at ' . $now);
}
$check($rift === $riftBefore, 'Reading phases and transitions must not mutate the timeline.');

$changedSettings = $settings;
$changedSettings['energy_seconds'] = [100, 100];
$changedSettings['active_seconds'] = [200, 200];
$changedSettings['unstable_seconds'] = [300, 300];
$changedSettings['closed_seconds'] = [400, 400];
$newRift = Lifecycle::create(1000, $changedSettings);
$check($newRift['disappears_at'] === 2000, 'New settings must determine the new timeline.');
$check($rift === $riftBefore, 'Creating another rift must preserve the old timeline.');
$check(Lifecycle::phase($rift, 1060) === 'closed', 'Changed settings must not affect an existing rift.');

foreach (Lifecycle::IMAGES as $image) {
  $check(preg_match('/^[a-z0-9]+$/D', $image) === 1, 'Image must be a safe extension-free basename.');
  $check(is_file(dirname(__DIR__, 3) . '/skins/EpicBlue/planeten/' . $image . '.jpg'), 'Planet image must exist: ' . $image);
}

$varyingSettings = [
  'energy_seconds' => [1, 19],
  'active_seconds' => [2, 41],
  'unstable_seconds' => [3, 37],
  'closed_seconds' => [4, 53],
  'target_active_per_system' => 2,
];
$stages = [
  ['born_at', 'opens_at', 'energy_seconds', 'energy'],
  ['opens_at', 'unstable_at', 'active_seconds', 'active'],
  ['unstable_at', 'closes_at', 'unstable_seconds', 'unstable'],
  ['closes_at', 'disappears_at', 'closed_seconds', 'closed'],
];
for ($sample = 0; $sample < 200; $sample++) {
  $sampleRift = Lifecycle::create(10000 + $sample, $varyingSettings);
  $check($sampleRift['born_at'] === 10000 + $sample, 'Birth must use the supplied time.');
  $check(in_array($sampleRift['image'], Lifecycle::IMAGES, true), 'Image must come from the verified list.');
  foreach ($stages as $stage) {
    list($starts, $ends, $rangeKey, $phase) = $stage;
    $duration = $sampleRift[$ends] - $sampleRift[$starts];
    $range = $varyingSettings[$rangeKey];
    $check($duration >= $range[0] && $duration <= $range[1], 'Duration outside inclusive range: ' . $rangeKey);
    $check(Lifecycle::phase($sampleRift, $sampleRift[$starts]) === $phase, 'Phase must begin exactly at its deadline.');
    $check(Lifecycle::nextTransition($sampleRift, $sampleRift[$starts]) === $sampleRift[$ends], 'Next transition must be strictly in the future.');
  }
}

$birthSettings = [
  'energy_seconds' => [1, 1000],
  'active_seconds' => [20, 40],
  'unstable_seconds' => [10, 30],
  'closed_seconds' => [1, 2000],
  'target_active_per_system' => 2,
];
$birthSettingsBefore = $birthSettings;
$near(Lifecycle::expectedBirths($birthSettings, 60), 2.4, 'Birth rate must use both active and unstable average durations.');
$near(Lifecycle::expectedBirths($birthSettings, 120), 4.8, 'Birth rate must scale with the interval.');
$near(Lifecycle::expectedBirths($birthSettings, 0), 0.0, 'Zero interval must produce zero expected births.');
$check($birthSettings === $birthSettingsBefore, 'Calculating birth rates must not mutate settings.');
$birthSettings['energy_seconds'] = [10000, 20000];
$birthSettings['closed_seconds'] = [30000, 40000];
$near(Lifecycle::expectedBirths($birthSettings, 60), 2.4, 'Energy and closed phases must not affect active population density.');
$birthSettings['target_active_per_system'] = 0;
$near(Lifecycle::expectedBirths($birthSettings, 60), 0.0, 'Zero target must stop generation.');
$birthSettings['target_active_per_system'] = 2.5;
$near(Lifecycle::expectedBirths($birthSettings, 60), 3.0, 'Fractional population targets must remain supported.');

echo 'Lifecycle: ' . $assertions . " assertions passed.\n";
