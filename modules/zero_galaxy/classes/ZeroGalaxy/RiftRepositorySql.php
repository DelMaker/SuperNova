<?php

namespace ZeroGalaxy;

use RuntimeException;

class RiftRepositorySql implements RiftRepository {
  private $sql;

  public function __construct(Sql $sql) { $this->sql = $sql; }

  public function assertOutsideTransaction(): void { $this->sql->assertOutsideTransaction(); }

  public function transaction(callable $operation) { return $this->sql->transaction($operation); }

  public function state(bool $lock = false): array {
    $state = $this->sql->one('SELECT * FROM ' . $this->sql->table('zero_galaxy_state') . ' WHERE id = 1'
      . ($lock ? ' FOR UPDATE' : ''));
    if (!$state) {
      throw new RuntimeException('Zero Galaxy state is missing', SchemaInstaller::MISSING);
    }
    return $state;
  }

  public function allForUpdate(): array {
    return $this->sql->query('SELECT * FROM ' . $this->sql->table('zero_galaxy_rifts') . ' ORDER BY rift_id FOR UPDATE')
      ->fetch_all(MYSQLI_ASSOC);
  }

  public function delete(int $riftId): void {
    $this->sql->query('DELETE FROM ' . $this->sql->table('zero_galaxy_rifts') . " WHERE rift_id = {$riftId}");
  }

  public function insert(array $rift, int $system, int $position, array $snapshot): void {
    $snapshot = $this->sql->escape(json_encode($snapshot, JSON_THROW_ON_ERROR));
    $image = $this->sql->escape($rift['image']);
    $ownerId = (int) $rift['npc_owner_id'];
    $this->sql->query('INSERT INTO ' . $this->sql->table('zero_galaxy_rifts') . "
      (system, position, born_at, opens_at, unstable_at, closes_at, disappears_at, image, config_snapshot, npc_owner_id)
      VALUES ({$system}, {$position}, {$rift['born_at']}, {$rift['opens_at']}, {$rift['unstable_at']},
        {$rift['closes_at']}, {$rift['disappears_at']}, '{$image}', '{$snapshot}', {$ownerId})");
  }

  public function schedule(int $nextTick, int $nextGeneration, int $seededSystems): void {
    $this->sql->query('UPDATE ' . $this->sql->table('zero_galaxy_state') . " SET next_tick_at = {$nextTick},
      next_generation_at = {$nextGeneration}, seeded_systems = {$seededSystems} WHERE id = 1");
  }

  /** Visible rifts by position; a planet phase is shown only once its native planet exists. */
  public function inSystem(int $system, int $now): array {
    $rifts = $this->sql->table('zero_galaxy_rifts');
    $planets = $this->sql->table('planets');
    $result = $this->sql->query("SELECT r.*, p.id AS materialized_id, p.debris_metal, p.debris_crystal
      FROM {$rifts} AS r
      LEFT JOIN {$planets} AS p ON p.id = r.planet_id AND p.id_owner = r.npc_owner_id
        AND p.galaxy = 0 AND p.system = r.system AND p.planet = r.position AND p.planet_type = 1
      WHERE r.system = {$system} AND r.born_at <= {$now} AND r.disappears_at > {$now} ORDER BY r.position");
    $rows = [];
    while ($row = $result->fetch_assoc()) {
      if (Lifecycle::hasPlanet(Lifecycle::phase($row, $now)) && empty($row['materialized_id'])) {
        continue;
      }
      unset($row['materialized_id']);
      $rows[(int) $row['position']] = $row;
    }
    return $rows;
  }
}
