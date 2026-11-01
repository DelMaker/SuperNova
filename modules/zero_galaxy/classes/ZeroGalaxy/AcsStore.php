<?php

namespace ZeroGalaxy;

interface AcsStore {
  public function group(int $id, bool $lock = false): ?array;
  public function fleet(int $id, bool $lock = false): ?array;
  public function fleets(int $groupId, bool $lock = false): array;
  public function leaderGroup(array $preview, int $fleetId): ?array;
  public function invitedAt(array $rift, int $userId, int $now): array;
  public function invitedCoordinates(int $userId, int $now): array;
  public function user(int $id): ?array;
  public function userByName(string $name): ?array;
  public function transaction(callable $operation);
  public function lockRift(int $riftId): ?array;
  public function lockFleetContext(array $owners, array $planets, int $fleetId): void;
  public function createGroup(int $userId, array $fleet): int;
  public function invite(int $userId, int $fleetId): void;
}
