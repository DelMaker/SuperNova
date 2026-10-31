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
$check($defaults['acs_enabled'] === true, 'ACS must be enabled by default.');
$check(Settings::load(['acs_enabled' => false])['acs_enabled'] === false, 'ACS may be disabled globally.');
$check(Settings::load(['acs_enabled' => true])['acs_enabled'] === true, 'ACS may be explicitly enabled.');
$changed = Settings::load(['target_active_per_system' => 0, 'active_seconds' => [3, 9], 'refresh_seconds' => 1]);
$check($changed['target_active_per_system'] === 0.0, 'Zero must disable generation.');
$check($changed['active_seconds'] === [3, 9], 'Overrides must replace the entire duration range.');
$check($changed['energy_seconds'] === $defaults['energy_seconds'], 'Omitted durations must retain defaults.');
$check($changed['refresh_seconds'] === 2, 'Refresh must not cause a tight reload loop.');
$check(Settings::load() === $defaults, 'Overrides must not modify defaults for another request.');

$keys = ['tiny', 'small', 'medium', 'large', 'huge', 'colossal', 'great'];
$check(array_keys($defaults['categories']) === $keys, 'Exactly seven stable category keys are required.');
$check(array_column($defaults['categories'], 'limit') === [-1, -1, -1, -1, 100, 10, 2], 'Global category caps must match the balance.');
$check(array_column($defaults['categories'], 'weight') === [30.0, 25.0, 20.0, 15.0, 7.0, 2.5, 0.5], 'Default weights must preserve fractional rare categories.');
$check($defaults['fleet_limit_multiplier'] === 1.25, 'Default fleet cap multiplier must be 125%.');
$check($defaults['categories']['great']['garrison_points']['mean'] === 268154961162000, 'Great must match 50% of the reference top fleet.');
$check(Settings::pointRange($defaults['categories']['tiny']['garrison_points']) === [64000000, 96000000], 'Tiny cost must range from 64M to 96M.');
$check(Settings::pointRange(['mean' => 1, 'spread_percent' => 100]) === [1, 2], 'A garrison must never round down to zero.');
$check(Settings::categoryPhaseRanges($defaults['categories']['tiny']) === [
  'energy_seconds' => [2160, 3240], 'active_seconds' => [28800, 43200],
  'unstable_seconds' => [7200, 10800], 'closed_seconds' => [4320, 6480],
], 'Category hours must convert to inclusive second ranges.');
$check(Settings::categoryPhaseRanges($defaults['categories']['great'])['active_seconds'] === [57600, 86400], 'Great active phase must last twice as long as tiny.');

$previousPoints = 0;
foreach ($defaults['categories'] as $key => $category) {
  $check($category['garrison_points']['mean'] > $previousPoints, 'Categories must increase in cost: ' . $key);
  $previousPoints = $category['garrison_points']['mean'];
  $check(array_keys($category['tech']) === [106, 109, 110, 111], 'Stationary NPC defaults need only four combat technologies.');
  $check($category['fleet_share_percent'] === 60.0 && $category['resource_percent'] === 75.0,
    'Fleet and resource budgets must be normalized percentages.');
}

$partial = Settings::load(['categories' => ['tiny' => [
  'garrison_points' => ['mean' => '90000000'],
  'tech' => [106 => ['spread' => 4], 115 => ['mean' => 3]],
  'phases_hours' => ['active' => ['mean' => 0.5]],
  'fleet_weights' => [204 => 0, 228 => 2],
  'defence_weights' => [401 => 0],
  'color' => '#abcdef',
  'limit' => 0,
]]]);
$tiny = $partial['categories']['tiny'];
$check($tiny['garrison_points'] === ['mean' => 90000000, 'spread_percent' => 20.0], 'A partial distribution must retain its spread.');
$check($tiny['tech'][106] === ['mean' => 8, 'spread' => 4], 'A partial technology must retain its mean.');
$check($tiny['tech'][115] === ['mean' => 3, 'spread' => 0], 'An optional engine must default to zero absolute spread.');
$check($tiny['tech'][109] === $defaults['categories']['tiny']['tech'][109], 'Other technologies must retain defaults.');
$check(Settings::categoryPhaseRanges($tiny)['active_seconds'] === [1440, 2160], 'One phase may override only its mean.');
$check($tiny['phases_hours']['energy'] === $defaults['categories']['tiny']['phases_hours']['energy'], 'Other phases must retain defaults.');
$check($tiny['fleet_weights'][204] === 0.0 && $tiny['fleet_weights'][228] === 2.0
  && $tiny['fleet_weights'][203] === $defaults['categories']['tiny']['fleet_weights'][203], 'Weights must merge by unit ID without renumbering.');
