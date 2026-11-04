<?php

namespace ZeroGalaxy;

final class DispatchResult {
  public array $dispatch;
  public array $fleet;
  public array $travel;
  public array $source;
  public array $target;
  public int $remaining;
  public array $refreshedSource = [];

  public function __construct(array $dispatch, array $fleet, array $travel, array $source, array $target, int $remaining) {
    $this->dispatch = $dispatch;
    $this->fleet = $fleet;
    $this->travel = $travel;
    $this->source = $source;
    $this->target = $target;
    $this->remaining = $remaining;
  }

  public function toArray(): array {
    return $this->dispatch + ['fleet' => $this->fleet, 'travel' => $this->travel,
      'source' => $this->source, 'target' => $this->target];
  }
}
