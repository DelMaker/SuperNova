<?php

namespace ZeroGalaxy;

use Throwable;

class SchemaInstaller {
  private $records;
  private $rifts;
  private $state;
  private $npcs;
  private $ready = false;

  public function __construct(RiftRepositorySql $records) {
    $this->records = $records;
    $this->rifts = $records->table('zero_galaxy_rifts');
    $this->state = $records->table('zero_galaxy_state');
    $this->npcs = $records->table('zero_galaxy_npcs');
  }

  public function install(): void {
    if ($this->ready) {
      return;
    }
    $this->records->assertOutsideTransaction();
    try {
      // A new Storage object is created on every request. Check the installed
      // schema and singleton with a read before attempting any DDL or writes.
      $installed = $this->records->query("SELECT s.id, s.npc_owner_id, s.stats_hidden_ids,
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
    $this->records->query("CREATE TABLE IF NOT EXISTS {$this->rifts} (
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
    $this->records->query("CREATE TABLE IF NOT EXISTS {$this->npcs} (
      owner_id BIGINT UNSIGNED NOT NULL,
      rift_id BIGINT UNSIGNED NOT NULL,
      PRIMARY KEY (owner_id), UNIQUE KEY rift (rift_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $this->records->query("CREATE TABLE IF NOT EXISTS {$this->state} (
      id TINYINT UNSIGNED NOT NULL,
      next_tick_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
      next_generation_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
      seeded_systems INT UNSIGNED NOT NULL DEFAULT 0,
      npc_owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      stats_hidden_ids TEXT NULL,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    // Preserve timelines from the earlier module-only draft.
    if (!$this->records->query("SHOW COLUMNS FROM {$this->rifts} LIKE 'planet_id'")->num_rows) {
      $this->records->query("ALTER TABLE {$this->rifts} ADD COLUMN IF NOT EXISTS planet_id BIGINT UNSIGNED NULL DEFAULT NULL");
    }
    if (!$this->records->query("SHOW COLUMNS FROM {$this->state} LIKE 'npc_owner_id'")->num_rows) {
      $this->records->query("ALTER TABLE {$this->state} ADD COLUMN IF NOT EXISTS npc_owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0");
    }
    if (!$this->records->query("SHOW COLUMNS FROM {$this->rifts} LIKE 'npc_owner_id'")->num_rows) {
      $this->records->query("ALTER TABLE {$this->rifts} ADD COLUMN IF NOT EXISTS npc_owner_id BIGINT UNSIGNED NULL DEFAULT NULL");
    }
    if (!$this->records->query("SHOW COLUMNS FROM {$this->state} LIKE 'stats_hidden_ids'")->num_rows) {
      $this->records->query("ALTER TABLE {$this->state} ADD COLUMN IF NOT EXISTS stats_hidden_ids TEXT NULL");
    }
    $this->records->query("INSERT IGNORE INTO {$this->state} (id) VALUES (1)");
    $this->ready = true;
  }
}
