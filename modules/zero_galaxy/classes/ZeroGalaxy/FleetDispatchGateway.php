<?php

namespace ZeroGalaxy;

interface FleetDispatchGateway {
  public function transaction(callable $action);
  public function lockTarget(DispatchContext $context, DispatchRequest $request): ?LockedRiftTarget;
  public function cost(LockedRiftTarget $target, array $ships): int;
  public function inventory(LockedRiftTarget $target, int $unitId): int;
  public function recyclerFleet(LockedRiftTarget $target, float $debris): array;
  public function allowed(LockedRiftTarget $target, array $ships, int $mission, int $speed): int;
  public function travel(LockedRiftTarget $target, array $ships, int $speed): array;
  public function hasCaptain(LockedRiftTarget $target, int $captainId): bool;
  public function launch(LockedRiftTarget $target, DispatchRequest $request, array $ships, array $travel, int $now): array;
  public function assignCaptain(int $captainId, int $fleetId): void;
  public function refreshSource(array $source, int $fuel): array;
}
