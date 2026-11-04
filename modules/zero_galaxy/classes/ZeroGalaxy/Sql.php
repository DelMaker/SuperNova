<?php

namespace ZeroGalaxy;

use InvalidArgumentException;
use RuntimeException;
use SN;
use Throwable;

/** Module SQL on the game connection; native DBAL transaction flags are not involved. */
final class Sql {
  private $db;
  private $prefix;

  /** @param \mysqli $db */
  public function __construct($db, string $prefix) {
    if (!self::validPrefix($prefix)) {
      throw new InvalidArgumentException('Invalid database prefix');
    }
    $this->db = $db;
    $this->prefix = $prefix;
  }

  public static function fromGame(): self {
    return new self(SN::$db->link, SN::$db->db_prefix);
  }

  public static function validPrefix(string $prefix): bool {
    return strlen($prefix) <= 40 && preg_match('/^[a-zA-Z0-9_]*$/D', $prefix) === 1;
  }

  public function table(string $name): string { return $this->prefix . $name; }
  public function escape(string $value): string { return $this->db->real_escape_string($value); }
  public function insertId(): int { return (int) $this->db->insert_id; }

  /** A failed statement may have rolled the transaction back, so processing must stop. */
  public function query(string $sql) {
    $result = $this->db->query($sql);
    if ($result === false) {
      throw new RuntimeException('Zero Galaxy database error: ' . $this->db->error, $this->db->errno);
    }
    return $result;
  }

  public function one(string $sql): ?array {
    return $this->query($sql)->fetch_assoc();
  }

  public function assertOutsideTransaction(): void {
    $status = $this->one('SELECT @@in_transaction AS active, @@autocommit AS automatic');
    if ((int) $status['active'] !== 0 || (int) $status['automatic'] !== 1) {
      throw new RuntimeException('Zero Galaxy cannot start inside another transaction');
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
}
