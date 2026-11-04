<?php

namespace ZeroGalaxy;

/** Rift records as seen by world reconciliation. */
interface RiftRepository {
  public function assertOutsideTransaction(): void;
  public function transaction(callable $operation);
  public function state(bool $lock = false): array;
  public function allForUpdate(): array;
  public function delete(int $riftId): void;
  public function insert(array $rift, int $system, int $position, array $snapshot): void;
  public function schedule(int $nextTick, int $nextGeneration, int $seededSystems): void;
}
