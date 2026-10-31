<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('FLEET_STATUS_FLYING', 0);
define('MT_AKS', 2);
define('PT_PLANET', 1);
function sn_get_groups($group) {
  if ($group !== 'fleet') { throw new RuntimeException('Unexpected test group'); }
  return [202 => 202, 203 => 203, 204 => 204];
}
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/AttackPolicy.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/AcsPolicy.php';

use ZeroGalaxy\AcsPolicy;

$assertions = 0;
$check = function ($condition, string $message) use (&$assertions): void {
  $assertions++;
  if (!$condition) { throw new RuntimeException($message); }
};
$reject = function (callable $call, string $message) use ($check): void {
  try { $call(); }
  catch (InvalidArgumentException $error) { $check(true, $message); return; }
  $check(false, $message);
};
$rift = ['system' => 10, 'position' => 5, 'planet_id' => 900];
$fleet = ['fleet_id' => 100, 'fleet_owner' => 7, 'fleet_group' => 12, 'fleet_mission' => MT_AKS,
  'fleet_mess' => FLEET_STATUS_FLYING, 'fleet_end_galaxy' => 0, 'fleet_end_system' => 10,
  'fleet_end_planet' => 5, 'fleet_end_type' => PT_PLANET, 'fleet_start_time' => 1700000000,
  'fleet_end_stay' => 0, 'fleet_end_planet_id' => 800, 'fleet_array' => '202,1'];
$prices = [202 => .6, 203 => .6, 204 => 2.25];
$calls = [];
$cost = function (int $id, $amount) use ($prices, &$calls): float {
  $calls[] = [$id, $amount]; return $prices[$id] * $amount;
};

$check(AcsPolicy::enabled(['acs_enabled' => true]), 'Normalized explicit true enables ACS');
foreach ([[], ['acs_enabled' => false], ['acs_enabled' => null], ['acs_enabled' => 0],
  ['acs_enabled' => 1], ['acs_enabled' => 'true'], ['acs_enabled' => 'false'], ['acs_enabled' => []]] as $settings) {
  $check(!AcsPolicy::enabled($settings), 'Missing or nonboolean settings must fail closed');
}

$check(AcsPolicy::validMember($fleet, $rift, 12, 1700000000), 'A valid native ACS arrival is accepted');
$check(AcsPolicy::validMember(array_map('strval', $fleet), array_map('strval', $rift), 12, 1700000000),
  'Native database numeric strings are accepted');
$withoutStay = $fleet; unset($withoutStay['fleet_end_stay']);
$check(AcsPolicy::validMember($withoutStay, $rift, 12, 1700000000), 'An absent hold stage remains compatible');
$check(AcsPolicy::validMember(array_replace($fleet, ['fleet_end_stay' => null]), $rift, 12, 1700000000),
  'A null hold stage is absent, not a holding mission');
$check(AcsPolicy::validMember($fleet, array_replace($rift, ['planet_id' => 901]), 12, 1700000000),
  'Rift generation replacement at the same coordinates is resolved elsewhere');
foreach (['fleet_id', 'fleet_owner', 'fleet_group', 'fleet_mission', 'fleet_mess',
  'fleet_end_galaxy', 'fleet_end_system', 'fleet_end_planet', 'fleet_end_type', 'fleet_start_time'] as $field) {
  $missing = $fleet; unset($missing[$field]);
  $check(!AcsPolicy::validMember($missing, $rift, 12, 1700000000), 'Missing member field fails closed: ' . $field);
}
foreach (['fleet_id' => 0, 'fleet_owner' => 0, 'fleet_group' => 13, 'fleet_mission' => 1,
  'fleet_mess' => 1, 'fleet_end_galaxy' => 1, 'fleet_end_system' => 11, 'fleet_end_planet' => 6,
  'fleet_end_type' => 3, 'fleet_start_time' => 1700000001, 'fleet_end_stay' => 1700000300] as $field => $value) {
  $check(!AcsPolicy::validMember(array_replace($fleet, [$field => $value]), $rift, 12, 1700000000),
    'Wrong member identity, target, stage or ETA fails closed: ' . $field);
}
foreach ([false, true, '', '0junk', '00', 0.0, -1, [], (string) PHP_INT_MAX . '0'] as $invalid) {
  $check(!AcsPolicy::validMember(array_replace($fleet, ['fleet_mess' => $invalid]), $rift, 12, 1700000000),
    'Malformed native flags cannot coerce to flying');
}
foreach ([[], ['system' => 0, 'position' => 5], ['system' => 10, 'position' => 0],
  ['system' => '10junk', 'position' => 5]] as $invalid) {
  $check(!AcsPolicy::validMember($fleet, $invalid, 12, 1700000000), 'Malformed rift coordinates fail closed');
}
$check(!AcsPolicy::validMember($fleet, $rift, 0, 1700000000), 'A zero group is never an ACS');
$check(!AcsPolicy::validMember($fleet, $rift, 12, 0), 'A missing group ETA fails closed');

