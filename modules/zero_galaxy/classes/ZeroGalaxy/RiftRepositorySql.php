<?php

namespace ZeroGalaxy;

use InvalidArgumentException;
use mysqli;
use RuntimeException;
use Throwable;

class RiftRepositorySql implements RiftRepository {
  private $db;
  private $prefix;

  public function __construct(mysqli $db, string $prefix) {
    if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix) || strlen($prefix) > 40) {
      throw new InvalidArgumentException('Invalid database prefix');
    }
    $this->db = $db;
    $this->prefix = $prefix;
  }

  public function table(string $name): string { return $this->prefix . $name; }
  public function escape(string $value): string { return $this->db->real_escape_string($value); }
  public function insertId(): int { return (int) $this->db->insert_id; }

  public function query(string $sql) {
    $result = $this->db->query($sql);
    if ($result === false) {
      throw new RuntimeException('Zero Galaxy database error: ' . $this->db->error, $this->db->errno);
    }
    return $result;
  }

  public function assertOutsideTransaction(): void {
    $status = $this->query('SELECT @@in_transaction AS active, @@autocommit AS automatic')->fetch_assoc();
    if ((int) $status['active'] !== 0 || (int) $status['automatic'] !== 1) {
      throw new RuntimeException('Zero Galaxy storage cannot start inside another transaction');
    }
  }

  public function transaction(callable $operation) {
    if (!$this->db->begin_transaction()) {
      throw new RuntimeException('Zero Galaxy could not start its transaction');
    }
    try {
      $result = $operation();
      if (!$this->db->commit()) { throw new RuntimeException('Zero Galaxy could not commit its transaction'); }
      return $result;
    } catch (Throwable $error) {
      $this->db->rollback();
      throw $error;
    }
  }

  public function state(bool $lock = false): ?array {
    return $this->query('SELECT * FROM ' . $this->table('zero_galaxy_state') . ' WHERE id = 1'
      . ($lock ? ' FOR UPDATE' : ''))->fetch_assoc();
  }

  public function allForUpdate(): iterable {
    $result = $this->query('SELECT * FROM ' . $this->table('zero_galaxy_rifts') . ' ORDER BY rift_id FOR UPDATE');
    while ($row = $result->fetch_assoc()) { yield $row; }
  }

  public function delete(int $riftId): void {
    $this->query('DELETE FROM ' . $this->table('zero_galaxy_rifts') . " WHERE rift_id = {$riftId}");
  }

  public function insert(array $rift, int $system, int $position, array $snapshot): void {
    $snapshot = $this->escape(json_encode($snapshot, JSON_THROW_ON_ERROR));
    $image = $this->escape($rift['image']);
    $ownerId = (int) $rift['npc_owner_id'];
    $this->query('INSERT INTO ' . $this->table('zero_galaxy_rifts') . "
      (system, position, born_at, opens_at, unstable_at, closes_at, disappears_at, image, config_snapshot, npc_owner_id)
      VALUES ({$system}, {$position}, {$rift['born_at']}, {$rift['opens_at']}, {$rift['unstable_at']},
        {$rift['closes_at']}, {$rift['disappears_at']}, '{$image}', '{$snapshot}', {$ownerId})");
  }

  public function schedule(int $nextTick, int $nextGeneration, int $seededSystems): void {
    $this->query('UPDATE ' . $this->table('zero_galaxy_state') . " SET next_tick_at = {$nextTick},
      next_generation_at = {$nextGeneration}, seeded_systems = {$seededSystems} WHERE id = 1");
  }

  public function inSystem(int $system, int $now): array {
    $rifts = $this->table('zero_galaxy_rifts'); $state = $this->table('zero_galaxy_state');
    $planets = $this->table('planets');
    $result = $this->query("SELECT r.*, COALESCE(r.npc_owner_id, s.npc_owner_id) AS owner_id, p.id AS materialized_id,
        p.debris_metal, p.debris_crystal
      FROM {$rifts} AS r JOIN {$state} AS s ON s.id = 1
      LEFT JOIN {$planets} AS p ON p.id = r.planet_id AND p.id_owner = COALESCE(r.npc_owner_id, s.npc_owner_id)
        AND p.galaxy = 0 AND p.system = r.system AND p.planet = r.position AND p.planet_type = 1
      WHERE r.system = {$system} AND r.born_at <= {$now} AND r.disappears_at > {$now} ORDER BY r.position");
    $rows = [];
    while ($row = $result->fetch_assoc()) {
      $phase = Lifecycle::phase($row, $now);
      if (($phase === 'active' || $phase === 'unstable') && empty($row['materialized_id'])) {
        continue;
      }
      unset($row['materialized_id']);
      $rows[(int) $row['position']] = $row;
    }
    return $rows;
  }

  public function findById(int $id, int $now): ?array {
    $rifts = $this->table('zero_galaxy_rifts');
    $coordinates = $this->query("SELECT system, position FROM {$rifts} WHERE rift_id = {$id}")->fetch_assoc();
    if (!$coordinates) { return null; }
    $row = $this->inSystem((int) $coordinates['system'], $now)[(int) $coordinates['position']] ?? null;
    return $row && (int) $row['rift_id'] === $id ? $row : null;
  }
}
