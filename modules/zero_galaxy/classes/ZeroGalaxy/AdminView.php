<?php

namespace ZeroGalaxy;

use InvalidArgumentException;
use mysqli;
use RuntimeException;

class AdminView {
  public static function appliesTo($template): bool {
    return defined('IN_ADMIN') && IN_ADMIN === true && is_object($template)
      && isset($template->files['userlist']) && is_string($template->files['userlist'])
      && preg_match('~(?:^|/)admin/userlist\.tpl\.html$~D', str_replace('\\', '/', $template->files['userlist'])) === 1;
  }

  /** Native online overview and full player list share this presentation. */
  public static function filter($template, mysqli $db, string $prefix): void {
    if (!self::appliesTo($template) || !empty($template->_rootref['ZERO_GALAXY_ADMIN_FILTERED'])) {
      return;
    }
    if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix) || strlen($prefix) > 40) {
      throw new InvalidArgumentException('Invalid database prefix');
    }
    // Registry membership alone must never hide an ordinary or promoted user.
    // Include offline NPCs because native duplicate-IP counts use all users.
    $result = $db->query("SELECT u.id, u.user_lastip, u.user_as_ally FROM {$prefix}users u
      WHERE u.id IN (SELECT owner_id FROM {$prefix}zero_galaxy_npcs
        UNION SELECT npc_owner_id FROM {$prefix}zero_galaxy_state WHERE id = 1)
        AND u.user_bot = " . Storage::NPC_BOT_TYPE . " AND u.authlevel = 0
        AND u.parent_account_id = 0 AND u.parent_account_global = 0");
    if ($result === false) {
      throw new RuntimeException('Zero Galaxy admin visibility database error: ' . $db->error, $db->errno);
    }
    $hidden = $ipCounts = [];
    while ($npc = $result->fetch_assoc()) {
      $hidden[(int) $npc['id']] = true;
      if ($npc['user_as_ally'] === null) {
        $ip = strtolower((string) $npc['user_lastip']);
        $ipCounts[$ip] = ($ipCounts[$ip] ?? 0) + 1;
      }
    }
    $rows = $template->_tpldata['user'] ?? [];
    $template->_tpldata['user'] = [];
    foreach ($rows as $row) {
      if (isset($hidden[(int) ($row['ID'] ?? 0)])) {
        continue;
      }
      $duplicates = (int) ($row['IP_MULTI'] ?? 0) - ($ipCounts[strtolower((string) ($row['IP'] ?? ''))] ?? 0);
      $row['IP_MULTI'] = $duplicates > 1 ? $duplicates : 0;
      unset($row['S_ROW_COUNT'], $row['S_FIRST_ROW'], $row['S_LAST_ROW']);
      $template->assign_block_vars('user', $row);
    }
    $template->assign_var('USER_COUNT', count($template->_tpldata['user']));
    $template->assign_var('ZERO_GALAXY_ADMIN_FILTERED', true);
  }
}
