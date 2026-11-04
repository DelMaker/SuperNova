<?php

namespace ZeroGalaxy;

use InvalidArgumentException;

final class DispatchFailure extends InvalidArgumentException {
  public string $languageKey;
  public ?int $nativeError;

  public function __construct(string $languageKey, ?int $nativeError = null) {
    parent::__construct($languageKey);
    $this->languageKey = $languageKey;
    $this->nativeError = $nativeError;
  }

  /** @param string $outcome 'missing' or 'failed' */
  public static function forMission(int $mission, string $outcome): self {
    $key = $mission === MT_ATTACK ? 'attack' : ($mission === MT_SPY ? 'spy' : 'recycle');
    return new self("zero_galaxy_{$key}_{$outcome}");
  }

  public function translate($language): string {
    return $this->nativeError !== null ? ($language['fl_attack_error'][$this->nativeError]
      ?? $language['zero_galaxy_attack_failed']) : ($language[$this->languageKey] ?? $this->languageKey);
  }
}
