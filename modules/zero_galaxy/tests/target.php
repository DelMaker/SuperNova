<?php

require __DIR__ . '/support/bootstrap.php';
zgCoreConstants();

use ZeroGalaxy\RiftTarget;

$rift = ['rift_id' => 1, 'npc_owner_id' => 20, 'planet_id' => 30, 'system' => 10, 'position' => 5,
  'born_at' => 100, 'opens_at' => 200, 'unstable_at' => 300, 'closes_at' => 400, 'disappears_at' => 500];
$planet = ['id' => 30, 'id_owner' => 20, 'galaxy' => 0, 'system' => 10, 'planet' => 5, 'planet_type' => PT_PLANET];
$owner = ['id' => 20, 'user_bot' => 200, 'authlevel' => 0, 'parent_account_id' => 0, 'parent_account_global' => 0];
foreach ([199 => false, 200 => true, 300 => true, 400 => false] as $now => $eligible) {
  $check(RiftTarget::matches($rift, $planet, $owner, $now) === $eligible,
    'Only a materialized active or unstable target is eligible at time ' . $now);
}
$check(!RiftTarget::matches($rift, null, $owner, 250), 'A planet deleted before arrival is unavailable');
$check(!RiftTarget::matches($rift, $planet, null, 250), 'An NPC removed before arrival is unavailable');
foreach (['id' => 31, 'id_owner' => 21, 'galaxy' => 1, 'system' => 11, 'planet' => 6, 'planet_type' => 3] as $key => $value) {
  $check(!RiftTarget::matches($rift, array_replace($planet, [$key => $value]), $owner, 250),
    'A different target identity or coordinate must be rejected: ' . $key);
}
foreach (['id' => 21, 'user_bot' => 0, 'authlevel' => 1, 'parent_account_id' => 1, 'parent_account_global' => 1] as $key => $value) {
  $check(!RiftTarget::matches($rift, $planet, array_replace($owner, [$key => $value]), 250),
    'A human, privileged or linked account cannot be a rift NPC: ' . $key);
}
foreach ([null, 0, 21] as $recordedOwner) {
  $check(!RiftTarget::matches(array_replace($rift, ['npc_owner_id' => $recordedOwner]), $planet, $owner, 250),
    'Only the owner recorded on the rift can own its target: ' . var_export($recordedOwner, true));
}
$check(!RiftTarget::matches(array_replace($rift, ['planet_id' => 0]), array_replace($planet, ['id' => 0]), $owner, 250),
  'Matching zero IDs cannot manufacture a materialized target');
$check(RiftTarget::matches(array_map('strval', $rift), array_map('strval', $planet), array_map('strval', $owner), 250),
  'Native database numeric strings must be accepted');

echo "Rift target: {$assertions} assertions passed; no game bootstrap or database used.\n";
