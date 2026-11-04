<?php

namespace ZeroGalaxy;

use Throwable;

class SchemaInstaller {
  /** MySQL ER_NO_SUCH_TABLE; RiftRepositorySql also reports a missing state row with it. */
  const MISSING = 1146;

  private $sql;

  public function __construct(Sql $sql) { $this->sql = $sql; }

  public static function isMissing(Throwable $error): bool {
    return (int) $error->getCode() === self::MISSING;
  }

  /** DDL commits implicitly, so installation never runs inside a caller transaction. */
  public function install(): void {
    $this->sql->assertOutsideTransaction();
    $rifts = $this->sql->table('zero_galaxy_rifts');
    $npcs = $this->sql->table('zero_galaxy_npcs');
    $state = $this->sql->table('zero_galaxy_state');
    $this->sql->query("CREATE TABLE IF NOT EXISTS {$rifts} (
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
    $this->sql->query("CREATE TABLE IF NOT EXISTS {$npcs} (
      owner_id BIGINT UNSIGNED NOT NULL,
      category_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      PRIMARY KEY (owner_id), UNIQUE KEY category (category_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $this->sql->query("CREATE TABLE IF NOT EXISTS {$state} (
      id TINYINT UNSIGNED NOT NULL,
      next_tick_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
      next_generation_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
      seeded_systems INT UNSIGNED NOT NULL DEFAULT 0,
      stats_hidden_ids TEXT NULL,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $this->sql->query("INSERT IGNORE INTO {$state} (id) VALUES (1)");
  }
}
