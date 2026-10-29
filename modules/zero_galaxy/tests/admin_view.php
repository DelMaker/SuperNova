<?php

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

define('INSIDE', true);
define('SN_ROOT_PHYSICAL', dirname(__DIR__, 3) . '/');
define('SN_ROOT_RELATIVE', '');
define('SN_TIME_NOW', 1700000000);
require_once SN_ROOT_PHYSICAL . 'classes/template_compile.php';
require_once SN_ROOT_PHYSICAL . 'classes/template.php';
require_once SN_ROOT_PHYSICAL . 'includes/db/db_queries_users.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Storage.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/AdminView.php';

use ZeroGalaxy\AdminView;

class SN { public static $lang = []; }
class AdminFixtureTemplate extends template {
  public function renderSource(string $source): string {
    ob_start();
    $this->evaluate($this->compiler->compile($source));
    return ob_get_clean();
  }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(getenv('SUPERNOVA_DB_HOST') ?: 'db', getenv('SUPERNOVA_DB_USER') ?: 'supernova',
  getenv('SUPERNOVA_DB_PASSWORD') ?: 'supernova-local', getenv('SUPERNOVA_DB_NAME') ?: 'supernova');
$prefix = 'zg_admin_' . bin2hex(random_bytes(5)) . '_';
$tables = ['zero_galaxy_state', 'zero_galaxy_npcs', 'config', 'referrals', 'users'];
$config = (object) ['game_users_online_timeout' => 900];

// Exercise the real native SELECT functions against isolated fixture tables.
function doquery($sql) {
  global $db, $prefix;
  return $db->query(preg_replace('/\{\{([a-z_]+)\}\}/', $prefix . '$1', $sql));
}

$assertions = 0;
$check = function ($condition, string $message) use (&$assertions) {
  $assertions++;
  if (!$condition) { throw new RuntimeException($message); }
};
$newTemplate = function (): AdminFixtureTemplate {
  $template = new AdminFixtureTemplate();
  $template->_rootref = &$template->_tpldata['.'][0];
  $template->files['userlist'] = SN_ROOT_PHYSICAL . 'design/templates/OpenGame/admin/userlist.tpl.html';
  return $template;
};
$list = function (bool $online = false, bool $desc = false) use ($newTemplate): AdminFixtureTemplate {
  $template = $newTemplate();
  $ipCounts = [];
  $result = db_user_list_admin_multi_accounts();
  while ($row = $result->fetch_assoc()) { $ipCounts[strtolower($row['user_lastip'])] = (int) $row['ip_count']; }
  $result = db_user_list_admin_sorted('u.id', $online, $desc);
  while ($row = $result->fetch_assoc()) {
    $template->assign_block_vars('user', ['ID' => $row['id'], 'NAME' => $row['username'], 'NAME_HTML' => $row['username'],
      'IP' => $row['user_lastip'], 'IP_MULTI' => $ipCounts[strtolower($row['user_lastip'])] ?? 0,
      'REFERRAL_COUNT' => $row['referral_count'], 'REFERRAL_DM' => $row['referral_dm'] ?? 0]);
  }
  $template->assign_vars(['USER_COUNT' => $result->num_rows, 'PAGE_HEADER' => $online ? 'Online' : 'Players']);
  return $template;
};
$ids = function ($template): array { return array_map('intval', array_column($template->_tpldata['user'], 'ID')); };
$byId = function ($template, int $id): array {
  foreach ($template->_tpldata['user'] as $row) { if ((int) $row['ID'] === $id) { return $row; } }
  throw new RuntimeException('Missing fixture row ' . $id);
};

try {
  $template = $newTemplate();
  $template->assign_block_vars('user', ['ID' => 2]);
  $snapshot = $template->_tpldata;
  $check(!AdminView::appliesTo($template), 'Native userlist outside IN_ADMIN must not be selected');
  AdminView::filter($template, $db, 'unsafe-prefix;');
  $check($snapshot === $template->_tpldata, 'Non-admin views must not query or mutate anything');
  define('IN_ADMIN', true);
  $check(AdminView::appliesTo($template), 'Admin native player template must be selected');
  $check(!AdminView::appliesTo(null) && !AdminView::appliesTo('admin/userlist'), 'Non-template values must be ignored');
  $template->files['userlist'] = 'modules/another/userlist.tpl.html';
  $check(!AdminView::appliesTo($template), 'Same handle with another template must not be selected');
  $template->files = ['users' => 'admin/userlist.tpl.html'];
  $check(!AdminView::appliesTo($template), 'Different template handles must be ignored');
  $invalid = false;
  try { AdminView::filter($newTemplate(), $db, 'unsafe-prefix;'); }
  catch (InvalidArgumentException $error) { $invalid = true; }
  $check($invalid, 'Unsafe SQL prefixes must be rejected before querying');

  $db->query("CREATE TABLE {$prefix}users (id INT PRIMARY KEY, username VARCHAR(100), user_bot INT DEFAULT 0,
    authlevel INT DEFAULT 0, parent_account_id INT DEFAULT 0, parent_account_global INT DEFAULT 0,
    user_lastip VARCHAR(100), user_as_ally INT NULL, onlinetime INT) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci");
  $db->query("CREATE TABLE {$prefix}referrals (id INT PRIMARY KEY, id_partner INT, dark_matter INT) ENGINE=InnoDB");
  $db->query("CREATE TABLE {$prefix}zero_galaxy_state (id INT PRIMARY KEY, npc_owner_id INT) ENGINE=InnoDB");
  $db->query("CREATE TABLE {$prefix}zero_galaxy_npcs (owner_id INT PRIMARY KEY, rift_id INT) ENGINE=InnoDB");
  $db->query("CREATE TABLE {$prefix}config (config_name VARCHAR(100) PRIMARY KEY, config_value TEXT) ENGINE=InnoDB");
  $db->query("INSERT INTO {$prefix}users (id,username,user_bot,authlevel,parent_account_id,parent_account_global,user_lastip,user_as_ally,onlinetime) VALUES
    (2,'Legacy NPC',200,0,0,0,'127.0.0.1',NULL,1700000000),
    (3,'Offline registered NPC',200,0,0,0,'127.0.0.1',NULL,0),
    (4,'IPv6 NPC',200,0,0,0,'2001:DB8::1',NULL,1700000000),
    (5,'Ally-marker NPC',200,0,0,0,'127.0.0.3',1,1700000000),
    (10,'Human shares NPC IP',0,0,0,0,'127.0.0.1',NULL,1700000000),
    (11,'Online duplicate human',0,0,0,0,'127.0.0.2',NULL,1700000000),
    (12,'Offline duplicate human',0,0,0,0,'127.0.0.2',NULL,0),
    (20,'Zero Galaxy unrelated bot',200,0,0,0,'127.0.0.2',NULL,1700000000),
    (30,'Converted registered human',0,0,0,0,'127.0.0.2',NULL,1700000000),
    (31,'Promoted registered bot',200,3,0,0,'127.0.0.3',NULL,1700000000),
    (32,'Linked registered bot',200,0,1,0,'127.0.0.3',NULL,1700000000),
    (33,'Globally linked bot',200,0,0,1,'127.0.0.3',NULL,1700000000),
    (40,'Manually statistics-hidden human',0,0,0,0,'127.0.0.3',NULL,1700000000),
    (41,'IPv6 human',0,0,0,0,'2001:db8::1',NULL,1700000000),
    (50,'Native alliance record',0,0,0,0,'127.0.0.4',1,1700000000)");
  $db->query("INSERT INTO {$prefix}zero_galaxy_state VALUES (1,2)");
  $db->query("INSERT INTO {$prefix}zero_galaxy_npcs VALUES (2,20),(3,30),(4,40),(5,50),(30,300),(31,310),(32,320),(33,330),(99,990)");
  $db->query("INSERT INTO {$prefix}config VALUES ('stats_hide_player_list','2,3,4,40')");
  $db->query("INSERT INTO {$prefix}referrals VALUES (1,10,100),(2,10,200)");
  $nativeTemplate = file_get_contents(SN_ROOT_PHYSICAL . 'design/templates/OpenGame/admin/userlist.tpl.html');

  foreach ([false, true] as $online) {
    foreach ([false, true] as $desc) {
      $template = $list($online, $desc);
      $nativeRows = count($template->_tpldata['user']);
      AdminView::filter($template, $db, $prefix);
      $expected = $online ? [10,11,20,30,31,32,33,40,41,50] : [10,11,12,20,30,31,32,33,40,41];
      // Native sorting groups non-alliance rows first in either direction.
      if ($desc) {
        $expected = array_reverse(array_diff($expected, [50]));
        if ($online) { $expected[] = 50; }
      }
      $check($ids($template) === array_values($expected), 'Only owned NPCs disappear; native order and unrelated users survive');
      $check($template->_rootref['USER_COUNT'] === count($expected) && $nativeRows > count($expected), 'Displayed count must match remaining rows');
      $check($byId($template,10)['IP_MULTI'] === 0, 'Offline and legacy NPCs must not create a false duplicate-IP warning');
      $check($byId($template,11)['IP_MULTI'] === 4, 'Offline human duplicates must still count on the online overview');
      $check($byId($template,31)['IP_MULTI'] === 4, 'NPC alliance records absent from native duplicate-IP query must not reduce human counts');
      $check($byId($template,41)['IP_MULTI'] === 0, 'IPv6 casing must match native case-insensitive IP grouping');
      $check((int) $byId($template,10)['REFERRAL_COUNT'] === 2 && (int) $byId($template,10)['REFERRAL_DM'] === 300,
        'Native referral aggregates must survive presentation filtering');
      foreach ($template->_tpldata['user'] as $index => $row) {
        $check($row['S_ROW_COUNT'] === $index && !empty($row['S_FIRST_ROW']) === ($index === 0)
          && !empty($row['S_LAST_ROW']) === ($index === count($expected)-1), 'Native block position metadata must be rebuilt');
      }
      $html = $template->renderSource($nativeTemplate);
      preg_match_all('/<tr uid="([0-9]+)"/', $html, $renderedIds);
      $check(array_map('intval', $renderedIds[1]) === array_values($expected), 'Real native template must render exactly the filtered user rows');
      $check(strpos($html, '(' . count($expected) . ')</h2>') !== false, 'Native page heading must render the corrected count');
      $snapshot = $template->_tpldata;
      AdminView::filter($template, $db, $prefix);
      $check($snapshot === $template->_tpldata, 'Repeated display hooks must not subtract hidden IP counts twice');
    }
  }

  $empty = $newTemplate();
  $empty->assign_block_vars('user', ['ID' => 2, 'IP' => '127.0.0.1', 'IP_MULTI' => 3]);
  $empty->assign_var('USER_COUNT', 1);
  AdminView::filter($empty, $db, $prefix);
  $check($empty->_tpldata['user'] === [] && $empty->_rootref['USER_COUNT'] === 0, 'An NPC-only overview must become an empty list with zero count');
  $check(strpos($empty->renderSource($nativeTemplate), '<tr uid=') === false, 'An empty native block must not leave phantom NPC rows');
  $empty = $newTemplate();
  AdminView::filter($empty, $db, $prefix);
  $check($empty->_rootref['USER_COUNT'] === 0, 'An originally empty overview must render zero users');

  $db->query("UPDATE {$prefix}users SET user_bot=0 WHERE id=2");
  $template = $list();
  AdminView::filter($template, $db, $prefix);
  $check(in_array(2, $ids($template), true) && $byId($template,10)['IP_MULTI'] === 2,
    'A legacy ID converted to an ordinary player must remain visible and count as a real IP duplicate');
  $check($db->query("SELECT config_value FROM {$prefix}config")->fetch_row()[0] === '2,3,4,40', 'Statistics exclusions must not be read or rewritten for admin visibility');
  $check((int) $db->query('SELECT @@in_transaction')->fetch_row()[0] === 0, 'Read-only filtering must not leave a transaction open');
  $template = $list();
  $beforeFailure = $template->_tpldata;
  $failed = false;
  try { AdminView::filter($template, $db, $prefix . 'missing_'); }
  catch (Throwable $error) { $failed = true; }
  $check($failed && $beforeFailure === $template->_tpldata, 'A database error must leave the native admin list intact for safe fallback');
} finally {
  foreach ($tables as $table) { $db->query("DROP TABLE IF EXISTS {$prefix}{$table}"); }
  $db->close();
}

echo "Zero Galaxy admin visibility: {$assertions} assertions passed; isolated SQL and real native userlist template.\n";
