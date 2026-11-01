<?php

namespace ZeroGalaxy;

interface WorldBodies {
  public function legacyOwner(array $state, int $now): int;
  public function riftOwner(array &$rift, array $profile, int $now): int;
  public function verifyOwner(int $ownerId, int $riftId): void;
  public function planet(array $rift, int $ownerId): ?array;
  public function materialize(array $rift, int $ownerId, int $now, string $phase, ?array $planet): void;
  public function removePlanet(array $rift, int $ownerId, ?array $planet): void;
  public function removeUnusedOwners(): void;
}
