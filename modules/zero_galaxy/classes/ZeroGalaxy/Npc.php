<?php

namespace ZeroGalaxy;

/** Service owners of rift planets. Registry membership alone never makes a player an NPC. */
final class Npc {
  const BOT_TYPE = 200;

  public static function matches($user): bool {
    return is_array($user) && isset($user['user_bot'], $user['authlevel'], $user['parent_account_id'], $user['parent_account_global'])
      && (int) $user['user_bot'] === self::BOT_TYPE && (int) $user['authlevel'] === 0
      && (int) $user['parent_account_id'] === 0 && (int) $user['parent_account_global'] === 0;
  }

  public static function sqlWhere(string $alias): string {
    return "{$alias}.user_bot = " . self::BOT_TYPE . " AND {$alias}.authlevel = 0"
      . " AND {$alias}.parent_account_id = 0 AND {$alias}.parent_account_global = 0";
  }

  /** Registered module NPCs that still carry the service identity, as `u` rows. */
  public static function fromSql(Sql $sql): string {
    return $sql->table('users') . ' u JOIN ' . $sql->table('zero_galaxy_npcs') . ' n ON n.owner_id = u.id'
      . ' WHERE ' . self::sqlWhere('u');
  }
}
