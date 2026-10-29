<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('PT_PLANET', 1);
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Lifecycle.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Storage.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/RiftTarget.php';

use ZeroGalaxy\RiftTarget;

$rift = ['rift_id' => 1, 'npc_owner_id' => 20, 'planet_id' => 30, 'system' => 10, 'position' => 5,
  'born_at' => 100, 'opens_at' => 200, 'unstable_at' => 300, 'closes_at' => 400, 'disappears_at' => 500];
$planet = ['id' => 30, 'id_owner' => 20, 'galaxy' => 0, 'system' => 10, 'planet' => 5, 'planet_type' => PT_PLANET];
$owner = ['id' => 20, 'user_bot' => 200, 'authlevel' => 0, 'parent_account_id' => 0, 'parent_account_global' => 0];
$assertions = 0;
$check = function ($condition, string $message) use (&$assertions): void {
  $assertions++;
  if (!$condition) { throw new RuntimeException($message); }
};
$before = [$rift, $planet, $owner];
foreach ([99 => false, 100 => false, 199 => false, 200 => true, 299 => true, 300 => true,
  399 => true, 400 => false, 499 => false, 500 => false] as $now => $eligible) {
  $check(RiftTarget::matches($rift, $planet, $owner, 2, $now) === $eligible,
    'Only a materialized active or unstable target is eligible at time ' . $now);
}
$check([$rift, $planet, $owner] === $before, 'Checking eligibility must not change snapshots');
foreach ([false, null, [], 'invalid'] as $missing) {
  $check(!RiftTarget::matches($missing, $planet, $owner, 2, 250), 'A missing rift is unavailable');
  $check(!RiftTarget::matches($rift, $missing, $owner, 2, 250), 'A missing planet is unavailable');
  $check(!RiftTarget::matches($rift, $planet, $missing, 2, 250), 'A missing owner is unavailable');
}
foreach (['rift' => $rift, 'planet' => $planet, 'owner' => $owner] as $type => $row) {
  foreach ($row as $field => $value) {
    if (in_array($field, ['rift_id', 'npc_owner_id'], true)) { continue; }
    $changed = $row; unset($changed[$field]);
    $check(!RiftTarget::matches($type === 'rift' ? $changed : $rift,
      $type === 'planet' ? $changed : $planet, $type === 'owner' ? $changed : $owner, 2, 250),
      'An incomplete ' . $type . ' row must not pass the shared guard: ' . $field);
  }
}
foreach (['id' => 31, 'id_owner' => 21, 'galaxy' => 1, 'system' => 11, 'planet' => 6, 'planet_type' => 3] as $key => $value) {
  $check(!RiftTarget::matches($rift, array_replace($planet, [$key => $value]), $owner, 2, 250),
    'A different target identity or coordinate must be rejected: ' . $key);
}
foreach (['id' => 21, 'user_bot' => 0, 'authlevel' => 1, 'parent_account_id' => 1, 'parent_account_global' => 1] as $key => $value) {
  $check(!RiftTarget::matches($rift, $planet, array_replace($owner, [$key => $value]), 2, 250),
    'A human, privileged or linked account cannot be a rift NPC: ' . $key);
}
foreach ([null, 0] as $legacyMarker) {
  $legacyRift = array_replace($rift, ['npc_owner_id' => $legacyMarker]);
  $check(RiftTarget::matches($legacyRift, $planet, $owner, 20, 250), 'Legacy shared NPC targets remain eligible');
  $check(!RiftTarget::matches($legacyRift, $planet, $owner, 21, 250), 'Legacy fallback cannot substitute another owner');
}
$legacyRift = $rift; unset($legacyRift['npc_owner_id']);
$check(RiftTarget::matches($legacyRift, $planet, $owner, 20, 250), 'Older rows without an owner column remain supported');
$check(!RiftTarget::matches(array_replace($rift, ['npc_owner_id' => 21]), $planet, $owner, 20, 250),
  'An explicit per-rift owner must take precedence over the legacy fallback');
$check(!RiftTarget::matches(array_replace($rift, ['planet_id' => 0]), array_replace($planet, ['id' => 0]), $owner, 2, 250),
  'Matching zero IDs cannot manufacture a materialized target');
$check(RiftTarget::matches(array_map('strval', $rift), array_map('strval', $planet), array_map('strval', $owner), 2, 250),
  'Native database numeric strings must be accepted');

echo "Rift target: {$assertions} assertions passed; no game bootstrap or database used.\n";
