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
  private $npcs;
  private $fleets;
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
    $this->npcs = $prefix . 'zero_galaxy_npcs';
    $this->fleets = $prefix . 'fleets';
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
    try {
      // A new Storage object is created on every request. Check the installed
      // schema and singleton with a read before attempting any DDL or writes.
      $installed = $this->query("SELECT s.id, s.npc_owner_id, s.stats_hidden_ids,
          r.planet_id, r.npc_owner_id, n.owner_id, n.rift_id
        FROM {$this->state} s LEFT JOIN {$this->rifts} r ON 0 LEFT JOIN {$this->npcs} n ON 0
        WHERE s.id = 1 LIMIT 1");
      if ($installed->num_rows) {
        $this->ready = true;
        return;
      }
    } catch (Throwable $error) {
      // Only absent tables or upgrade columns require installation. A broken
      // connection or denied read must remain a real error, not a DDL retry.
      if (!in_array((int) $error->getCode(), [1054, 1146], true)) { throw $error; }
    }
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
      npc_owner_id BIGINT UNSIGNED NULL DEFAULT NULL,
      PRIMARY KEY (rift_id), UNIQUE KEY coordinates (system, position),
      KEY expiration (disappears_at), KEY materialized_planet (planet_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $this->query("CREATE TABLE IF NOT EXISTS {$this->npcs} (
      owner_id BIGINT UNSIGNED NOT NULL,
      rift_id BIGINT UNSIGNED NOT NULL,
      PRIMARY KEY (owner_id), UNIQUE KEY rift (rift_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $this->query("CREATE TABLE IF NOT EXISTS {$this->state} (
      id TINYINT UNSIGNED NOT NULL,
      next_tick_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
      next_generation_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
      seeded_systems INT UNSIGNED NOT NULL DEFAULT 0,
      npc_owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      stats_hidden_ids TEXT NULL,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    // Preserve timelines from the earlier module-only draft.
    if (!$this->query("SHOW COLUMNS FROM {$this->rifts} LIKE 'planet_id'")->num_rows) {
      $this->query("ALTER TABLE {$this->rifts} ADD COLUMN IF NOT EXISTS planet_id BIGINT UNSIGNED NULL DEFAULT NULL");
    }
    if (!$this->query("SHOW COLUMNS FROM {$this->state} LIKE 'npc_owner_id'")->num_rows) {
      $this->query("ALTER TABLE {$this->state} ADD COLUMN IF NOT EXISTS npc_owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0");
    }
    if (!$this->query("SHOW COLUMNS FROM {$this->rifts} LIKE 'npc_owner_id'")->num_rows) {
      $this->query("ALTER TABLE {$this->rifts} ADD COLUMN IF NOT EXISTS npc_owner_id BIGINT UNSIGNED NULL DEFAULT NULL");
    }
    if (!$this->query("SHOW COLUMNS FROM {$this->state} LIKE 'stats_hidden_ids'")->num_rows) {
      $this->query("ALTER TABLE {$this->state} ADD COLUMN IF NOT EXISTS stats_hidden_ids TEXT NULL");
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

  /** Research belongs to a player in the native engine, so each populated rift owns its immutable research. */
  private function lockRiftNpc(array &$rift, array $profile, int $now): int {
    $riftId = (int) $rift['rift_id'];
    $ownerId = (int) ($rift['npc_owner_id'] ?? 0);
    if (!$ownerId) {
      $baseName = self::NPC_NAME . ' #' . $riftId;
      if (!preg_match('/^.{1,32}$/uD', $baseName)) { $baseName = 'Разлом #' . $riftId; }
      $name = $this->db->real_escape_string($baseName);
      while ($this->query("SELECT id FROM {$this->users} WHERE username = '{$name}' LIMIT 1")->num_rows) {
        // Even a 64-bit rift ID plus nonce fits the native 32-character name.
        $name = $this->db->real_escape_string('#' . $riftId . ' ' . bin2hex(random_bytes(4)));
      }
      $password = $this->disabledPassword();
      $this->query("INSERT INTO {$this->users}
        (username, password, salt, authlevel, user_bot, galaxy, system, planet, id_planet, current_planet,
         parent_account_id, parent_account_global, register_time, onlinetime, vacation, deltime, email, email_2)
        VALUES ('{$name}', '{$password}', '', 0, " . self::NPC_BOT_TYPE . ", 0, 0, 0, 0, 0,
          0, 0, {$now}, {$now}, 0, 0, '', '')");
      $ownerId = (int) $this->db->insert_id;
      $this->query("INSERT INTO {$this->npcs} (owner_id, rift_id) VALUES ({$ownerId}, {$riftId})");
      foreach ($profile['tech'] as $id => $level) {
        if ($level > 0) {
          $this->query("INSERT INTO {$this->units}
            (unit_player_id, unit_location_type, unit_location_id, unit_type, unit_snid, unit_level)
            VALUES ({$ownerId}, 4, {$ownerId}, 100, " . (int) $id . ", " . (int) $level . ")");
        }
      }
      $this->query("UPDATE {$this->rifts} SET npc_owner_id = {$ownerId} WHERE rift_id = {$riftId}");
      $rift['npc_owner_id'] = $ownerId;
    }
    $this->verifyRiftNpc($ownerId, $riftId);
    $this->query("UPDATE {$this->users} SET onlinetime = {$now} WHERE id = {$ownerId}");
    return $ownerId;
  }

  private function verifyRiftNpc(int $ownerId, int $riftId): void {
    $owner = $this->query("SELECT u.* FROM {$this->users} u JOIN {$this->npcs} n ON n.owner_id = u.id
      WHERE n.owner_id = {$ownerId} AND n.rift_id = {$riftId} FOR UPDATE")->fetch_assoc();
    if (!$owner || (int) $owner['user_bot'] !== self::NPC_BOT_TYPE || (int) $owner['authlevel'] !== 0
      || (int) $owner['galaxy'] !== 0 || (int) $owner['id_planet'] !== 0 || (int) $owner['current_planet'] !== 0
      || (int) $owner['parent_account_id'] !== 0 || (int) $owner['parent_account_global'] !== 0
      || !preg_match('/^(?:' . preg_quote(self::NPC_NAME . ' #' . $riftId, '/') . '(?: [a-f0-9]{8})?|'
        . preg_quote('Разлом #' . $riftId, '/') . '|#' . $riftId . ' [a-f0-9]{8})$/D', $owner['username'])
      || strlen($owner['password']) !== 64
      || $owner['password'][0] !== '!') {
      throw new RuntimeException('Zero Galaxy per-rift NPC identity is invalid');
    }
  }

  private function cleanupNpcs(): void {
    $rows = $this->query("SELECT n.* FROM {$this->npcs} n LEFT JOIN {$this->rifts} r ON r.rift_id = n.rift_id
      WHERE r.rift_id IS NULL ORDER BY n.owner_id FOR UPDATE");
    while ($npc = $rows->fetch_assoc()) {
      $ownerId = (int) $npc['owner_id'];
      // Incoming and returning fleets still refer to their original target owner.
      if ($this->query("SELECT fleet_id FROM {$this->fleets} WHERE fleet_owner = {$ownerId}
        OR fleet_target_owner = {$ownerId} LIMIT 1 FOR UPDATE")->num_rows
        || $this->query("SELECT id FROM {$this->planets} WHERE id_owner = {$ownerId} LIMIT 1 FOR UPDATE")->num_rows) {
        continue;
      }
      $this->verifyRiftNpc($ownerId, (int) $npc['rift_id']);
      if ($this->query("SELECT unit_id FROM {$this->units} WHERE unit_player_id = {$ownerId}
        AND (unit_location_type <> 4 OR unit_location_id <> {$ownerId} OR unit_type <> 100) LIMIT 1 FOR UPDATE")->num_rows) {
        throw new RuntimeException('Expired Zero Galaxy NPC contains unexpected units');
      }
      $this->query("DELETE FROM {$this->units} WHERE unit_player_id = {$ownerId}
        AND unit_location_type = 4 AND unit_location_id = {$ownerId} AND unit_type = 100");
      $this->query("DELETE FROM {$this->users} WHERE id = {$ownerId}");
      $this->query("DELETE FROM {$this->npcs} WHERE owner_id = {$ownerId}");
    }
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
    $name = $this->db->real_escape_string(RiftName::forRift($rift, $phase));
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
      $profile = Profile::read($rift);
      if ($profile) {
        foreach ([200 => 'fleet', 400 => 'defence'] as $type => $group) {
          foreach ($profile[$group] as $id => $count) {
            $this->query("INSERT INTO {$this->units}
              (unit_player_id, unit_location_type, unit_location_id, unit_type, unit_snid, unit_level)
              VALUES ({$ownerId}, 1, {$planetId}, {$type}, " . (int) $id . ", " . (int) $count . ")");
          }
        }
        $m = (int) $profile['resources'][901]; $c = (int) $profile['resources'][902]; $d = (int) $profile['resources'][903];
        $this->query("UPDATE {$this->planets} SET metal = {$m}, crystal = {$c}, deuterium = {$d} WHERE id = {$planetId}");
      }
      $this->query("UPDATE {$this->rifts} SET planet_id = {$planetId} WHERE rift_id = " . (int) $rift['rift_id']);
    } else {
      $planetId = (int) $planet['id'];
      // Never refill an existing garrison or its loot. Keep basic native income
      // from accumulating before a dispatch refreshes a target at ETA <= now.
      $this->query("UPDATE {$this->planets} SET name = '{$name}', last_update = {$now}, que_processed = {$now},
        metal_perhour = 0, crystal_perhour = 0,
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
      $legacyOwnerId = $this->lockNpc($state, $now);
      $rows = $this->query("SELECT * FROM {$this->rifts} ORDER BY rift_id FOR UPDATE");
      $occupied = [];
      $categoryCounts = [];
      $nextTransition = $now + $settings['tick_seconds'];
      while ($rift = $rows->fetch_assoc()) {
        $phase = Lifecycle::phase($rift, $now);
        $profile = Profile::read($rift);
        $ownerId = (int) ($rift['npc_owner_id'] ?? 0) ?: $legacyOwnerId;
        if ($profile && in_array($phase, ['active', 'unstable'], true)) {
          $ownerId = $this->lockRiftNpc($rift, $profile, $now);
        } elseif (!empty($rift['npc_owner_id'])) {
          $this->verifyRiftNpc($ownerId, (int) $rift['rift_id']);
        }
        $planet = $this->lockPlanet($rift, $ownerId);
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
        if ($profile) {
          $key = $profile['category']['key'];
          $categoryCounts[$key] = ($categoryCounts[$key] ?? 0) + 1;
        }
        $deadline = Lifecycle::nextTransition($rift, $now);
        if ($deadline > $now) {
          $nextTransition = min($nextTransition, $deadline);
        }
      }
      $this->cleanupNpcs();

      $maxSystem = max(1, $maxSystem);
      $maxPlanet = max(1, $maxPlanet);
      $generationDue = (int) $state['next_generation_at'] <= $now;
      $expectedBirths = Lifecycle::expectedBirths($settings, $settings['generation_seconds']);
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
          $birthSettings = $settings;
          $snapshotData = $settings;
          if (!empty($settings['categories'])) {
            $categoryKey = Profile::choose($settings['categories'], $categoryCounts);
            if ($categoryKey === null) { break; }
            $category = $settings['categories'][$categoryKey];
            $rates = [901 => (float) \SN::$config->rpg_exchange_metal,
              902 => \SN::$config->rpg_exchange_crystal / \SN::$config->rpg_exchange_metal,
              903 => \SN::$config->rpg_exchange_deuterium / \SN::$config->rpg_exchange_metal];
            $profile = Profile::create($categoryKey, $category, $settings['fleet_limit_multiplier'], $rates);
            $snapshotData = ['profile' => $profile, 'category_settings' => $category,
              'fleet_limit_multiplier' => $settings['fleet_limit_multiplier']];
            $birthSettings = array_replace($settings, Settings::categoryPhaseRanges($category));
            $categoryCounts[$categoryKey] = ($categoryCounts[$categoryKey] ?? 0) + 1;
          }
          $snapshot = $this->db->real_escape_string(json_encode($snapshotData, JSON_THROW_ON_ERROR));
          $rift = Lifecycle::create($now, $birthSettings);
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
    $result = $this->query("SELECT r.*, COALESCE(r.npc_owner_id, s.npc_owner_id) AS owner_id, p.id AS materialized_id,
        p.debris_metal, p.debris_crystal
      FROM {$this->rifts} AS r JOIN {$this->state} AS s ON s.id = 1
      LEFT JOIN {$this->planets} AS p ON p.id = r.planet_id AND p.id_owner = COALESCE(r.npc_owner_id, s.npc_owner_id)
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

  public function findById(int $id, int $now): ?array {
    $this->install();
    $coordinates = $this->query("SELECT system, position FROM {$this->rifts} WHERE rift_id = {$id}")->fetch_assoc();
    if (!$coordinates) { return null; }
    $row = $this->inSystem((int) $coordinates['system'], $now)[(int) $coordinates['position']] ?? null;
    return $row && (int) $row['rift_id'] === $id ? $row : null;
  }
}
