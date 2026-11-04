<?php

/** Tables under a random prefix; drop() removes everything created with it. */
final class ZgTables {
  public $db;
  public $prefix;
  private $gamePrefix;

  /** @param string|null $prefix A prefix received by a worker process of the same check. */
  public function __construct(mysqli $db, string $label, string $prefix = null) {
    $this->db = $db;
    $this->prefix = $prefix ?? 'zg_' . $label . '_' . bin2hex(random_bytes(5)) . '_';
    if (!preg_match('/^zg_' . preg_quote($label, '/') . '_[a-f0-9]{10}_$/D', $this->prefix)) {
      throw new RuntimeException('An isolated test prefix is required');
    }
  }

  public function sql(): \ZeroGalaxy\Sql { return new \ZeroGalaxy\Sql($this->db, $this->prefix); }

  public function cloneGame(array $tables, string $source = 'sn_'): void {
    foreach ($tables as $table) {
      $this->db->query("CREATE TABLE {$this->prefix}{$table} LIKE {$source}{$table}");
    }
  }

  public function one(string $sql): ?array { return $this->db->query($sql)->fetch_assoc(); }
  public function scalar(string $sql) { return $this->db->query($sql)->fetch_row()[0]; }
  public function rows(string $sql): array { return $this->db->query($sql)->fetch_all(MYSQLI_ASSOC); }

  /** Native {{table}} queries of the bootstrapped game use these tables until restoreGame(). */
  public function useInGame(): void {
    $this->gamePrefix = SN::$db->db_prefix;
    SN::$db->db_prefix = $this->prefix;
    SN::$db->schema()->clear();
    \Unit\DBStaticUnit::cache_clear();
  }

  public function restoreGame(): void {
    if ($this->gamePrefix === null) { return; }
    if (\DBAL\db_mysql::$db_in_transaction) { \DBAL\db_mysql::db_transaction_rollback(); }
    SN::$db->db_prefix = $this->gamePrefix;
    SN::$db->schema()->clear();
    \Unit\DBStaticUnit::cache_clear();
  }

  public function drop(): void {
    if ((int) $this->scalar('SELECT @@in_transaction') !== 0) { $this->db->rollback(); }
    $pattern = $this->db->real_escape_string(addcslashes($this->prefix, '_%')) . '%';
    $tables = array_column($this->db->query("SHOW TABLES LIKE '{$pattern}'")->fetch_all(), 0);
    $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $table) { $this->db->query("DROP TABLE IF EXISTS `{$table}`"); }
    $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
  }
}

/** Fails only the source planet refresh after a committed dispatch, through the real DBAL. */
final class ZgRefreshFailure {
  public $failures = 0;
  private $db;
  private $sourceId;

  public function __construct($db, int $sourceId) {
    $this->db = $db;
    $this->sourceId = $sourceId;
  }

  public function __get($name) { return $this->db->$name; }
  public function __call($name, $arguments) { return $this->db->$name(...$arguments); }

  public function doquery($query, $fetch = false, $skip = false) {
    if (!$this->failures && !\DBAL\db_mysql::$db_in_transaction
      && strpos($query, 'SELECT * FROM {{planets}}') === 0 && strpos($query, '`id` = ' . $this->sourceId) !== false) {
      $this->failures++;
      throw new RuntimeException('Injected committed source refresh failure');
    }
    return $this->db->doquery($query, $fetch, $skip);
  }
}
