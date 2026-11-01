<?php

namespace ZeroGalaxy;

final class DispatchResult {
  public array $dispatch;
  public array $fleet;
  public array $travel;
  public LockedRiftTarget $target;
  public int $remaining;
  public array $refreshedSource = [];

  public function __construct(array $dispatch, array $fleet, array $travel, LockedRiftTarget $target, int $remaining = 0) {
    $this->dispatch = $dispatch;
    $this->fleet = $fleet;
    $this->travel = $travel;
    $this->target = $target;
    $this->remaining = $remaining;
  }

  public function toArray(): array {
    return $this->dispatch + ['fleet' => $this->fleet, 'travel' => $this->travel,
      'source' => $this->target->source, 'target' => $this->target->planet];
  }
}
