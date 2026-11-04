<?php

require __DIR__ . '/support/bootstrap.php';
require __DIR__ . '/support/database.php';

use ZeroGalaxy\NPCStatistics;

if (($argv[1] ?? '') === '--worker') {
  NPCStatistics::sync((new ZgTables(zgDb(), 'stats', $argv[2] ?? ''))->sql());
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

$tables = new ZgTables(zgDb(), 'stats');
$db = $tables->db;
$prefix = $tables->prefix;
$hidden = function () use ($tables, $prefix) {
  return $tables->scalar("SELECT config_value FROM {$prefix}config WHERE config_name = 'stats_hide_player_list'");
};
$managed = function () use ($tables, $prefix) {
  return $tables->scalar("SELECT stats_hidden_ids FROM {$prefix}zero_galaxy_state WHERE id = 1");
};
$sync = function () use ($tables, &$cache) { NPCStatistics::sync($tables->sql(), $cache); };

try {
  $db->query("CREATE TABLE {$prefix}zero_galaxy_state (id INT PRIMARY KEY, stats_hidden_ids TEXT NULL) ENGINE=InnoDB");
  $db->query("CREATE TABLE {$prefix}zero_galaxy_npcs (owner_id INT PRIMARY KEY, category_key VARCHAR(64)) ENGINE=InnoDB");
  $db->query("CREATE TABLE {$prefix}users (id INT PRIMARY KEY, user_bot INT, authlevel INT,
    parent_account_id INT, parent_account_global INT) ENGINE=InnoDB");
  $db->query("CREATE TABLE {$prefix}config (config_name VARCHAR(100) PRIMARY KEY, config_value TEXT NULL) ENGINE=InnoDB");
  $db->query("INSERT INTO {$prefix}zero_galaxy_state VALUES (1, NULL)");
  $db->query("INSERT INTO {$prefix}users VALUES (2,200,0,0,0), (3,200,0,0,0), (4,0,0,0,0), (5,200,0,0,0)");
  $db->query("INSERT INTO {$prefix}zero_galaxy_npcs VALUES (2,'tiny'), (3,'small'), (4,'medium'), (5,'large')");
  $db->query("INSERT INTO {$prefix}config VALUES ('stats_hide_player_list', '10,0030,1e2,5,garbage')");
  $cache = new StatisticsTestCache($db, $prefix);
  $sync();
  $check($hidden() === '2,3,5,10,30,100', 'Sync must merge real NPCs and effective manual IDs');
  $check($managed() === '2,3', 'Pre-existing manual NPC exclusion must not become module-managed');
  $check($cache->value === $hidden(), 'Cache and committed setting must agree');
  $check((int) $tables->scalar('SELECT @@in_transaction') === 0, 'Sync must finish its transaction');

  $writes = $cache->writes;
  $inserts = $db->query("SHOW SESSION STATUS LIKE 'Com_insert'")->fetch_row()[1];
  $sync();
  $check($cache->writes === $writes && $cache->reads === 1, 'Unchanged sync must refresh cache without rewriting configuration');
  $check($db->query("SHOW SESSION STATUS LIKE 'Com_insert'")->fetch_row()[1] === $inserts,
    'Unchanged sync must not take the shared write path');
  $db->query("UPDATE {$prefix}config SET config_value = '2,3,5,30,42,100' WHERE config_name = 'stats_hide_player_list'");
  $sync();
  $check($hidden() === '2,3,5,30,42,100', 'Manual additions and removals must survive stale request cache');
  $check($cache->value === $hidden(), 'Unchanged database value must replace a stale cache');

  $db->query("DELETE FROM {$prefix}zero_galaxy_npcs WHERE owner_id IN (3,5)");
  $db->query("DELETE FROM {$prefix}users WHERE id IN (3,5)");
  $sync();
  $check($hidden() === '2,5,30,42,100', 'Cleanup must remove only module-managed retired NPC exclusions');
  $check($managed() === '2', 'Managed list must shrink after NPC cleanup');

  $db->query("INSERT INTO {$prefix}users VALUES (6,200,0,0,0)");
  $db->query("INSERT INTO {$prefix}zero_galaxy_npcs VALUES (6,'huge')");
  $cache->failAfterWrite = true;
  zgExpect($sync, RuntimeException::class, 'A failed cache publication must propagate to the caller');
  $check($hidden() === '2,5,30,42,100' && $managed() === '2', 'A failed synchronization must roll back both SQL changes');
  $check($cache->value === $hidden(), 'A rolled-back transaction must repair its published cache');
  $check((int) $tables->scalar('SELECT @@in_transaction') === 0, 'Cache repair must leave no transaction open');
  $cache->failAfterWrite = false;
  $sync();
  $check($hidden() === '2,5,6,30,42,100' && $managed() === '2,6', 'Retry must include the new NPC exactly once');

  $db->query("UPDATE {$prefix}users SET user_bot = 0 WHERE id = 2");
  $sync();
  $check($hidden() === '5,6,30,42,100', 'An unrelated or converted human must not remain module-hidden');

  $db->begin_transaction();
  zgExpect($sync, RuntimeException::class, 'Sync must refuse to run inside a foreign transaction');
  $check((int) $tables->scalar('SELECT @@in_transaction') === 1, 'Sync must not commit or roll back a foreign transaction');
  $db->rollback();

  $db->query("UPDATE {$prefix}config SET config_value = '5,6,30,42,100,777' WHERE config_name = 'stats_hide_player_list'");
  $db->query("INSERT INTO {$prefix}users VALUES (8,200,0,0,0)");
  $db->query("INSERT INTO {$prefix}zero_galaxy_npcs VALUES (8,'great')");
  $db->begin_transaction();
  $db->query("SELECT * FROM {$prefix}zero_galaxy_state WHERE id = 1 FOR UPDATE");
  $workers = [zgWorker(['--worker', $prefix]), zgWorker(['--worker', $prefix])];
  usleep(300000);
  $db->commit();
  foreach ($workers as $worker) {
    [$code, $output, $errors] = zgFinish($worker);
    $check($code === 0, 'Concurrent worker must succeed: ' . $output . $errors);
  }
  $check($hidden() === '5,6,8,30,42,100,777', 'Concurrent synchronizers must preserve a committed manual edit and new NPC');
  $check($managed() === '6,8', 'Concurrent synchronizers must retain exactly their managed IDs');
} finally {
  $tables->drop();
  $db->close();
}

echo "Zero Galaxy NPC statistics: {$assertions} assertions passed\n";
