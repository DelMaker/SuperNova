<?php

namespace ZeroGalaxy;

use InvalidArgumentException;
use mysqli;
use RuntimeException;
use Throwable;

class Storage {
  const NPC_BOT_TYPE = 200;
  const NPC_NAME = 'Обитатели разлома';
  const ACTIVE_NAME = 'Планета разлома';
  const UNSTABLE_NAME = 'Нестабильная планета разлома';

  private $db;
  private $rifts;
  private $state;
  private $users;
  private $planets;
  private $units;
  private $ready = false;

  public function __construct(mysqli $db, string $prefix) {
    if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix) || strlen($prefix) > 40) {
      throw new InvalidArgumentException('Invalid database prefix');
    }
    $this->db = $db;
    $this->rifts = $prefix . 'zero_galaxy_rifts';
    $this->state = $prefix . 'zero_galaxy_state';
    $this->users = $prefix . 'users';
    $this->planets = $prefix . 'planets';
    $this->units = $prefix . 'unit';
  }

  private function query(string $sql) {
    $result = $this->db->query($sql);
    if ($result === false) {
      throw new RuntimeException('Zero Galaxy database error: ' . $this->db->error, $this->db->errno);
    }
    return $result;
  }

  private function outsideTransaction(): void {
    $status = $this->query('SELECT @@in_transaction AS active, @@autocommit AS automatic')->fetch_assoc();
    if ((int) $status['active'] !== 0 || (int) $status['automatic'] !== 1) {
      throw new RuntimeException('Zero Galaxy storage cannot start inside another transaction');
    }
  }

  /** Only module-owned tables are installed or upgraded, outside gameplay transactions. */
  public function install(): void {
    if ($this->ready) {
      return;
    }
    $this->outsideTransaction();
    $this->query("CREATE TABLE IF NOT EXISTS {$this->rifts} (
      rift_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      system INT UNSIGNED NOT NULL,
      position INT UNSIGNED NOT NULL,
      born_at BIGINT UNSIGNED NOT NULL,
      opens_at BIGINT UNSIGNED NOT NULL,
      unstable_at BIGINT UNSIGNED NOT NULL,
      closes_at BIGINT UNSIGNED NOT NULL,
      disappears_at BIGINT UNSIGNED NOT NULL,
      image VARCHAR(64) NOT NULL,
      config_snapshot TEXT NOT NULL,
      planet_id BIGINT UNSIGNED NULL DEFAULT NULL,
      PRIMARY KEY (rift_id), UNIQUE KEY coordinates (system, position),
      KEY expiration (disappears_at), KEY materialized_planet (planet_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $this->query("CREATE TABLE IF NOT EXISTS {$this->state} (
      id TINYINT UNSIGNED NOT NULL,
      next_tick_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
      next_generation_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
      seeded_systems INT UNSIGNED NOT NULL DEFAULT 0,
      npc_owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    // Preserve timelines from the earlier module-only draft.
    if (!$this->query("SHOW COLUMNS FROM {$this->rifts} LIKE 'planet_id'")->num_rows) {
      $this->query("ALTER TABLE {$this->rifts} ADD COLUMN IF NOT EXISTS planet_id BIGINT UNSIGNED NULL DEFAULT NULL");
    }
    if (!$this->query("SHOW COLUMNS FROM {$this->state} LIKE 'npc_owner_id'")->num_rows) {
      $this->query("ALTER TABLE {$this->state} ADD COLUMN IF NOT EXISTS npc_owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0");
    }
    $this->query("INSERT IGNORE INTO {$this->state} (id) VALUES (1)");
    $this->ready = true;
  }

  private function npcName(int $ownerId = 0): string {
    $name = self::NPC_NAME;
    $safe = $this->db->real_escape_string($name);
    if ($this->query("SELECT id FROM {$this->users} WHERE username = '{$safe}' AND id <> {$ownerId} LIMIT 1")->num_rows) {
      $name .= ' ' . bin2hex(random_bytes(2));
    }
    return $this->db->real_escape_string($name);
  }

  private function disabledPassword(): string {
    // Non-hex, non-numeric and longer than MD5; even loose comparison cannot match.
    return '!' . bin2hex(random_bytes(31)) . '!';
  }

  private function lockNpc(array $state, int $now): int {
    $ownerId = (int) $state['npc_owner_id'];
    if (!$ownerId) {
      $name = $this->npcName();
      // Legacy passwords are 32-character MD5 hashes. This value cannot match;
      // no account or account translation is created for this NPC.
      $password = $this->disabledPassword();
      $this->query("INSERT INTO {$this->users}
        (username, password, salt, authlevel, user_bot, galaxy, system, planet,
         id_planet, current_planet, parent_account_id, parent_account_global,
         register_time, onlinetime, vacation, deltime, email, email_2)
        VALUES ('{$name}', '{$password}', '', 0, " . self::NPC_BOT_TYPE . ", 0, 0, 0,
          0, 0, 0, 0, {$now}, {$now}, 0, 0, '', '')");
      $ownerId = (int) $this->db->insert_id;
      $this->query("UPDATE {$this->state} SET npc_owner_id = {$ownerId} WHERE id = 1");
    }
    $owner = $this->query("SELECT * FROM {$this->users} WHERE id = {$ownerId} FOR UPDATE")->fetch_assoc();
    if (!$owner || (int) $owner['authlevel'] !== 0 || (int) $owner['user_bot'] !== self::NPC_BOT_TYPE
      || (int) $owner['galaxy'] !== 0 || (int) $owner['id_planet'] !== 0 || (int) $owner['current_planet'] !== 0
      || (int) $owner['parent_account_id'] !== 0 || (int) $owner['parent_account_global'] !== 0
      || (strpos($owner['username'], self::NPC_NAME) !== 0 && !preg_match('/^Zero Galaxy [a-f0-9]{16}$/D', $owner['username']))
      || strlen($owner['password']) !== 64) {
      throw new RuntimeException('Zero Galaxy NPC ownership marker is invalid; no planets were changed');
    }
    if (strpos($owner['username'], self::NPC_NAME) !== 0) {
      $name = $this->npcName($ownerId);
      $this->query("UPDATE {$this->users} SET username = '{$name}' WHERE id = {$ownerId}");
    }
    if ($owner['password'][0] !== '!') {
      $password = $this->disabledPassword();
      $this->query("UPDATE {$this->users} SET password = '{$password}' WHERE id = {$ownerId}");
    }
    $this->query("UPDATE {$this->users} SET onlinetime = {$now} WHERE id = {$ownerId}");
    return $ownerId;
  }

  private function lockPlanet(array $rift, int $ownerId): ?array {
    $planetId = (int) $rift['planet_id'];
    if (!$planetId) {
      return null;
    }
    $planet = $this->query("SELECT * FROM {$this->planets} WHERE id = {$planetId} FOR UPDATE")->fetch_assoc();
    if (!$planet) {
      return null;
    }
    if ((int) $planet['id_owner'] !== $ownerId || (int) $planet['galaxy'] !== 0
      || (int) $planet['system'] !== (int) $rift['system'] || (int) $planet['planet'] !== (int) $rift['position']
      || (int) $planet['planet_type'] !== 1) {
      throw new RuntimeException('Zero Galaxy planet ownership or coordinates changed; refusing to alter it');
    }
    return $planet;
  }

  private function removePlanet(array $rift, int $ownerId, ?array $planet): void {
    $planetId = (int) $rift['planet_id'];
    if ($planet) {
      $foreignUnit = $this->query("SELECT unit_id FROM {$this->units} WHERE unit_location_type = 1
        AND unit_location_id = {$planetId} AND (unit_player_id IS NULL OR unit_player_id <> {$ownerId})
        LIMIT 1 FOR UPDATE")->fetch_assoc();
      if ($foreignUnit) {
        throw new RuntimeException('Zero Galaxy planet contains units of another owner; refusing deletion');
      }
      $this->query("DELETE FROM {$this->units} WHERE unit_location_type = 1
        AND unit_location_id = {$planetId} AND unit_player_id = {$ownerId}");
      // Native FK nulls the target ID, retaining incoming fleets and their times.
      $this->query("DELETE FROM {$this->planets} WHERE id = {$planetId} AND id_owner = {$ownerId}
        AND galaxy = 0 AND system = " . (int) $rift['system'] . " AND planet = " . (int) $rift['position'] . " AND planet_type = 1");
    }
    if ($planetId) {
      $this->query("UPDATE {$this->rifts} SET planet_id = NULL WHERE rift_id = " . (int) $rift['rift_id']);
    }
  }

  private function materialize(array $rift, int $ownerId, int $now, string $phase, ?array $planet): void {
    $name = $this->db->real_escape_string($phase === 'unstable' ? self::UNSTABLE_NAME : self::ACTIVE_NAME);
    if (!$planet) {
      $system = (int) $rift['system'];
      $position = (int) $rift['position'];
      $collision = $this->query("SELECT id FROM {$this->planets} WHERE galaxy = 0
        AND system = {$system} AND planet = {$position} LIMIT 1 FOR UPDATE")->fetch_assoc();
      if ($collision) {
        throw new RuntimeException('Zero Galaxy coordinates are occupied; refusing to replace another planet');
      }
      $image = $this->db->real_escape_string($rift['image']);
      $this->query("INSERT INTO {$this->planets}
        (name, id_owner, galaxy, system, planet, planet_type, image, last_update, que_processed,
         metal, crystal, deuterium, metal_perhour, crystal_perhour, deuterium_perhour,
         energy_max, energy_used, metal_max, crystal_max, deuterium_max,
         field_current, position_original, field_max_original, temp_min_original, temp_max_original)
        VALUES ('{$name}', {$ownerId}, 0, {$system}, {$position}, 1, '{$image}', {$now}, {$now},
          0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, {$position}, 163, 0, 40)");
      $planetId = (int) $this->db->insert_id;
      $this->query("UPDATE {$this->rifts} SET planet_id = {$planetId} WHERE rift_id = " . (int) $rift['rift_id']);
    } else {
      $planetId = (int) $planet['id'];
      // Stage 2 has no resources/buildings. The clock also prevents ordinary
      // basic income when native dispatch refreshes an event at ETA <= now.
      $this->query("UPDATE {$this->planets} SET name = '{$name}', last_update = {$now}, que_processed = {$now},
        metal = 0, crystal = 0, deuterium = 0, metal_perhour = 0, crystal_perhour = 0,
        deuterium_perhour = 0, energy_max = 0, energy_used = 0 WHERE id = {$planetId}");
    }
  }

  private function probability(float $chance): bool {
    return $chance >= 1 || ($chance > 0 && random_int(1, 1000000000) <= $chance * 1000000000);
  }

  private function birthCount(float $mean, int $slots, bool $initial): int {
    if ($slots === 0 || $mean <= 0) {
      return 0;
    }
    $mean = min($mean, $slots);
    if ($initial) {
      $count = 0;
      for ($i = 0; $i < $slots; $i++) {
        $count += $this->probability($mean / $slots) ? 1 : 0;
      }
      return $count;
    }
    $count = (int) floor($mean);
    return $count + ($this->probability($mean - $count) ? 1 : 0);
  }

  /** Force processes lifecycle now but never bypasses generation cadence. */
  public function tick(int $now, array $settings, int $maxSystem, int $maxPlanet, bool $force = false): void {
    $this->outsideTransaction();
    $this->install();
    $state = $this->query("SELECT * FROM {$this->state} WHERE id = 1")->fetch_assoc();
    if (!$force && (int) $state['next_tick_at'] > $now) {
      return;
    }
    if (!$this->db->begin_transaction()) {
      throw new RuntimeException('Zero Galaxy could not start its transaction');
    }
    try {
      // Shared lock order with the sender: state, users, then planets.
      $state = $this->query("SELECT * FROM {$this->state} WHERE id = 1 FOR UPDATE")->fetch_assoc();
      if (!$force && (int) $state['next_tick_at'] > $now) {
        $this->db->commit();
        return;
      }
      $ownerId = $this->lockNpc($state, $now);
      $rows = $this->query("SELECT * FROM {$this->rifts} ORDER BY rift_id FOR UPDATE");
      $occupied = [];
      $nextTransition = $now + $settings['tick_seconds'];
      while ($rift = $rows->fetch_assoc()) {
        $planet = $this->lockPlanet($rift, $ownerId);
        $phase = Lifecycle::phase($rift, $now);
        if ($phase === 'active' || $phase === 'unstable') {
          $this->materialize($rift, $ownerId, $now, $phase, $planet);
        } else {
          $this->removePlanet($rift, $ownerId, $planet);
        }
        if ((int) $rift['disappears_at'] <= $now) {
          $this->query("DELETE FROM {$this->rifts} WHERE rift_id = " . (int) $rift['rift_id']);
          continue;
        }
        $occupied[(int) $rift['system']][(int) $rift['position']] = true;
        $deadline = Lifecycle::nextTransition($rift, $now);
        if ($deadline > $now) {
          $nextTransition = min($nextTransition, $deadline);
        }
      }

      $maxSystem = max(1, $maxSystem);
      $maxPlanet = max(1, $maxPlanet);
      $generationDue = (int) $state['next_generation_at'] <= $now;
      $expectedBirths = Lifecycle::expectedBirths($settings, $settings['generation_seconds']);
      $snapshot = $this->db->real_escape_string(json_encode($settings, JSON_THROW_ON_ERROR));
      for ($system = 1; $system <= $maxSystem; $system++) {
        $initial = $system > (int) $state['seeded_systems'];
        if (!$initial && !$generationDue) {
          continue;
        }
        $free = array_values(array_diff(range(1, $maxPlanet), array_keys($occupied[$system] ?? [])));
        $mean = $initial ? $settings['target_active_per_system'] : $expectedBirths;
        $count = $this->birthCount((float) $mean, count($free), $initial);
        shuffle($free);
        for ($i = 0; $i < $count; $i++) {
          $rift = Lifecycle::create($now, $settings);
          $image = $this->db->real_escape_string($rift['image']);
          $position = $free[$i];
          $this->query("INSERT INTO {$this->rifts}
            (system, position, born_at, opens_at, unstable_at, closes_at, disappears_at, image, config_snapshot)
            VALUES ({$system}, {$position}, {$rift['born_at']}, {$rift['opens_at']}, {$rift['unstable_at']},
              {$rift['closes_at']}, {$rift['disappears_at']}, '{$image}', '{$snapshot}')");
          $nextTransition = min($nextTransition, $rift['opens_at']);
        }
      }

      // Bounded catch-up: one generation after idle time, not every missed cycle.
      $nextGeneration = $generationDue ? $now + $settings['generation_seconds'] : (int) $state['next_generation_at'];
      $nextTick = min($nextTransition, $nextGeneration);
      $seededSystems = max($maxSystem, (int) $state['seeded_systems']);
      $this->query("UPDATE {$this->state} SET next_tick_at = {$nextTick},
        next_generation_at = {$nextGeneration}, seeded_systems = {$seededSystems} WHERE id = 1");
      if (!$this->db->commit()) {
        throw new RuntimeException('Zero Galaxy could not commit its transaction');
      }
    } catch (Throwable $error) {
      $this->db->rollback();
      throw $error;
    }
  }

  public function npcOwnerId(): int {
    $this->install();
    $state = $this->query("SELECT npc_owner_id FROM {$this->state} WHERE id = 1")->fetch_assoc();
    return (int) $state['npc_owner_id'];
  }

  /** Read-only once installed; does not begin/commit the sender's transaction. */
  public function inSystem(int $system, int $now): array {
    $this->install();
    $result = $this->query("SELECT r.*, s.npc_owner_id AS owner_id, p.id AS materialized_id
      FROM {$this->rifts} AS r JOIN {$this->state} AS s ON s.id = 1
      LEFT JOIN {$this->planets} AS p ON p.id = r.planet_id AND p.id_owner = s.npc_owner_id
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

  public function findActive(int $system, int $position, int $now): ?array {
    $rows = $this->inSystem($system, $now);
    $row = $rows[$position] ?? null;
    return $row && !empty($row['planet_id']) && in_array(Lifecycle::phase($row, $now), ['active', 'unstable'], true)
      ? $row : null;
  }
}
