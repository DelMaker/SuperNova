<?php

namespace ZeroGalaxy;

use RuntimeException;
use Throwable;

class NPCStatistics {
  /**
   * Keep native NPC exclusions in the existing statistics setting. Only IDs
   * added by this module are managed; pre-existing manual exclusions survive.
   * The cache object must use this same database connection and game prefix.
   */
  public static function sync(Sql $sql, $configCache = null): void {
    $sql->assertOutsideTransaction();
    // NPC sets rarely change: compare without locks before taking the shared ones.
    $plan = self::plan($sql, false);
    if ($plan['hidden'] === $plan['current'] && $plan['managed'] === $plan['stored']) {
      if ($configCache) {
        // Another process may have changed the setting since this request loaded its configuration.
        $configCache->db_loadItem('stats_hide_player_list');
      }
      return;
    }
    try {
      $sql->transaction(function () use ($sql, $configCache): void {
        // Lifecycle and statistics writers share the same first lock.
        $plan = self::plan($sql, true);
        if (strlen($plan['hidden']) > 65535 || strlen($plan['managed']) > 65535) {
          throw new RuntimeException('Zero Galaxy statistics exclusions exceed the configuration field capacity');
        }
        if ($plan['hidden'] !== $plan['current']) {
          if ($configCache) {
            // Native classPersistent writes both DB and cache while the SQL row
            // remains locked. A failed transaction repairs that cache below.
            $configCache->db_saveItem('stats_hide_player_list', $plan['hidden']);
          } else {
            $sql->query('UPDATE ' . $sql->table('config') . " SET config_value = '" . $sql->escape($plan['hidden'])
              . "' WHERE config_name = 'stats_hide_player_list'");
          }
        } elseif ($configCache) {
          $configCache->db_loadItem('stats_hide_player_list');
        }
        if ($plan['managed'] !== $plan['stored']) {
          $sql->query('UPDATE ' . $sql->table('zero_galaxy_state') . " SET stats_hidden_ids = '"
            . $sql->escape($plan['managed']) . "' WHERE id = 1");
        }
      });
    } catch (Throwable $error) {
      if ($configCache) {
        self::repairCache($sql, $configCache);
      }
      throw $error;
    }
  }

  private static function plan(Sql $sql, bool $lock): array {
    $forUpdate = $lock ? ' FOR UPDATE' : '';
    $state = $sql->one('SELECT stats_hidden_ids FROM ' . $sql->table('zero_galaxy_state') . ' WHERE id = 1' . $forUpdate);
    if (!$state) {
      throw new RuntimeException('Zero Galaxy statistics state is missing');
    }
    $config = $sql->table('config');
    if ($lock) {
      $sql->query("INSERT IGNORE INTO {$config} (config_name, config_value) VALUES ('stats_hide_player_list', '')");
    }
    $configuration = $sql->one("SELECT config_value FROM {$config} WHERE config_name = 'stats_hide_player_list'" . $forUpdate);
    $current = (string) ($configuration['config_value'] ?? '');
    $managed = self::ids((string) $state['stats_hidden_ids']);
    $manual = array_diff_key(self::ids($current), $managed);
    $npcs = [];
    $rows = $sql->query('SELECT u.id FROM ' . Npc::fromSql($sql));
    while ($row = $rows->fetch_assoc()) {
      $npcs[(int) $row['id']] = true;
    }
    return ['current' => $current, 'stored' => (string) $state['stats_hidden_ids'],
      'hidden' => self::pack($manual + $npcs), 'managed' => self::pack(array_diff_key($npcs, $manual))];
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

  private static function repairCache(Sql $sql, $configCache): void {
    try {
      $sql->transaction(function () use ($sql, $configCache): void {
        $sql->query('SELECT config_value FROM ' . $sql->table('config') . " WHERE config_name = 'stats_hide_player_list' FOR UPDATE");
        $configCache->db_loadItem('stats_hide_player_list');
      });
    } catch (Throwable $repairError) {
      error_log('Zero Galaxy statistics cache repair: ' . $repairError->getMessage());
    }
  }
}
