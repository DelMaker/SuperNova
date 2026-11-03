<?php

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

function sn_get_groups($group) {
  if ($group !== 'fleet') { throw new RuntimeException('Unexpected test group'); }
  return [202 => 202, 203 => 203, 204 => 204, 210 => 210];
}
class SN {
  public static $sn_secret_word = 'local-attack-test-secret-0123456789';
}

require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Profile.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/AttackPolicy.php';

use ZeroGalaxy\AttackPolicy;

require __DIR__ . '/support/assertions.php';
$expect = function (callable $callable, string $class, string $message) use ($check) {
  try { $callable(); }
  catch (Throwable $error) {
    if (!$error instanceof $class) { throw $error; }
    $check(true, $message); return;
  }
  $check(false, $message);
};
$encode = function ($ships): string { return str_rot13(base64_encode(json_encode($ships))); };
$rift = function ($limit, $version = 1): array {
  return ['config_snapshot' => json_encode(['profile' => ['version' => $version, 'fleet_limit' => $limit]], JSON_PRESERVE_ZERO_FRACTION)];
};
$prices = [202 => .6, 203 => .6, 204 => 2.25, 210 => .25];
$cost = function (int $id, $count) use ($prices): float {
  if (!isset($prices[$id])) { throw new InvalidArgumentException('Unknown test unit'); }
  return $prices[$id] * $count;
};

$check(AttackPolicy::cost([202 => 1, 203 => 1], $cost) === 1,
  'Statistics must sum fractional unit costs before flooring once; flooring each type would incorrectly give zero.');
$check(AttackPolicy::cost([204 => 4], $cost) === 9, 'A cost resolver returns the cost of the whole stack, not a per-unit price.');

$check(AttackPolicy::limit($rift(0)) === 0, 'A saved zero cap must be distinguishable from a missing populated profile.');
foreach ([['config_snapshot' => '{bad'], $rift(1000, 2), $rift(-1), $rift('1000'), $rift(1000.0)] as $invalidRift) {
  $check(AttackPolicy::limit($invalidRift) === null, 'Legacy, unsupported or noninteger profile limits must fail closed.');
}

$ships = [202 => '5', 203 => 0, 204 => 2];
$check(AttackPolicy::decode($encode($ships)) === [202 => 5, 204 => 2],
  'Native fleet decoding must normalize decimal counts and omit zero stacks.');
foreach ([null, [], [202 => 0], [202 => -1], [202 => 1.5],
  [202 => '1e3'], [202 => '1 '], [202 => true], [202 => []],
  [999 => 1], ['202garbage' => 1], [202 => (string) PHP_INT_MAX],
  [202 => '922337203685477580800'], [202 => 1, 203 => 1, 204 => 1, 210 => 1, 999 => 0]] as $invalidShips) {
  $expect(function () use ($invalidShips) { AttackPolicy::ships($invalidShips); }, InvalidArgumentException::class,
    'Malformed IDs, quantities, oversized maps and empty fleets must be rejected: ' . var_export($invalidShips, true));
}
foreach ([[], '!!!!', str_repeat('A', 16001), $encode([]),
  $encode([999 => 1]), str_rot13(base64_encode('{bad json'))] as $invalidEncoding) {
  $expect(function () use ($invalidEncoding) { AttackPolicy::decode($invalidEncoding); }, InvalidArgumentException::class,
    'Malformed, oversized or invalid encoded fleets must be rejected before use.');
}
foreach ([-1, 1.5, INF] as $invalidCount) {
  $expect(function () use ($invalidCount) {
    AttackPolicy::cost([202 => $invalidCount], function () { return 1; });
  }, InvalidArgumentException::class, 'Cost must reject invalid counts independently of the resolver result: ' . var_export($invalidCount, true));
}
$expect(function () {
  AttackPolicy::cost([202 => (string) PHP_INT_MAX], function () { return 1; });
}, InvalidArgumentException::class, 'A count beyond the safe integer conversion boundary must be rejected before calling a resolver.');
foreach ([0, INF, 'not a cost'] as $invalidCost) {
  $expect(function () use ($invalidCost) {
    AttackPolicy::cost([202 => 1], function () use ($invalidCost) { return $invalidCost; });
  }, InvalidArgumentException::class, 'Invalid native price values must never allow free or unbounded ships.');
}
$expect(function () {
  AttackPolicy::cost([202 => 1], function () { return PHP_INT_MAX; });
}, InvalidArgumentException::class, 'Overflowing total fleet cost must be rejected.');
$expect(function () {
  AttackPolicy::cost([202 => 1, 203 => 1], function () { return PHP_INT_MAX * .75; });
}, InvalidArgumentException::class, 'Overflow must also be rejected when individual stack prices are finite.');

$key = SN::$sn_secret_word;
$issued = 1700000000; $user = 7; $source = 11; $riftId = 19;
$token = AttackPolicy::token($user, $source, $riftId, $issued);
$check(AttackPolicy::validToken($token, $issued, $user, $source, $riftId, $issued + AttackPolicy::TOKEN_TTL, $key),
  'The exact TTL boundary must remain valid.');
$check(!AttackPolicy::validToken($token, $issued, $user, $source, $riftId, $issued + AttackPolicy::TOKEN_TTL + 1, $key),
  'A token one second past its TTL must be rejected.');
$check(!AttackPolicy::validToken($token, $issued, $user, $source, $riftId, $issued - 1, $key), 'A future-issued token must be rejected.');
foreach ([[$user + 1, $source, $riftId], [$user, $source + 1, $riftId], [$user, $source, $riftId + 1]] as $identity) {
  $check(!AttackPolicy::validToken($token, $issued, $identity[0], $identity[1], $identity[2], $issued, $key),
    'Tokens must not transfer to another player, source planet or rift generation.');
}
$check(!AttackPolicy::validToken($token, $issued + 1, $user, $source, $riftId, $issued + 1, $key), 'Changing the claimed issue time must invalidate the signature.');
$check(!AttackPolicy::validToken($token, $issued, $user, $source, $riftId, $issued, $key . '-other'), 'Tokens from another server key must be rejected.');
$wrongNamespace = hash_hmac('sha256', "zero-galaxy-spy:v1:{$user}:{$source}:{$riftId}:{$issued}", $key);
$check(!AttackPolicy::validToken($wrongNamespace, $issued, $user, $source, $riftId, $issued, $key), 'A valid HMAC for a different operation must not authorize an attack.');
foreach ([null, $token . "\n", str_repeat('0', 64)] as $malformedToken) {
  $check(!AttackPolicy::validToken($malformedToken, $issued, $user, $source, $riftId, $issued, $key),
    'Malformed or forged tokens must be rejected without coercion.');
}
$expect(function () use ($user, $source, $riftId, $issued) {
  AttackPolicy::token($user, $source, $riftId, $issued, 'too-short');
}, RuntimeException::class, 'An unavailable/short signing key must fail closed.');

echo "Attack policy: {$assertions} assertions passed; no game bootstrap or database used.\n";
