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

  public static function recycle(int $system, int $position, int $riftId): self {
    return self::coordinates(MT_RECYCLE, $system, $position, $riftId);
  }

  private static function coordinates(int $mission, int $system, int $position, ?int $riftId): self {
    $request = new self($mission, $riftId);
    $request->system = $system;
    $request->position = $position;
    return $request;
  }
}
