<?php

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Settings.php';

use ZeroGalaxy\Settings;

$assertions = 0;
$check = function ($condition, $message) use (&$assertions) {
  $assertions++;
  if (!$condition) {
    throw new RuntimeException($message);
  }
};

$defaults = Settings::load();
$check($defaults['target_active_per_system'] === 2.0, 'Default density must be two.');
$changed = Settings::load(['target_active_per_system' => 0, 'active_seconds' => [3, 9], 'refresh_seconds' => 1]);
$check($changed['target_active_per_system'] === 0.0, 'Zero must disable generation.');
$check($changed['active_seconds'] === [3, 9], 'Overrides must replace the entire duration range.');
$check($changed['energy_seconds'] === $defaults['energy_seconds'], 'Omitted durations must retain defaults.');
$check($changed['refresh_seconds'] === 2, 'Refresh must not cause a tight reload loop.');
$check(Settings::load() === $defaults, 'Overrides must not modify defaults for another request.');

foreach ([
  ['target_active_per_system' => -1],
  ['target_active_per_system' => INF],
  ['target_active_per_system' => NAN],
  ['target_active_per_system' => 'many'],
  ['tick_seconds' => 0],
  ['generation_seconds' => -1],
  ['refresh_seconds' => []],
  ['active_seconds' => [10, 2]],
  ['energy_seconds' => [0, 1]],
  ['closed_seconds' => [1]],
  ['unstable_seconds' => '3600'],
  ['unknown_typo' => 1],
] as $invalid) {
  $thrown = false;
  try {
    Settings::load($invalid);
  } catch (InvalidArgumentException $error) {
    $thrown = true;
  }
  $check($thrown, 'Invalid configuration must be rejected: ' . implode(',', array_keys($invalid)));
}

echo "Settings: {$assertions} assertions passed\n";