$second = array_replace($fleet, ['fleet_id' => 101, 'fleet_array' => '203,1']);
$returned = array_replace($fleet, ['fleet_id' => 102, 'fleet_mess' => 1, 'fleet_array' => '204,1000']);
$other = array_replace($fleet, ['fleet_id' => 103, 'fleet_owner' => 8, 'fleet_array' => '204,1000']);
$snapshot = [$fleet, $second, $returned, $other];
$check(AcsPolicy::playerCost($snapshot, 7, [], $cost) === 1,
  'A player shares one cap across all flying fleets, flooring their combined fractional cost once');
$check($calls === [[202, 1], [203, 1]], 'Returning fleets and other players do not spend this player budget');
$check($snapshot === [$fleet, $second, $returned, $other], 'Pure player cost does not alter any rows');
$calls = [];
$check(AcsPolicy::playerCost([$fleet, array_replace($second, ['fleet_array' => '202,2'])], 7, [202 => 2, 204 => 1], $cost) === 5,
  'A newly selected fleet is combined with all existing fleets before final rounding');
$check($calls === [[202, 5], [204, 1]], 'Same ship types merge into one cost stack');
$check(AcsPolicy::playerCost([], 7, [], $cost) === 0, 'No existing or selected ships cost zero');
$check(AcsPolicy::playerCost([], 7, [204 => 4], $cost) === 9, 'A new participant spends only their own selected fleet cost');
$check(AcsPolicy::playerCost([$fleet], 8, [], $cost) === 0, 'A different participant has a separate allowance');
$check(AcsPolicy::playerCost([array_replace($fleet, ['fleet_array' => '202,1;202,1;203,1;'])], 7, [], $cost) === 1,
  'Repeated native stacks are merged; native trailing separators remain accepted');
foreach ([null, '', 'bad', '202', '202,', '202,-1', '202,1.5', '202,1e3', '202,1,2', '202,1;bad',
  '202,1;202,-1', '999,1', '202,0', '202,1;999,0', '202,' . PHP_INT_MAX, str_repeat('2', 16001), []] as $encoded) {
  $reject(function () use ($fleet, $encoded, $cost) {
    AcsPolicy::playerCost([array_replace($fleet, ['fleet_array' => $encoded])], 7, [], $cost);
  }, 'Malformed or unsupported native fleet data must not bypass a shared cap');
}
$reject(function () use ($fleet, $cost) {
  $row = $fleet; unset($row['fleet_mess']); AcsPolicy::playerCost([$row], 7, [], $cost);
}, 'A matching owner row cannot disappear from the budget through missing status');
$reject(function () use ($fleet, $cost) {
  AcsPolicy::playerCost([$fleet], 0, [], $cost);
}, 'A zero player cannot request an ACS budget');
$reject(function () use ($cost) { AcsPolicy::playerCost([null], 7, [], $cost); }, 'Malformed rows are rejected');
foreach ([[202 => -1], [202 => 1.5], [202 => true], [999 => 1]] as $extra) {
  $reject(function () use ($extra, $cost) { AcsPolicy::playerCost([], 7, $extra, $cost); }, 'New selection uses the normal attack policy');
}
$large = (string) intdiv(PHP_INT_MAX, 2);
$reject(function () use ($fleet, $large, $cost) {
  AcsPolicy::playerCost([
    array_replace($fleet, ['fleet_array' => '202,' . $large]),
    array_replace($fleet, ['fleet_array' => '202,' . $large]),
    array_replace($fleet, ['fleet_array' => '202,2']),
  ], 7, [], $cost);
}, 'Merging valid stacks cannot overflow to a cheap negative count');

echo "ACS policy: {$assertions} assertions passed; no game bootstrap or database used.\n";
