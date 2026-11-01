<?php

namespace ZeroGalaxy;

use mysqli;

class Storage {
  const NPC_BOT_TYPE = 200;
  const NPC_NAME = 'Обитатели разлома';
  const ACTIVE_NAME = 'Планета разлома';
  const UNSTABLE_NAME = 'Нестабильная планета разлома';

  private $records;
  private $schema;
  private $world;

  public function __construct(mysqli $db, string $prefix) {
    $this->records = new RiftRepositorySql($db, $prefix);
    $this->schema = new SchemaInstaller($this->records);
    $this->world = new WorldReconciler($this->records, new NativeNpcPlanets($this->records),
      static function (string $key, array $category, float $multiplier): array {
        $config = \SN::$config;
        $rates = [901 => (float) $config->rpg_exchange_metal,
          902 => $config->rpg_exchange_crystal / $config->rpg_exchange_metal,
          903 => $config->rpg_exchange_deuterium / $config->rpg_exchange_metal];
        return Profile::create($key, $category, $multiplier, $rates);
      });
  }

  public function install(): void { $this->schema->install(); }

  public function tick(int $now, array $settings, int $maxSystem, int $maxPlanet, bool $force = false): void {
    $this->install();
    $this->world->tick($now, $settings, $maxSystem, $maxPlanet, $force);
  }

  public function npcOwnerId(): int {
    $this->install();
    return (int) $this->records->state()['npc_owner_id'];
  }

  public function inSystem(int $system, int $now): array {
    $this->install();
    return $this->records->inSystem($system, $now);
  }

  public function findActive(int $system, int $position, int $now): ?array {
    $row = $this->inSystem($system, $now)[$position] ?? null;
    return $row && !empty($row['planet_id']) && in_array(Lifecycle::phase($row, $now), ['active', 'unstable'], true)
      ? $row : null;
  }

  public function findById(int $id, int $now): ?array {
    $this->install();
    return $this->records->findById($id, $now);
  }
}
