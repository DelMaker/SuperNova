<?php

namespace ZeroGalaxy;

final class DispatchRequest {
  public int $mission;
  public int $system;
  public int $position;
  public int $planetType = 1;
  public int $sourceId = 0;
  /** Explicit composition from the fleet form; quick missions select their ships under lock. */
  public array $ships = [];
  public int $speed = 10;
  public int $captainId = 0;
  public int $probeCount = 0;
  /** The fleet form may target empty coordinates; quick missions need an existing planet. */
  public bool $allowEmptyTarget = false;

  private function __construct(int $mission, int $system, int $position) {
    $this->mission = $mission;
    $this->system = $system;
    $this->position = $position;
  }

  public static function spy(int $system, int $position, int $probes): self {
    $request = new self(MT_SPY, $system, $position);
    $request->probeCount = $probes;
    return $request;
  }

  public static function recycle(int $system, int $position): self {
    $request = new self(MT_RECYCLE, $system, $position);
    $request->planetType = PT_DEBRIS;
    return $request;
  }

  public static function flight(int $system, int $position, int $planetType, int $mission, array $ships,
    int $speed, int $captainId = 0, int $sourceId = 0): self {
    if (!in_array($mission, self::missions($planetType, $ships), true)) {
      throw new \InvalidArgumentException('fl_bad_mission');
    }
    $request = new self($mission, $system, $position);
    $request->allowEmptyTarget = true;
    $request->planetType = $planetType;
    $request->ships = AttackPolicy::ships($ships);
    $request->speed = $speed;
    $request->captainId = $captainId;
    $request->sourceId = $sourceId;
    return $request;
  }

  public static function missions(int $planetType, array $ships): array {
    if ($planetType === PT_DEBRIS) {
      return array_intersect(array_keys($ships), sn_get_groups('flt_recyclers')) ? [MT_RECYCLE] : [];
    }
    if (!in_array($planetType, [PT_PLANET, PT_MOON], true)) { return []; }
    return empty($ships[SHIP_SPY]) ? [MT_ATTACK] : [MT_ATTACK, MT_SPY];
  }
}
