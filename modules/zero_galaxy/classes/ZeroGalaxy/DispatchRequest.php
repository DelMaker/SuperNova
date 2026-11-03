<?php

namespace ZeroGalaxy;

final class DispatchRequest {
  public int $mission;
  public ?int $riftId;
  public int $system = 0;
  public int $position = 0;
  public int $sourceId = 0;
  public array $ships = [];
  public int $speed = 10;
  public int $captainId = 0;
  public int $groupId = 0;
  public int $probeCount = 0;
  public bool $nativeForm = false;
  public int $planetType = 1;

  private function __construct(int $mission, ?int $riftId) {
    $this->mission = $mission;
    $this->riftId = $riftId;
  }

  public static function attack(int $riftId, array $ships, int $speed, int $captainId, int $sourceId, int $groupId): self {
    $request = new self(MT_ATTACK, $riftId);
    $request->ships = $ships;
    $request->speed = $speed;
    $request->captainId = $captainId;
    $request->sourceId = $sourceId;
    $request->groupId = $groupId;
    return $request;
  }

  public static function spy(int $system, int $position, ?int $riftId, int $probes): self {
    $request = self::coordinates(MT_SPY, $system, $position, $riftId);
    $request->probeCount = $probes;
    return $request;
  }

  public static function recycle(int $system, int $position, array $ships = [], int $speed = 10, int $sourceId = 0): self {
    $request = self::coordinates(MT_RECYCLE, $system, $position, null);
    $request->ships = $ships;
    $request->speed = $speed;
    $request->sourceId = $sourceId;
    return $request;
  }

  public static function flight(int $system, int $position, int $planetType, int $mission, array $ships,
    int $speed, int $captainId = 0, int $sourceId = 0, int $groupId = 0): self {
    $request = self::coordinates($mission === MT_AKS ? MT_ATTACK : $mission, $system, $position, null);
    $request->nativeForm = true;
    $request->planetType = $mission === MT_RECYCLE ? PT_DEBRIS : $planetType;
    $request->ships = AttackPolicy::ships($ships);
    $request->speed = $speed;
    $request->captainId = $captainId;
    $request->sourceId = $sourceId;
    $request->groupId = $groupId;
    return $request;
  }

  private static function coordinates(int $mission, int $system, int $position, ?int $riftId): self {
    $request = new self($mission, $riftId);
    $request->system = $system;
    $request->position = $position;
    return $request;
  }
}
