<?php

namespace ZeroGalaxy;

final class DispatchContext {
  public array $actor;
  public array $settings;
  public int $now;

  public function __construct(array $actor, array $settings, int $now) {
    $this->actor = $actor;
    $this->settings = $settings;
    $this->now = $now;
  }
}
