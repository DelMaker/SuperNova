<?php

namespace ZeroGalaxy;

/** Native NPC owners and planets materialized for rifts. */
interface WorldBodies {
  /** @return int[] Owner ID per configured category key. */
  public function syncOwners(array $categories, int $now): array;
  /** @return array[] Current planet rows per rift ID, locked for the rest of the tick. */
  public function lockPlanets(array $rifts): array;
  public function materialize(array $rift, int $ownerId, int $now, string $phase, ?array $planet): void;
  public function removePlanet(array $rift, int $ownerId, ?array $planet): void;
  /** Applies batched planet refreshes and retires owners of removed categories. */
  public function finish(int $now): void;
}
