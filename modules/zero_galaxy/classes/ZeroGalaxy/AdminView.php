<?php

namespace ZeroGalaxy;

class AdminView {
  public static function appliesTo($template): bool {
    return defined('IN_ADMIN') && IN_ADMIN === true && is_object($template)
      && (new NativeTemplateAdapter($template))->isNative('userlist', 'admin/');
  }

  public static function filter($template, Sql $sql): void {
    if (!self::appliesTo($template) || (new NativeTemplateAdapter($template))->value('ZERO_GALAXY_ADMIN_FILTERED')) {
      return;
    }
    // Include offline NPCs because native duplicate-IP counts use all users.
    $result = $sql->query('SELECT u.id, u.user_lastip, u.user_as_ally FROM ' . Npc::fromSql($sql));
    $hidden = $ipCounts = [];
    while ($npc = $result->fetch_assoc()) {
      $hidden[(int) $npc['id']] = true;
      if ($npc['user_as_ally'] === null) {
        $ip = strtolower((string) $npc['user_lastip']);
        $ipCounts[$ip] = ($ipCounts[$ip] ?? 0) + 1;
      }
    }
    $adapter = new NativeTemplateAdapter($template);
    $visible = [];
    foreach ($adapter->rows('user') as $row) {
      if (isset($hidden[(int) ($row['ID'] ?? 0)])) {
        continue;
      }
      $duplicates = (int) ($row['IP_MULTI'] ?? 0) - ($ipCounts[strtolower((string) ($row['IP'] ?? ''))] ?? 0);
      $row['IP_MULTI'] = $duplicates > 1 ? $duplicates : 0;
      $visible[] = $row;
    }
    $template->assign_var('USER_COUNT', $adapter->replaceRows('user', $visible));
    $template->assign_var('ZERO_GALAXY_ADMIN_FILTERED', true);
  }
}
