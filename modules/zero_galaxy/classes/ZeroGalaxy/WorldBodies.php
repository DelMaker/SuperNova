<?php

namespace ZeroGalaxy;

interface WorldBodies {
  public function syncOwners(array $categories, array $state, int $now): array;
  public function assignOwner(array &$rift, int $ownerId, int $legacyOwnerId): void;
  public function planet(array $rift, int $ownerId): ?array;
  public function materialize(array $rift, int $ownerId, int $now, string $phase, ?array $planet): void;
  public function removePlanet(array $rift, int $ownerId, ?array $planet): void;
  public function removeUnusedOwners(): void;
}
