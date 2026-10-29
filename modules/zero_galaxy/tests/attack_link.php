<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/AttackLink.php';

use ZeroGalaxy\AttackLink;

$assertions = 0;
$check = function ($condition, $message) use (&$assertions) {
  $assertions++;
  if (!$condition) { throw new RuntimeException($message); }
};
$query = ['galaxy' => '0', 'system' => '199', 'planet' => '8', 'planet_type' => '1', 'target_mission' => '1'];
$resolve = function ($parameters, $page = 'fleet', $method = 'GET') {
  return AttackLink::nativeCoordinates($page, $method, $parameters, 199, 15);
};
$expected = ['system' => 199, 'position' => 8];
$check($resolve($query) === $expected, 'The existing espionage report attack URL must resolve zero coordinates.');
$alias = $query; unset($alias['planet_type']); $alias['planettype'] = '1';
$check($resolve($alias) === $expected, 'Native planettype alias must remain supported.');
$check($resolve(array_replace($query, ['fleet_page' => '0'])) === $expected, 'Explicit first fleet step must be supported.');
$check($resolve(array_replace($query, ['mode' => '0'])) === $expected, 'Legacy first-step mode must be supported.');
foreach (['galaxy' => ['1', '2', '-1', '0.0', '0evil', [], true],
  'target_mission' => ['0', '2', '6', '7', [], true],
  'planet_type' => ['2', '3', [], true], 'system' => ['0', '200', '-1', '2evil', [], true],
  'planet' => ['0', '16', '-1', '8evil', [], true], 'fleet_page' => ['1', '2', '3', [], true]] as $key => $values) {
  foreach ($values as $value) {
    $check($resolve(array_replace($query, [$key => $value])) === null, 'Invalid or unrelated native request must not be redirected: ' . $key);
  }
}
foreach (['galaxy', 'target_mission', 'system', 'planet'] as $key) {
  $missing = $query; unset($missing[$key]);
  $check($resolve($missing) === null, 'Missing required coordinate or mission must not turn into a zero-galaxy attack.');
}
foreach (['POST', 'HEAD', 'DELETE'] as $method) {
  $check($resolve($query, 'fleet', $method) === null, 'Only the read-only native entry link is redirected.');
}
foreach (['galaxy', 'messages', 'zero_galaxy_attack'] as $page) {
  $check($resolve($query, $page) === null, 'Other pages must retain their existing routing.');
}
$check($resolve(array_replace($query, ['system' => '1', 'planet' => '1'])) === ['system' => 1, 'position' => 1], 'Lower coordinate boundaries must be supported.');
$check($resolve(array_replace($query, ['system' => '199', 'planet' => '15'])) === ['system' => 199, 'position' => 15], 'Upper coordinate boundaries must be supported.');
echo "Attack link: {$assertions} assertions passed.\n";
