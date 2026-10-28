<?php

namespace ZeroGalaxy;

use InvalidArgumentException;
use mysqli;
use RuntimeException;
use Throwable;

class NPCStatistics {
  /**
   * Keep native NPC exclusions in the existing statistics setting. Only IDs
   * added by this module are managed; pre-existing manual exclusions survive.
   * The cache object must use this same database connection and game prefix.
   */
  public static function sync(mysqli $db, string $prefix, $configCache = null): void {
    if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix) || strlen($prefix) > 40) {
      throw new InvalidArgumentException('Invalid database prefix');
    }
    $status = self::query($db, 'SELECT @@in_transaction AS active, @@autocommit AS automatic')->fetch_assoc();
    if ((int) $status['active'] !== 0 || (int) $status['automatic'] !== 1) {
      throw new RuntimeException('Zero Galaxy statistics cannot start inside another transaction');
    }
    $stateTable = $prefix . 'zero_galaxy_state';
    $configTable = $prefix . 'config';
    if (!$db->begin_transaction()) {
      throw new RuntimeException('Zero Galaxy statistics could not start its transaction');
    }
    try {
      // Lifecycle and statistics writers share the same first lock.
      $state = self::query($db, "SELECT npc_owner_id, stats_hidden_ids FROM {$stateTable} WHERE id = 1 FOR UPDATE")->fetch_assoc();
      if (!$state) {
        throw new RuntimeException('Zero Galaxy statistics state is missing');
      }
      self::query($db, "INSERT IGNORE INTO {$configTable} (config_name, config_value)
        VALUES ('stats_hide_player_list', '')");
      $configuration = self::query($db, "SELECT config_value FROM {$configTable}
        WHERE config_name = 'stats_hide_player_list' FOR UPDATE")->fetch_assoc();
      $current = self::ids((string) $configuration['config_value']);
      $managed = self::ids((string) $state['stats_hidden_ids']);
      $manual = array_diff_key($current, $managed);

      $legacy = (int) $state['npc_owner_id'];
      $rows = self::query($db, "SELECT u.id FROM {$prefix}users u
        JOIN {$prefix}zero_galaxy_npcs n ON n.owner_id = u.id
        WHERE u.user_bot = " . Storage::NPC_BOT_TYPE . " AND u.authlevel = 0
          AND u.parent_account_id = 0 AND u.parent_account_global = 0
        UNION SELECT u.id FROM {$prefix}users u WHERE u.id = {$legacy}
          AND u.user_bot = " . Storage::NPC_BOT_TYPE . " AND u.authlevel = 0
          AND u.parent_account_id = 0 AND u.parent_account_global = 0");
      $npcs = [];
      while ($row = $rows->fetch_assoc()) {
        $npcs[(int) $row['id']] = true;
      }
      $newManaged = array_diff_key($npcs, $manual);
      $newHidden = self::pack($manual + $npcs);
      $managedValue = self::pack($newManaged);
      if (strlen($newHidden) > 65535 || strlen($managedValue) > 65535) {
        throw new RuntimeException('Zero Galaxy statistics exclusions exceed the configuration field capacity');
      }

      if ($newHidden !== (string) $configuration['config_value']) {
        if ($configCache) {
          // Native classPersistent writes both DB and cache while the SQL row
          // remains locked. A failed transaction repairs that cache below.
          $configCache->db_saveItem('stats_hide_player_list', $newHidden);
        } else {
          $safe = $db->real_escape_string($newHidden);
          self::query($db, "UPDATE {$configTable} SET config_value = '{$safe}'
            WHERE config_name = 'stats_hide_player_list'");
        }
      } elseif ($configCache) {
        // Refresh even without writes: another process may have changed the
        // setting since this request loaded its in-memory configuration.
        $configCache->db_loadItem('stats_hide_player_list');
      }
      if ($managedValue !== (string) $state['stats_hidden_ids']) {
        $safeManaged = $db->real_escape_string($managedValue);
        self::query($db, "UPDATE {$stateTable} SET stats_hidden_ids = '{$safeManaged}' WHERE id = 1");
      }
      if (!$db->commit()) {
        throw new RuntimeException('Zero Galaxy statistics could not commit its transaction');
      }
    } catch (Throwable $error) {
      $db->rollback();
      if ($configCache) {
        self::repairCache($db, $configTable, $configCache);
      }
      throw $error;
    }
  }

  private static function query(mysqli $db, string $sql) {
    $result = $db->query($sql);
    if ($result === false) {
      throw new RuntimeException('Zero Galaxy statistics database error: ' . $db->error, $db->errno);
    }
    return $result;
  }

  private static function ids(string $value): array {
    $result = [];
    foreach (explode(',', $value) as $item) {
      $item = trim($item);
      if (!is_numeric($item)) {
        continue;
      }
      $number = (float) $item;
      if (!is_finite($number) || $number < 1 || floor($number) !== $number || $number >= PHP_INT_MAX) {
        continue;
      }
      $result[(int) $number] = true;
    }
    return $result;
  }

  private static function pack(array $ids): string {
    $ids = array_keys($ids);
    sort($ids, SORT_NUMERIC);
    return implode(',', $ids);
  }

  private static function repairCache(mysqli $db, string $configTable, $configCache): void {
    try {
      $db->begin_transaction();
      self::query($db, "SELECT config_value FROM {$configTable}
        WHERE config_name = 'stats_hide_player_list' FOR UPDATE");
      $configCache->db_loadItem('stats_hide_player_list');
      $db->commit();
    } catch (Throwable $repairError) {
      $db->rollback();
      error_log('Zero Galaxy statistics cache repair: ' . $repairError->getMessage());
    }
  }
}