$check($tiny['defence_weights'][401] === 0.0 && $tiny['defence_weights'][402] > 0, 'One defence type may be disabled without replacing its whole map.');
$check($tiny['color'] === '#ABCDEF' && $tiny['limit'] === 0, 'Color normalization and zero cap must be supported.');
foreach (array_slice($keys, 1) as $key) {
  $check($partial['categories'][$key] === $defaults['categories'][$key], 'A category override must not alter or denormalize its neighbors: ' . $key);
}
$check(Settings::load(['categories' => []]) === $defaults, 'An empty category override must retain all defaults.');
$check(Settings::load($defaults) === $defaults, 'Normalized settings must load without changing their meaning.');
$legacy = Settings::load(['active_seconds' => [3, 9]]);
$check($legacy['categories'] === $defaults['categories'], 'Deprecated seconds must not overwrite category hours.');
$check(Settings::load()['categories'] === $defaults['categories'], 'Nested overrides must not mutate another load.');
$check(Settings::load(['categories' => ['tiny' => ['phases_hours' => ['energy' => ['mean' => 0.000000001]]]]])
  ['categories']['tiny']['phases_hours']['energy']['mean'] > 0, 'Positive subsecond means are supported and clamp to one second.');

foreach ([
  ['acs_enabled' => 0],
  ['acs_enabled' => 1],
  ['acs_enabled' => 'false'],
  ['acs_enabled' => 'true'],
  ['acs_enabled' => null],
  ['acs_enabled' => []],
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
  ['tick_seconds' => true],
  ['fleet_limit_multiplier' => 0],
  ['fleet_limit_multiplier' => INF],
  ['categories' => null],
  ['categories' => ['enormous' => []]],
  ['categories' => ['tiny' => null]],
  ['categories' => ['tiny' => ['typo' => 1]]],
  ['categories' => ['tiny' => ['weight' => -1]]],
  ['categories' => ['tiny' => ['weight' => NAN]]],
  ['categories' => ['tiny' => ['limit' => -2]]],
  ['categories' => ['tiny' => ['limit' => 1.5]]],
  ['categories' => ['tiny' => ['name' => '<b>Tiny</b>']]],
  ['categories' => ['tiny' => ['color' => 'red;display:none']]],
  ['categories' => ['tiny' => ['garrison_points' => ['mean' => 0]]]],
  ['categories' => ['tiny' => ['garrison_points' => ['mean' => 4000000000000001]]]],
  ['categories' => ['tiny' => ['garrison_points' => ['spread_percent' => 101]]]],
  ['categories' => ['tiny' => ['garrison_points' => ['average' => 1]]]],
  ['categories' => ['tiny' => ['fleet_share_percent' => 101]]],
  ['categories' => ['tiny' => ['resource_percent' => -1]]],
  ['categories' => ['tiny' => ['resource_spread_percent' => 101]]],
  ['categories' => ['tiny' => ['tech' => [105 => ['mean' => 3]]]]],
  ['categories' => ['tiny' => ['tech' => [106 => ['spread_percent' => 10]]]]],
  ['categories' => ['tiny' => ['tech' => [106 => ['mean' => 1.5]]]]],
  ['categories' => ['tiny' => ['tech' => [106 => ['spread' => -1]]]]],
  ['categories' => ['tiny' => ['tech' => [106 => ['mean' => 999, 'spread' => 2]]]]],
  ['categories' => ['tiny' => ['fleet_weights' => '204:1']]],
  ['categories' => ['tiny' => ['fleet_weights' => ['unknown' => 1]]]],
  ['categories' => ['tiny' => ['fleet_weights' => [204 => -1]]]],
  ['categories' => ['tiny' => ['defence_weights' => [401 => INF]]]],
  ['categories' => ['tiny' => ['fleet_weights' => array_fill_keys(array_keys($defaults['categories']['tiny']['fleet_weights']), 0)]]],
  ['categories' => ['tiny' => ['phases_hours' => ['missing' => ['mean' => 1]]]]],
  ['categories' => ['tiny' => ['phases_hours' => ['energy' => ['mean' => 0]]]]],
  ['categories' => ['tiny' => ['phases_hours' => ['energy' => ['mean' => 8760]]]]],
  ['categories' => ['tiny' => ['phases_hours' => ['energy' => ['spread_percent' => -1]]]]],
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
