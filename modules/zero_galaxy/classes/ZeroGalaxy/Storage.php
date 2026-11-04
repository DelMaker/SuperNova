<?php

namespace ZeroGalaxy;

use RuntimeException;

class Storage {
  private $records;
  private $schema;
  private $world;

  public function __construct(Sql $sql) {
    $this->records = new RiftRepositorySql($sql);
    $this->schema = new SchemaInstaller($sql);
    $this->world = new WorldReconciler($this->records, new NativeNpcPlanets($sql),
      static function (string $key, array $category, float $multiplier): array {
        return Profile::create($key, $category, $multiplier, Profile::nativeRates());
      });
  }

  /** A regular request costs one state read; the schema is installed when that read finds nothing. */
  public function tick(int $now, array $settings, int $maxSystem, int $maxPlanet, bool $force = false): bool {
    try {
      return $this->world->tick($now, $settings, $maxSystem, $maxPlanet, $force);
    } catch (RuntimeException $error) {
      if (!SchemaInstaller::isMissing($error)) { throw $error; }
    }
    $this->schema->install();
    return $this->world->tick($now, $settings, $maxSystem, $maxPlanet, $force);
  }

  public function inSystem(int $system, int $now): array {
    return $this->records->inSystem($system, $now);
  }

  public function findActive(int $system, int $position, int $now): ?array {
    $row = $this->inSystem($system, $now)[$position] ?? null;
    return $row && !empty($row['planet_id']) && Lifecycle::hasPlanet(Lifecycle::phase($row, $now)) ? $row : null;
  }
}
