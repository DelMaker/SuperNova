<?php

require __DIR__ . '/support/bootstrap.php';

use ZeroGalaxy\Settings;

$defaults = Settings::load();
$check(!array_key_exists('acs_enabled', $defaults), 'ACS must have no current configuration switch.');
$changed = Settings::load(['target_active_per_system' => 0, 'refresh_seconds' => 1]);
$check($changed['target_active_per_system'] === 0.0, 'Zero must disable generation.');
$check($changed['refresh_seconds'] === 2, 'Refresh must not cause a tight reload loop.');

$check(Settings::pointRange(['mean' => 1, 'spread_percent' => 100]) === [1, 2], 'A garrison must never round down to zero.');

$partial = Settings::load(['categories' => ['tiny' => [
  'garrison_points' => ['mean' => '90000000'],
  'tech' => [106 => '12', 115 => 3, 109 => 0],
  'phases_hours' => ['active' => ['mean' => 0.5]],
  'fleet_weights' => [204 => 0, 228 => 2],
  'defence_weights' => [401 => 0],
  'color' => '#abcdef',
  'limit' => 0,
]]]);
$tiny = $partial['categories']['tiny'];
$check($tiny['garrison_points'] === ['mean' => 90000000, 'spread_percent' => 20.0], 'A partial distribution must retain its spread.');
$check($tiny['tech'] === [106 => 12, 115 => 3, 109 => 0], 'A technology override must replace the static map, including zero levels.');
$check(Settings::categoryPhaseRanges($tiny)['active_seconds'] === [1440, 2160], 'One phase may override only its mean.');
$check($tiny['phases_hours']['energy'] === $defaults['categories']['tiny']['phases_hours']['energy'], 'Other phases must retain defaults.');
$check($tiny['fleet_weights'][204] === 0.0 && $tiny['fleet_weights'][228] === 2.0
  && $tiny['fleet_weights'][203] === $defaults['categories']['tiny']['fleet_weights'][203], 'Weights must merge by unit ID without renumbering.');
$check($tiny['defence_weights'][401] === 0.0 && $tiny['defence_weights'][402] > 0, 'One defence type may be disabled without replacing its whole map.');
$check($tiny['color'] === '#ABCDEF' && $tiny['limit'] === 0, 'Color normalization and zero cap must be supported.');
$check($partial['categories']['great'] === $defaults['categories']['great'], 'A category override must preserve its neighbors.');
$check(!isset(Settings::load(['categories' => ['tiny' => null]])['categories']['tiny']), 'Null must remove a configured category.');
$check(Settings::load(['categories' => array_fill_keys(array_keys($defaults['categories']), null)])['categories'] === [], 'All category types may be disabled by removal.');
$check(Settings::load(['categories' => ['tiny' => ['tech' => []]]])['categories']['tiny']['tech'] === [],
  'An empty technology map must remove all configured research.');
$new = $defaults['categories']['tiny'];
$new['name'] = 'Особый';
$new['garrison_points']['mean'] = 500;
$new['fleet_weights'] = [204 => 1.0];
$new['tech'] = [106 => 1000];
$catalog = Settings::load(['categories' => ['custom_1' => $new]]);
$check($catalog['categories']['custom_1'] === $new, 'A complete new category must preserve its own balance without inherited units.');
$subsecond = Settings::load(['categories' => ['tiny' => ['phases_hours' => ['energy' => ['mean' => 0.000000001]]]]]);
$check(Settings::categoryPhaseRanges($subsecond['categories']['tiny'])['energy_seconds'] === [1, 1],
  'Positive subsecond means must produce a usable one-second phase.');

foreach ([
  ['target_active_per_system' => INF],
  ['tick_seconds' => 0],
  ['unknown_typo' => 1],
  ['tick_seconds' => true],
  // Removed settings must be deleted from config.php rather than silently ignored.
  ['acs_enabled' => false],
  ['active_seconds' => [3, 9]],
  ['categories' => ['Bad-Key' => $new]],
  ['categories' => ['new' => array_diff_key($new, ['tech' => true])]],
  ['categories' => ['tiny' => ['typo' => 1]]],
  ['categories' => ['tiny' => ['weight' => NAN]]],
  ['categories' => ['tiny' => ['limit' => 1.5]]],
  ['categories' => ['tiny' => ['name' => '<b>Tiny</b>']]],
  ['categories' => ['tiny' => ['color' => 'red;display:none']]],
  ['categories' => ['tiny' => ['garrison_points' => ['mean' => 4000000000000001]]]],
  ['categories' => ['tiny' => ['garrison_points' => ['spread_percent' => 101]]]],
  ['categories' => ['tiny' => ['garrison_points' => ['average' => 1]]]],
  ['categories' => ['tiny' => ['tech' => [105 => 3]]]],
  ['categories' => ['tiny' => ['tech' => [106 => ['mean' => 8, 'spread' => 2]]]]],
  ['categories' => ['tiny' => ['tech' => [106 => 1.5]]]],
  ['categories' => ['tiny' => ['tech' => [106 => 1001]]]],
  ['categories' => ['tiny' => ['fleet_weights' => ['unknown' => 1]]]],
  ['categories' => ['tiny' => ['fleet_weights' => array_fill_keys(array_keys($defaults['categories']['tiny']['fleet_weights']), 0)]]],
  ['categories' => ['tiny' => ['phases_hours' => ['missing' => ['mean' => 1]]]]],
  ['categories' => ['tiny' => ['phases_hours' => ['energy' => ['mean' => 8760]]]]],
] as $invalid) {
  zgExpect(function () use ($invalid) { Settings::load($invalid); }, InvalidArgumentException::class,
    'Invalid configuration must be rejected: ' . implode(',', array_keys($invalid)));
}

echo "Settings: {$assertions} assertions passed\n";
