<?php

namespace ZeroGalaxy;

/** Every ordinary galaxy is exactly one intergalactic jump away from galaxy 0. */
final class RiftGeometry {
  /** Native travel, fuel and recycler helpers derive distance from coordinates only. */
  public static function source(array $planet): array {
    $planet['galaxy'] = 1;
    return $planet;
  }

  public static function distance(): int {
    return (int) \Universe::getBaseGalaxyDistance();
  }
}
