<?php

namespace ZeroGalaxy;

/** Call only with the current rift, planet and owner read under their locks. */
class RiftTarget {
  public static function matches($rift, $planet, $owner, int $now): bool {
    if (!is_array($rift) || !is_array($planet) || !is_array($owner)
      || !isset($rift['planet_id'], $rift['npc_owner_id'], $rift['born_at'], $rift['opens_at'], $rift['unstable_at'],
        $rift['closes_at'], $rift['disappears_at'], $rift['system'], $rift['position'],
        $planet['id'], $planet['id_owner'], $planet['galaxy'], $planet['system'], $planet['planet'], $planet['planet_type'],
        $owner['id'])) {
      return false;
    }
    $ownerId = (int) $rift['npc_owner_id'];
    return $ownerId > 0 && (int) $rift['planet_id'] > 0
      && Lifecycle::hasPlanet(Lifecycle::phase($rift, $now))
      && (int) $planet['id'] === (int) $rift['planet_id']
      && (int) $planet['id_owner'] === $ownerId && (int) $owner['id'] === $ownerId
      && (int) $planet['galaxy'] === 0 && (int) $planet['planet_type'] === PT_PLANET
      && (int) $planet['system'] === (int) $rift['system'] && (int) $planet['planet'] === (int) $rift['position']
      && Npc::matches($owner);
  }
}
