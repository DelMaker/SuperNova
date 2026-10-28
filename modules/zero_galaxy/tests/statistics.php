<?php

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Storage.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/NPCStatistics.php';

use ZeroGalaxy\NPCStatistics;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function statisticsTestDb(): mysqli {
  return new mysqli(getenv('SUPERNOVA_DB_HOST') ?: 'db', getenv('SUPERNOVA_DB_USER') ?: 'supernova',
    getenv('SUPERNOVA_DB_PASSWORD') ?: 'supernova-local', getenv('SUPERNOVA_DB_NAME') ?: 'supernova');
}

if (($argv[1] ?? '') === '--worker') {
  $prefix = $argv[2] ?? '';
  if (!preg_match('/^zg_stat_test_[a-f0-9]{10}_$/D', $prefix)) {
    throw new RuntimeException('Statistics worker requires an isolated test prefix');
  }
  NPCStatistics::sync(statisticsTestDb(), $prefix);
  exit;
}

// Matches classPersistent's relevant behavior: SQL write and cache publication
// happen before the caller commits; a later failure can leave a stale cache.
class StatisticsTestCache {
  public $value = 'stale';
  public $writes = 0;
  public $reads = 0;
  public $failAfterWrite = false;
  private $db;
  private $table;

  public function __construct(mysqli $db, string $prefix) {
    $this->db = $db;
    $this->table = $prefix . 'config';
  }

  public function db_saveItem($name, $value) {
    $this->writes++;
    $safe = $this->db->real_escape_string($value);
    $this->db->query("REPLACE INTO {$this->table} (config_name, config_value) VALUES ('stats_hide_player_list', '{$safe}')");
    $this->value = $value;
    if ($this->failAfterWrite) {
      throw new RuntimeException('Fixture cache publication failed');
    }
  }

  public function db_loadItem($name) {
    $this->reads++;
    $row = $this->db->query("SELECT config_value FROM {$this->table} WHERE config_name = 'stats_hide_player_list' FOR UPDATE")->fetch_assoc();
    return $this->value = (string) $row['config_value'];
  }
}

