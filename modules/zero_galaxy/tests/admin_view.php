<?php

require __DIR__ . '/support/bootstrap.php';
zgCoreConstants();
require __DIR__ . '/support/template.php';
require __DIR__ . '/support/database.php';
require_once SN_ROOT_PHYSICAL . 'includes/db/db_queries_users.php';

use ZeroGalaxy\AdminView;
use ZeroGalaxy\Sql;

class SN { public static $lang = []; }

$tables = new ZgTables(zgDb(), 'admin');
$db = $tables->db;
$prefix = $tables->prefix;
$config = (object) ['game_users_online_timeout' => 900];

function doquery($sql) {
  global $db, $prefix;
  return $db->query(preg_replace('/\{\{([a-z_]+)\}\}/', $prefix . '$1', $sql));
}

$newTemplate = function (): ZgTemplate { return ZgTemplate::fixture(['userlist' => ZgTemplate::native('admin/userlist')]); };
$list = function (bool $online = false) use ($newTemplate): ZgTemplate {
  $template = $newTemplate();
  $ipCounts = [];
  $result = db_user_list_admin_multi_accounts();
  while ($row = $result->fetch_assoc()) { $ipCounts[strtolower($row['user_lastip'])] = (int) $row['ip_count']; }
  $result = db_user_list_admin_sorted('u.id', $online, false);
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
  AdminView::filter($template, $tables->sql());
  $check($snapshot === $template->_tpldata, 'Non-admin views must not query or mutate anything');
  define('IN_ADMIN', true);
  $template->files['userlist'] = 'modules/another/userlist.tpl.html';
  $check(!AdminView::appliesTo($template), 'Same handle with another template must not be selected');
  zgExpect(function () use ($db) { new Sql($db, 'unsafe-prefix;'); }, InvalidArgumentException::class,
    'Unsafe SQL prefixes must be rejected before querying');

  $db->query("CREATE TABLE {$prefix}users (id INT PRIMARY KEY, username VARCHAR(100), user_bot INT DEFAULT 0,
    authlevel INT DEFAULT 0, parent_account_id INT DEFAULT 0, parent_account_global INT DEFAULT 0,
    user_lastip VARCHAR(100), user_as_ally INT NULL, onlinetime INT) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci");
  $db->query("CREATE TABLE {$prefix}referrals (id INT PRIMARY KEY, id_partner INT, dark_matter INT) ENGINE=InnoDB");
  $db->query("CREATE TABLE {$prefix}zero_galaxy_npcs (owner_id INT PRIMARY KEY, category_key VARCHAR(64)) ENGINE=InnoDB");
  $db->query("INSERT INTO {$prefix}users (id,username,user_bot,authlevel,parent_account_id,parent_account_global,user_lastip,user_as_ally,onlinetime) VALUES
    (2,'Registered NPC',200,0,0,0,'127.0.0.1',NULL,1700000000),
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
  $db->query("INSERT INTO {$prefix}zero_galaxy_npcs VALUES (2,'tiny'),(3,'small'),(4,'medium'),(5,'large'),
    (30,'huge'),(31,'colossal'),(32,'great'),(33,'custom'),(99,'missing')");
  $nativeTemplate = file_get_contents(ZgTemplate::native('admin/userlist'));

  foreach ([false, true] as $online) {
    $template = $list($online);
    $nativeRows = count($template->_tpldata['user']);
    AdminView::filter($template, $tables->sql());
    $expected = $online ? [10,11,20,30,31,32,33,40,41,50] : [10,11,12,20,30,31,32,33,40,41];
    $check($ids($template) === array_values($expected), 'Only owned NPCs disappear; native order and unrelated users survive');
    $check($template->_rootref['USER_COUNT'] === count($expected) && $nativeRows > count($expected), 'Displayed count must match remaining rows');
    $check($byId($template,10)['IP_MULTI'] === 0, 'Offline NPCs must not create a false duplicate-IP warning');
    $check($byId($template,11)['IP_MULTI'] === 4, 'Offline human duplicates must still count on the online overview');
    $check($byId($template,31)['IP_MULTI'] === 4, 'NPC alliance records absent from native duplicate-IP query must not reduce human counts');
    $check($byId($template,41)['IP_MULTI'] === 0, 'IPv6 casing must match native case-insensitive IP grouping');
    $html = $template->renderSource($nativeTemplate);
    preg_match_all('/<tr uid="([0-9]+)"/', $html, $renderedIds);
    $check(array_map('intval', $renderedIds[1]) === array_values($expected), 'Real native template must render exactly the filtered user rows');
    $check(strpos($html, '(' . count($expected) . ')</h2>') !== false, 'Native page heading must render the corrected count');
    $snapshot = $template->_tpldata;
    AdminView::filter($template, $tables->sql());
    $check($snapshot === $template->_tpldata, 'Repeated display hooks must not subtract hidden IP counts twice');
  }

  $empty = $newTemplate();
  $empty->assign_block_vars('user', ['ID' => 2, 'IP' => '127.0.0.1', 'IP_MULTI' => 3]);
  $empty->assign_var('USER_COUNT', 1);
  AdminView::filter($empty, $tables->sql());
  $check($empty->_tpldata['user'] === [] && $empty->_rootref['USER_COUNT'] === 0, 'An NPC-only overview must become an empty list with zero count');
  $check(strpos($empty->renderSource($nativeTemplate), '<tr uid=') === false, 'An empty native block must not leave phantom NPC rows');
  $empty = $newTemplate();
  AdminView::filter($empty, $tables->sql());
  $check($empty->_rootref['USER_COUNT'] === 0, 'An originally empty overview must render zero users');

  $db->query("UPDATE {$prefix}users SET user_bot=0 WHERE id=2");
  $template = $list();
  AdminView::filter($template, $tables->sql());
  $check(in_array(2, $ids($template), true) && $byId($template,10)['IP_MULTI'] === 2,
    'A registered ID converted to an ordinary player must remain visible and count as a real IP duplicate');
  $template = $list();
  $beforeFailure = $template->_tpldata;
  $failed = false;
  try { AdminView::filter($template, new Sql($db, $prefix . 'missing_')); }
  catch (Throwable $error) { $failed = true; }
  $check($failed && $beforeFailure === $template->_tpldata, 'A database error must leave the native admin list intact for safe fallback');
} finally {
  $tables->drop();
  $db->close();
}

echo "Zero Galaxy admin visibility: {$assertions} assertions passed; isolated SQL and real native userlist template.\n";
