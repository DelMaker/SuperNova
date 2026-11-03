<?php

namespace ZeroGalaxy;

final class LockedRiftTarget {
  public array $actor;
  public array $source;
  public array $planet;
  public array $rift;

  public function __construct(array $actor, array $source, array $planet, array $rift) {
    $this->actor = $actor;
    $this->source = $source;
    $this->planet = $planet;
    $this->rift = $rift;
  }
}
