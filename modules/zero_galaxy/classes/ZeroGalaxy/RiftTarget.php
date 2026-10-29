<?php

namespace ZeroGalaxy;

/** Call only with the current rift, planet and owner read under their locks. */
class RiftTarget {
  public static function ownerId(array $rift, int $legacyOwnerId): int {
    return (int) ($rift['npc_owner_id'] ?? 0) ?: $legacyOwnerId;
  }

  public static function matches($rift, $planet, $owner, int $legacyOwnerId, int $now): bool {
    if (!is_array($rift) || !is_array($planet) || !is_array($owner)
      || !isset($rift['planet_id'], $rift['born_at'], $rift['opens_at'], $rift['unstable_at'],
        $rift['closes_at'], $rift['disappears_at'], $rift['system'], $rift['position'],
        $planet['id'], $planet['id_owner'], $planet['galaxy'], $planet['system'], $planet['planet'], $planet['planet_type'],
        $owner['id'], $owner['user_bot'], $owner['authlevel'], $owner['parent_account_id'], $owner['parent_account_global'])) {
      return false;
    }
    $ownerId = self::ownerId($rift, $legacyOwnerId);
    return $ownerId > 0 && (int) $rift['planet_id'] > 0
      && in_array(Lifecycle::phase($rift, $now), ['active', 'unstable'], true)
      && (int) $planet['id'] === (int) $rift['planet_id']
      && (int) $planet['id_owner'] === $ownerId && (int) $owner['id'] === $ownerId
      && (int) $planet['galaxy'] === 0 && (int) $planet['planet_type'] === PT_PLANET
      && (int) $planet['system'] === (int) $rift['system'] && (int) $planet['planet'] === (int) $rift['position']
      && (int) $owner['user_bot'] === Storage::NPC_BOT_TYPE && (int) $owner['authlevel'] === 0
      && (int) $owner['parent_account_id'] === 0 && (int) $owner['parent_account_global'] === 0;
  }
}
