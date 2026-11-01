<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('PT_PLANET', 1);
define('MT_ATTACK', 1);
define('MT_AKS', 2);
function sn_get_groups($group): array {
  if ($group !== 'fleet') { throw new RuntimeException('Unexpected test group'); }
  return [202, 203];
}

require __DIR__ . '/support/autoload.php';
require __DIR__ . '/support/assertions.php';

use ZeroGalaxy\AcsTokens;
use ZeroGalaxy\AttackPolicy;
use ZeroGalaxy\AttackRequest;

$reject = function (callable $operation, string $errorCode, string $message) use ($check): void {
  try { $operation(); }
  catch (InvalidArgumentException $error) {
    $check($error->getMessage() === $errorCode, $message); return;
  }
  $check(false, $message);
};
$secret = 'attack-request-test-secret-0123456789';
$issued = 1700000000; $userId = 7; $sourceId = 11; $riftId = 19;
$base = ['rift_id' => (string) $riftId, 'source_id' => (string) $sourceId,
  'token_at' => (string) $issued, 'token' => AttackPolicy::token($userId, $sourceId, $riftId, $issued, $secret),
  'ships' => [202 => '5', 203 => 0], 'usedfleet' => str_rot13(base64_encode(json_encode([202 => 5]))),
  'speed' => '6', 'captain_id' => '42'];
$post = function (int $step, array $data) use ($issued): AttackRequest {
  return AttackRequest::fromHttp(['step' => (string) $step], $data, 'POST', 999, $issued + 15);
};
$rift = ['system' => 99, 'position' => 7];

$initial = AttackRequest::fromHttp(['rift_id' => (string) $riftId], [], 'GET', $sourceId, $issued);
$check($initial->step === 0 && $initial->riftId === $riftId && $initial->sourceId === $sourceId
  && $initial->issuedAt === $issued && $initial->ships === [] && $initial->speed === 10,
  'GET must open step zero with the current source and no submitted fleet');
$initial->authorize($userId, $issued, $secret);
$initial->validateTarget([]);
$check(true, 'Opening the form must not require a POST token or fixed target fields');

$selection = $post(1, $base);
$check($selection->ships === [202 => 5] && $selection->sourceId === $sourceId
  && $selection->issuedAt === $issued && $selection->speed === 10 && $selection->captainId === 0,
  'Step one must read its source and fleet from POST and defer speed and captain selection');
$route = $post(2, $base);
$check($route->ships === [202 => 5] && $route->speed === 6 && $route->captainId === 0,
  'Step two must decode the carried fleet and accept the selected speed');
$dispatch = $post(3, $base);
$check($dispatch->ships === [202 => 5] && $dispatch->speed === 6 && $dispatch->captainId === 42,
  'Step three must preserve the fleet and speed and read the selected captain');

foreach ([[1, 'GET'], [0, 'POST'], [-1, 'GET'], [4, 'POST']] as [$step, $method]) {
  $reject(function () use ($step, $method, $base, $sourceId, $issued): void {
    AttackRequest::fromHttp(['step' => $step], $base, $method, $sourceId, $issued);
  }, 'zero_galaxy_attack_bad_request', 'Only GET step zero and POST steps one through three are valid transitions');
}

$selection->authorize($userId, $issued + 15, $secret);
$selection->authorize($userId, $issued + 15, $secret);
$repeated = $post(1, $base);
$repeated->authorize($userId, $issued + 15, $secret);
$check($repeated->ships === $selection->ships,
  'The same authentic POST may authorize repeatedly and retain the same fleet');
$unsigned = $base; unset($unsigned['token']);
$reject(function () use ($post, $unsigned, $userId, $issued, $secret): void {
  $post(1, $unsigned)->authorize($userId, $issued + 15, $secret);
}, 'zero_galaxy_attack_expired_form', 'A POST without an attack signature must be rejected');
$reject(function () use ($selection, $userId, $issued, $secret): void {
  $selection->authorize($userId + 1, $issued + 15, $secret);
}, 'zero_galaxy_attack_expired_form', 'A signed form must remain bound to its player');
foreach (['source_id' => $sourceId + 1, 'rift_id' => $riftId + 1] as $field => $value) {
  $reject(function () use ($post, $base, $field, $value, $userId, $issued, $secret): void {
    $post(1, array_replace($base, [$field => $value]))->authorize($userId, $issued + 15, $secret);
  }, 'zero_galaxy_attack_expired_form', 'Changing the POST source or rift must invalidate its original signature');
}

$tokens = new AcsTokens($secret);
$joined = array_replace($base, ['acs_group' => '23',
  'acs_token' => $tokens->join($userId, $sourceId, $riftId, $issued, 23)]);
$joinRequest = $post(2, $joined);
$joinRequest->authorize($userId, $issued + 15, $secret);
$check($joinRequest->groupId === 23, 'Joining an ACS must require both the attack and matching group signature');
$reject(function () use ($post, $joined, $userId, $issued, $secret): void {
  $post(2, array_replace($joined, ['acs_group' => '24']))->authorize($userId, $issued + 15, $secret);
}, 'zero_galaxy_attack_expired_form', 'An ACS signature must not transfer to another group');
unset($joined['acs_token']);
$reject(function () use ($post, $joined, $userId, $issued, $secret): void {
  $post(2, $joined)->authorize($userId, $issued + 15, $secret);
}, 'zero_galaxy_attack_expired_form', 'An ordinary attack signature alone must not authorize an ACS join');

$fixed = ['galaxy' => 0, 'system' => 99, 'planet' => 7, 'planet_type' => PT_PLANET,
  'target_mission' => MT_ATTACK, 'fleet_group' => 0,
  'resource0' => 0, 'resource1' => 0, 'resource2' => 0, 'missiontime' => 0];
$post(2, array_replace($base, $fixed))->validateTarget($rift);
$check(true, 'Matching native destination, mission and empty cargo fields must remain accepted');
foreach (['galaxy' => 1, 'system' => 100, 'target_mission' => MT_AKS, 'resource0' => 1] as $field => $value) {
  $reject(function () use ($post, $base, $fixed, $field, $value, $rift): void {
    $post(2, array_replace($base, $fixed, [$field => $value]))->validateTarget($rift);
  }, 'zero_galaxy_attack_bad_request', 'A submitted destination, mission or cargo override must be rejected');
}
foreach ([0, 11, null] as $speed) {
  $input = array_replace($base, ['speed' => $speed]);
  if ($speed === null) { unset($input['speed']); }
  $reject(function () use ($post, $input): void { $post(2, $input); },
    $speed === null ? 'zero_galaxy_attack_bad_request' : 'fl_cheat_speed',
    'Route submission must reject absent or out-of-range speed');
}

echo "Attack request: {$assertions} assertions passed; no engine bootstrap or database.\n";