$db = statisticsTestDb();
$prefix = 'zg_stat_test_' . bin2hex(random_bytes(5)) . '_';
$tables = ['zero_galaxy_state', 'zero_galaxy_npcs', 'config', 'users'];
$assertions = 0;
$check = function ($condition, $message) use (&$assertions) {
  $assertions++;
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$scalar = function ($sql) use ($db) {
  return $db->query($sql)->fetch_row()[0];
};
$hidden = function () use ($scalar, $prefix) {
  return $scalar("SELECT config_value FROM {$prefix}config WHERE config_name = 'stats_hide_player_list'");
};
$managed = function () use ($scalar, $prefix) {
  return $scalar("SELECT stats_hidden_ids FROM {$prefix}zero_galaxy_state WHERE id = 1");
};

try {
  $db->query("CREATE TABLE {$prefix}zero_galaxy_state (id INT PRIMARY KEY, npc_owner_id INT, stats_hidden_ids TEXT NULL) ENGINE=InnoDB");
  $db->query("CREATE TABLE {$prefix}zero_galaxy_npcs (owner_id INT PRIMARY KEY, rift_id INT) ENGINE=InnoDB");
  $db->query("CREATE TABLE {$prefix}users (id INT PRIMARY KEY, user_bot INT, authlevel INT,
    parent_account_id INT, parent_account_global INT) ENGINE=InnoDB");
  $db->query("CREATE TABLE {$prefix}config (config_name VARCHAR(100) PRIMARY KEY, config_value TEXT NULL) ENGINE=InnoDB");
  $db->query("INSERT INTO {$prefix}zero_galaxy_state VALUES (1, 2, NULL)");
  $db->query("INSERT INTO {$prefix}users VALUES (2,200,0,0,0), (3,200,0,0,0), (4,0,0,0,0), (5,200,0,0,0)");
  $db->query("INSERT INTO {$prefix}zero_galaxy_npcs VALUES (3,30), (4,40), (5,50)");
  $db->query("INSERT INTO {$prefix}config VALUES ('stats_hide_player_list', '10,0030,1e2,5,garbage')");
  $cache = new StatisticsTestCache($db, $prefix);
  NPCStatistics::sync($db, $prefix, $cache);
  $check($hidden() === '2,3,5,10,30,100', 'Sync must merge real NPCs and effective manual IDs');
  $check($managed() === '2,3', 'Pre-existing manual NPC exclusion must not become module-managed');
  $check($cache->value === $hidden(), 'Cache and committed setting must agree');
  $check((int) $scalar('SELECT @@in_transaction') === 0, 'Sync must finish its transaction');

  $writes = $cache->writes;
  NPCStatistics::sync($db, $prefix, $cache);
  $check($cache->writes === $writes && $cache->reads === 1, 'Unchanged sync must refresh cache without rewriting configuration');
  $db->query("UPDATE {$prefix}config SET config_value = '2,3,5,30,42,100' WHERE config_name = 'stats_hide_player_list'");
  NPCStatistics::sync($db, $prefix, $cache);
  $check($hidden() === '2,3,5,30,42,100', 'Manual additions and removals must survive stale request cache');
  $check($cache->value === $hidden(), 'Unchanged database value must replace a stale cache');

  $db->query("DELETE FROM {$prefix}zero_galaxy_npcs WHERE owner_id IN (3,5)");
  $db->query("DELETE FROM {$prefix}users WHERE id IN (3,5)");
  NPCStatistics::sync($db, $prefix, $cache);
  $check($hidden() === '2,5,30,42,100', 'Cleanup must remove only module-managed retired NPC exclusions');
  $check($managed() === '2', 'Managed list must shrink after NPC cleanup');

  $db->query("INSERT INTO {$prefix}users VALUES (6,200,0,0,0)");
  $db->query("INSERT INTO {$prefix}zero_galaxy_npcs VALUES (6,60)");
  $cache->failAfterWrite = true;
  $failed = false;
  try {
    NPCStatistics::sync($db, $prefix, $cache);
  } catch (RuntimeException $error) {
    $failed = true;
  }
  $check($failed, 'A failed cache publication must propagate to the caller');
  $check($hidden() === '2,5,30,42,100' && $managed() === '2', 'A failed synchronization must roll back both SQL changes');
  $check($cache->value === $hidden(), 'A rolled-back transaction must repair its published cache');
  $check((int) $scalar('SELECT @@in_transaction') === 0, 'Cache repair must leave no transaction open');
  $cache->failAfterWrite = false;
  NPCStatistics::sync($db, $prefix, $cache);
  $check($hidden() === '2,5,6,30,42,100' && $managed() === '2,6', 'Retry must include the new NPC exactly once');

  $db->query("UPDATE {$prefix}users SET user_bot = 0 WHERE id = 2");
  NPCStatistics::sync($db, $prefix, $cache);
  $check($hidden() === '5,6,30,42,100', 'An unrelated or converted human must not remain module-hidden');

  $db->begin_transaction();
  $guarded = false;
  try {
    NPCStatistics::sync($db, $prefix, $cache);
  } catch (RuntimeException $error) {
    $guarded = true;
  }
  $check($guarded && (int) $scalar('SELECT @@in_transaction') === 1, 'Sync must not commit or roll back a foreign transaction');
  $db->rollback();

  $db->begin_transaction();
  $db->query("SELECT * FROM {$prefix}zero_galaxy_state WHERE id = 1 FOR UPDATE");
  $workers = [];
  for ($i = 0; $i < 2; $i++) {
    $process = proc_open([PHP_BINARY, __FILE__, '--worker', $prefix],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
      throw new RuntimeException('Could not start isolated statistics worker');
    }
    fclose($pipes[0]);
    $workers[] = [$process, $pipes];
  }
  $db->query("UPDATE {$prefix}config SET config_value = '5,6,30,42,100,777' WHERE config_name = 'stats_hide_player_list'");
  $db->query("INSERT INTO {$prefix}users VALUES (8,200,0,0,0)");
  $db->query("INSERT INTO {$prefix}zero_galaxy_npcs VALUES (8,80)");
  $db->commit();
  foreach ($workers as [$process, $pipes]) {
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $check(proc_close($process) === 0, 'Concurrent worker must succeed: ' . $output . $errors);
  }
  $check($hidden() === '5,6,8,30,42,100,777', 'Concurrent synchronizers must preserve a committed manual edit and new NPC');
  $check($managed() === '6,8', 'Concurrent synchronizers must retain exactly their managed IDs');
  $cache->value = 'stale again';
  NPCStatistics::sync($db, $prefix, $cache);
  $check($cache->value === $hidden(), 'Next request must refresh cache after a parallel database writer');
} finally {
  $db->rollback();
  foreach ($tables as $table) {
    $db->query("DROP TABLE IF EXISTS {$prefix}{$table}");
  }
  $db->close();
}

echo "Zero Galaxy NPC statistics: {$assertions} assertions passed\n";
