<?php

require __DIR__ . '/support/bootstrap.php';

use ZeroGalaxy\Profile;
use ZeroGalaxy\Settings;

$rates = [901 => 1, 902 => 2, 903 => 4];
$info = [
  202 => ['type' => 200, 'cost' => 7, 'max' => 0],
  203 => ['type' => 200, 'cost' => 13, 'max' => 0],
  204 => ['type' => 200, 'cost' => 3, 'max' => 2],
  401 => ['type' => 400, 'cost' => 11, 'max' => 0],
  402 => ['type' => 400, 'cost' => 17, 'max' => 0],
  403 => ['type' => 400, 'cost' => 5, 'max' => 3],
];
$resolver = function (int $id, int $type) use ($info): array {
  if (!isset($info[$id]) || $info[$id]['type'] !== $type) {
    throw new RuntimeException('Unknown test unit or wrong unit type');
  }
  return $info[$id];
};
$cost = function (array $units) use ($info): int {
  $sum = 0;
  foreach ($units as $id => $count) { $sum += $info[$id]['cost'] * $count; }
  return $sum;
};
$category = Settings::load()['categories']['tiny'];
$category['garrison_points'] = ['mean' => 1000, 'spread_percent' => 0];
$category['fleet_weights'] = [202 => 1, 203 => 0];
$category['defence_weights'] = [401 => 1];
$category['resource_spread_percent'] = 0;
$category['tech'] = [106 => 8, 109 => 3];
$profile = Profile::create('tiny', $category, 1.25, $rates, $resolver);
$check($profile['fleet'] === [202 => 85] && $profile['fleet_points'] === 595, 'Fleet must fit the 60% budget using whole ships.');
$check($profile['defence'] === [401 => 36] && $profile['defence_points'] === 396, 'Defence must fit the remaining 40% budget.');
$check($profile['garrison_points'] === 991 && $profile['fleet_limit'] === 1238, 'Fleet limit must use actual fleet plus defence cost, not the requested budget.');
$check($profile['resources'] === [901 => 267, 902 => 55, 903 => 16], 'Loot must use 75% of actual fleet cost and weighted 60/25/15 shares.');
$check(!array_key_exists('tech', $profile), 'Category research must stay out of the immutable planet profile.');

$check(Profile::read(['config_snapshot' => json_encode(['profile' => $profile])]) === $profile,
  'A created profile must be read back unchanged.');
$check(Profile::read([]) === null && Profile::read(['config_snapshot' => '{bad json']) === null,
  'Missing or malformed snapshots must not be treated as populated profiles.');
foreach ([['version' => 2], ['fleet_limit' => -1], ['fleet_limit' => 1.0], ['fleet_limit' => '1238'],
  ['category' => ['color' => 'red']], ['category' => ['name' => '  ']], ['category' => ['key' => 7]],
  ['fleet' => 'none'], ['resources' => null]] as $broken) {
  $snapshot = ['profile' => array_replace_recursive($profile, $broken)];
  $check(Profile::read(['config_snapshot' => json_encode($snapshot, JSON_PRESERVE_ZERO_FRACTION)]) === null,
    'Every field the module relies on must be validated when reading: ' . json_encode($broken));
}
$incomplete = $profile;
unset($incomplete['resources']);
$check(Profile::read(['config_snapshot' => json_encode(['profile' => $incomplete])]) === null,
  'A profile without its materializable resources must not be applied.');

$selection = [
  'disabled' => ['weight' => 0, 'limit' => -1],
  'finite' => ['weight' => 30, 'limit' => 2],
  'uncapped' => ['weight' => 0.000001, 'limit' => -1],
  'zero_cap' => ['weight' => 1000, 'limit' => 0],
];
$check(Profile::choose($selection, ['finite' => 2, 'uncapped' => 1000000]) === 'uncapped', 'A fractional-weight unlimited category must remain eligible after finite caps are reached.');
$check(Profile::choose(['finite' => $selection['finite']], ['finite' => 3]) === null, 'An already exceeded cap must never permit another birth.');
$check(Profile::choose(['zero' => $selection['zero_cap']], []) === null, 'A zero cap must disable an otherwise heavily weighted category.');
$check(Profile::choose(['only' => ['weight' => 0.5, 'limit' => 2]], ['only' => 1]) === 'only', 'The final unreserved slot must remain eligible.');

$varied = $category;
$varied['garrison_points']['spread_percent'] = 20;
$varied['fleet_weights'] = [202 => 1, 203 => 3, 204 => 100];
$varied['defence_weights'] = [401 => 2, 402 => 1, 403 => 100];
$varied['resource_spread_percent'] = 20;
$sample = Profile::create('tiny', $varied, 1.25, $rates, $resolver);
$check($sample['target_points'] >= 800 && $sample['target_points'] <= 1200, 'Random target must remain inside its configured inclusive range.');
$fleet = $cost($sample['fleet']); $defence = $cost($sample['defence']);
$check($sample['fleet_points'] === $fleet && $sample['defence_points'] === $defence,
  'Recorded point costs must match the actual chosen units.');
$check($fleet <= floor($sample['target_points'] * .6) && $defence <= $sample['target_points'] - floor($sample['target_points'] * .6), 'Neither army may overspend its independent budget.');
$check(($sample['fleet'][204] ?? 0) <= 2 && ($sample['defence'][403] ?? 0) <= 3, 'A high weight must never bypass the native quantity cap.');
$check($sample['garrison_points'] === $fleet + $defence && $sample['fleet_limit'] === (int) floor(($fleet + $defence) * 1.25), 'The final cap must match the materializable garrison.');
$equivalent = $sample['resources'][901] + 2 * $sample['resources'][902] + 4 * $sample['resources'][903];
$check($equivalent <= $fleet * .9 + 0.000001 && $equivalent > $fleet * .6 - 7.000001, 'Weighted loot must stay within 60–90% of fleet cost, allowing only conversion rounding.');

$capped = $category;
$capped['fleet_weights'] = [204 => 1];
$capped['defence_weights'] = [403 => 1];
$sample = Profile::create('tiny', $capped, 1.25, $rates, $resolver);
$check($sample['fleet'] === [204 => 2] && $sample['defence'] === [403 => 3], 'An army containing only capped units must stop at the cap instead of inventing extra units.');
$check($sample['garrison_points'] === 21 && $sample['fleet_limit'] === 26, 'Unused capped budgets must not inflate garrison cost or its attack cap.');
foreach ([0, 100] as $share) {
  $edge = $category; $edge['fleet_share_percent'] = $share;
  $sample = Profile::create('tiny', $edge, 1.25, $rates, $resolver);
  $check($share === 0 ? ($sample['fleet'] === [] && array_sum($sample['resources']) === 0) : $sample['defence'] === [], 'Zero-budget armies must remain empty; loot cannot come from defence.');
}
foreach ([[901 => 0, 902 => 2, 903 => 4], [901 => 1, 902 => INF, 903 => 4], [901 => 1, 902 => 2]] as $invalidRates) {
  zgExpect(function () use ($category, $invalidRates, $resolver) {
    Profile::create('tiny', $category, 1.25, $invalidRates, $resolver);
  }, RuntimeException::class, 'Missing, zero or infinite exchange rates must be rejected.');
}
$bad = $category; $bad['fleet_weights'] = [401 => 1];
zgExpect(function () use ($bad, $rates, $resolver) {
  Profile::create('tiny', $bad, 1.25, $rates, $resolver);
}, RuntimeException::class, 'The unit resolver must be able to reject a defence type in the fleet pool.');

echo "Profile: {$assertions} assertions passed; no game bootstrap or database used.\n";
