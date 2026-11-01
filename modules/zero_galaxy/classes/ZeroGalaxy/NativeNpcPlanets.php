<?php

namespace ZeroGalaxy;

use RuntimeException;

class NativeNpcPlanets implements WorldBodies {
  private $records;
  private $rifts;
  private $state;
  private $users;
  private $planets;
  private $units;
  private $npcs;
  private $fleets;

  public function __construct(RiftRepositorySql $records) {
    $this->records = $records;
    foreach (['rifts' => 'zero_galaxy_rifts', 'state' => 'zero_galaxy_state', 'npcs' => 'zero_galaxy_npcs',
      'users' => 'users', 'planets' => 'planets', 'units' => 'unit', 'fleets' => 'fleets'] as $property => $table) {
      $this->$property = $records->table($table);
    }
  }

  private function npcName(int $ownerId = 0): string {
    $name = Storage::NPC_NAME;
    $safe = $this->records->escape($name);
    if ($this->records->query("SELECT id FROM {$this->users} WHERE username = '{$safe}' AND id <> {$ownerId} LIMIT 1")->num_rows) {
      $name .= ' ' . bin2hex(random_bytes(2));
    }
    return $this->records->escape($name);
  }

  private function disabledPassword(): string {
    // Non-hex, non-numeric and longer than MD5; even loose comparison cannot match.
    return '!' . bin2hex(random_bytes(31)) . '!';
  }

  public function legacyOwner(array $state, int $now): int {
    $ownerId = (int) $state['npc_owner_id'];
    if (!$ownerId) {
      $name = $this->npcName();
      // Legacy passwords are 32-character MD5 hashes. This value cannot match;
      // no account or account translation is created for this NPC.
      $password = $this->disabledPassword();
      $this->records->query("INSERT INTO {$this->users}
        (username, password, salt, authlevel, user_bot, galaxy, system, planet,
         id_planet, current_planet, parent_account_id, parent_account_global,
         register_time, onlinetime, vacation, deltime, email, email_2)
        VALUES ('{$name}', '{$password}', '', 0, " . Storage::NPC_BOT_TYPE . ", 0, 0, 0,
          0, 0, 0, 0, {$now}, {$now}, 0, 0, '', '')");
      $ownerId = $this->records->insertId();
      $this->records->query("UPDATE {$this->state} SET npc_owner_id = {$ownerId} WHERE id = 1");
    }
    $owner = $this->records->query("SELECT * FROM {$this->users} WHERE id = {$ownerId} FOR UPDATE")->fetch_assoc();
    if (!$owner || (int) $owner['authlevel'] !== 0 || (int) $owner['user_bot'] !== Storage::NPC_BOT_TYPE
      || (int) $owner['galaxy'] !== 0 || (int) $owner['id_planet'] !== 0 || (int) $owner['current_planet'] !== 0
      || (int) $owner['parent_account_id'] !== 0 || (int) $owner['parent_account_global'] !== 0
      || (strpos($owner['username'], Storage::NPC_NAME) !== 0 && !preg_match('/^Zero Galaxy [a-f0-9]{16}$/D', $owner['username']))
      || strlen($owner['password']) !== 64) {
      throw new RuntimeException('Zero Galaxy NPC ownership marker is invalid; no planets were changed');
    }
    if (strpos($owner['username'], Storage::NPC_NAME) !== 0) {
      $name = $this->npcName($ownerId);
      $this->records->query("UPDATE {$this->users} SET username = '{$name}' WHERE id = {$ownerId}");
    }
    if ($owner['password'][0] !== '!') {
      $password = $this->disabledPassword();
      $this->records->query("UPDATE {$this->users} SET password = '{$password}' WHERE id = {$ownerId}");
    }
    $this->records->query("UPDATE {$this->users} SET onlinetime = {$now} WHERE id = {$ownerId}");
    return $ownerId;
  }

  public function planet(array $rift, int $ownerId): ?array {
    $planetId = (int) $rift['planet_id'];
    if (!$planetId) {
      return null;
    }
    $planet = $this->records->query("SELECT * FROM {$this->planets} WHERE id = {$planetId} FOR UPDATE")->fetch_assoc();
    if (!$planet) {
      return null;
    }
    if ((int) $planet['id_owner'] !== $ownerId || (int) $planet['galaxy'] !== 0
      || (int) $planet['system'] !== (int) $rift['system'] || (int) $planet['planet'] !== (int) $rift['position']
      || (int) $planet['planet_type'] !== 1) {
      throw new RuntimeException('Zero Galaxy planet ownership or coordinates changed; refusing to alter it');
    }
    return $planet;
  }

  public function riftOwner(array &$rift, array $profile, int $now): int {
    $riftId = (int) $rift['rift_id'];
    $ownerId = (int) ($rift['npc_owner_id'] ?? 0);
    if (!$ownerId) {
      $baseName = Storage::NPC_NAME . ' #' . $riftId;
      if (!preg_match('/^.{1,32}$/uD', $baseName)) { $baseName = 'Разлом #' . $riftId; }
      $name = $this->records->escape($baseName);
      while ($this->records->query("SELECT id FROM {$this->users} WHERE username = '{$name}' LIMIT 1")->num_rows) {
        // Even a 64-bit rift ID plus nonce fits the native 32-character name.
        $name = $this->records->escape('#' . $riftId . ' ' . bin2hex(random_bytes(4)));
      }
      $password = $this->disabledPassword();
      $this->records->query("INSERT INTO {$this->users}
        (username, password, salt, authlevel, user_bot, galaxy, system, planet, id_planet, current_planet,
         parent_account_id, parent_account_global, register_time, onlinetime, vacation, deltime, email, email_2)
        VALUES ('{$name}', '{$password}', '', 0, " . Storage::NPC_BOT_TYPE . ", 0, 0, 0, 0, 0,
          0, 0, {$now}, {$now}, 0, 0, '', '')");
      $ownerId = $this->records->insertId();
      $this->records->query("INSERT INTO {$this->npcs} (owner_id, rift_id) VALUES ({$ownerId}, {$riftId})");
      foreach ($profile['tech'] as $id => $level) {
        if ($level > 0) {
          $this->records->query("INSERT INTO {$this->units}
            (unit_player_id, unit_location_type, unit_location_id, unit_type, unit_snid, unit_level)
            VALUES ({$ownerId}, 4, {$ownerId}, 100, " . (int) $id . ", " . (int) $level . ")");
        }
      }
      $this->records->query("UPDATE {$this->rifts} SET npc_owner_id = {$ownerId} WHERE rift_id = {$riftId}");
      $rift['npc_owner_id'] = $ownerId;
    }
    $this->verifyOwner($ownerId, $riftId);
    $this->records->query("UPDATE {$this->users} SET onlinetime = {$now} WHERE id = {$ownerId}");
    return $ownerId;
  }

  public function verifyOwner(int $ownerId, int $riftId): void {
    $owner = $this->records->query("SELECT u.* FROM {$this->users} u JOIN {$this->npcs} n ON n.owner_id = u.id
      WHERE n.owner_id = {$ownerId} AND n.rift_id = {$riftId} FOR UPDATE")->fetch_assoc();
    if (!$owner || (int) $owner['user_bot'] !== Storage::NPC_BOT_TYPE || (int) $owner['authlevel'] !== 0
      || (int) $owner['galaxy'] !== 0 || (int) $owner['id_planet'] !== 0 || (int) $owner['current_planet'] !== 0
      || (int) $owner['parent_account_id'] !== 0 || (int) $owner['parent_account_global'] !== 0
      || !preg_match('/^(?:' . preg_quote(Storage::NPC_NAME . ' #' . $riftId, '/') . '(?: [a-f0-9]{8})?|'
        . preg_quote('Разлом #' . $riftId, '/') . '|#' . $riftId . ' [a-f0-9]{8})$/D', $owner['username'])
      || strlen($owner['password']) !== 64
      || $owner['password'][0] !== '!') {
      throw new RuntimeException('Zero Galaxy per-rift NPC identity is invalid');
    }
  }

  public function removeUnusedOwners(): void {
    // Lock owners before inventory, including reads inside the filtering subquery.
    $this->records->query("SELECT u.id FROM {$this->users} u JOIN {$this->npcs} n ON n.owner_id = u.id
      LEFT JOIN {$this->rifts} r ON r.rift_id = n.rift_id
      WHERE r.rift_id IS NULL ORDER BY u.id FOR UPDATE");
    // External grants may add player inventory; preserve it and defer retirement.
    $rows = $this->records->query("SELECT n.* FROM {$this->npcs} n LEFT JOIN {$this->rifts} r ON r.rift_id = n.rift_id
      WHERE r.rift_id IS NULL AND NOT EXISTS (SELECT 1 FROM {$this->units} u
        WHERE u.unit_player_id = n.owner_id
          AND (u.unit_location_type <> 4 OR u.unit_location_id <> n.owner_id OR u.unit_type <> 100))
      ORDER BY n.owner_id FOR UPDATE");
    while ($npc = $rows->fetch_assoc()) {
      $ownerId = (int) $npc['owner_id'];
      // Incoming and returning fleets still refer to their original target owner.
      if ($this->records->query("SELECT fleet_id FROM {$this->fleets} WHERE fleet_owner = {$ownerId}
        OR fleet_target_owner = {$ownerId} LIMIT 1 FOR UPDATE")->num_rows
        || $this->records->query("SELECT id FROM {$this->planets} WHERE id_owner = {$ownerId} LIMIT 1 FOR UPDATE")->num_rows) {
        continue;
      }
      $this->verifyOwner($ownerId, (int) $npc['rift_id']);
      // Recheck after locking the owner, in case another writer added inventory.
      if ($this->records->query("SELECT unit_id FROM {$this->units} WHERE unit_player_id = {$ownerId}
        AND (unit_location_type <> 4 OR unit_location_id <> {$ownerId} OR unit_type <> 100) LIMIT 1 FOR UPDATE")->num_rows) {
        continue;
      }
      $this->records->query("DELETE FROM {$this->units} WHERE unit_player_id = {$ownerId}
        AND unit_location_type = 4 AND unit_location_id = {$ownerId} AND unit_type = 100");
      $this->records->query("DELETE FROM {$this->users} WHERE id = {$ownerId}");
      $this->records->query("DELETE FROM {$this->npcs} WHERE owner_id = {$ownerId}");
    }
  }

  public function removePlanet(array $rift, int $ownerId, ?array $planet): void {
    $planetId = (int) $rift['planet_id'];
    if ($planet) {
      $foreignUnit = $this->records->query("SELECT unit_id FROM {$this->units} WHERE unit_location_type = 1
        AND unit_location_id = {$planetId} AND (unit_player_id IS NULL OR unit_player_id <> {$ownerId})
        LIMIT 1 FOR UPDATE")->fetch_assoc();
      if ($foreignUnit) {
        throw new RuntimeException('Zero Galaxy planet contains units of another owner; refusing deletion');
      }
      $this->records->query("DELETE FROM {$this->units} WHERE unit_location_type = 1
        AND unit_location_id = {$planetId} AND unit_player_id = {$ownerId}");
      // Native FK nulls the target ID, retaining incoming fleets and their times.
      $this->records->query("DELETE FROM {$this->planets} WHERE id = {$planetId} AND id_owner = {$ownerId}
        AND galaxy = 0 AND system = " . (int) $rift['system'] . " AND planet = " . (int) $rift['position'] . " AND planet_type = 1");
    }
    if ($planetId) {
      $this->records->query("UPDATE {$this->rifts} SET planet_id = NULL WHERE rift_id = " . (int) $rift['rift_id']);
    }
  }

  public function materialize(array $rift, int $ownerId, int $now, string $phase, ?array $planet): void {
    $name = $this->records->escape(RiftName::forRift($rift, $phase));
    if (!$planet) {
      $system = (int) $rift['system'];
      $position = (int) $rift['position'];
      $collision = $this->records->query("SELECT id FROM {$this->planets} WHERE galaxy = 0
        AND system = {$system} AND planet = {$position} LIMIT 1 FOR UPDATE")->fetch_assoc();
      if ($collision) {
        throw new RuntimeException('Zero Galaxy coordinates are occupied; refusing to replace another planet');
      }
      $image = $this->records->escape($rift['image']);
      $this->records->query("INSERT INTO {$this->planets}
        (name, id_owner, galaxy, system, planet, planet_type, image, last_update, que_processed,
         metal, crystal, deuterium, metal_perhour, crystal_perhour, deuterium_perhour,
         energy_max, energy_used, metal_max, crystal_max, deuterium_max,
         field_current, position_original, field_max_original, temp_min_original, temp_max_original)
        VALUES ('{$name}', {$ownerId}, 0, {$system}, {$position}, 1, '{$image}', {$now}, {$now},
          0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, {$position}, 163, 0, 40)");
      $planetId = $this->records->insertId();
      $profile = Profile::read($rift);
      if ($profile) {
        foreach ([200 => 'fleet', 400 => 'defence'] as $type => $group) {
          foreach ($profile[$group] as $id => $count) {
            $this->records->query("INSERT INTO {$this->units}
              (unit_player_id, unit_location_type, unit_location_id, unit_type, unit_snid, unit_level)
              VALUES ({$ownerId}, 1, {$planetId}, {$type}, " . (int) $id . ", " . (int) $count . ")");
          }
        }
        $m = (int) $profile['resources'][901]; $c = (int) $profile['resources'][902]; $d = (int) $profile['resources'][903];
        $this->records->query("UPDATE {$this->planets} SET metal = {$m}, crystal = {$c}, deuterium = {$d} WHERE id = {$planetId}");
      }
      $this->records->query("UPDATE {$this->rifts} SET planet_id = {$planetId} WHERE rift_id = " . (int) $rift['rift_id']);
    } else {
      $planetId = (int) $planet['id'];
      // Never refill an existing garrison or its loot. Keep basic native income
      // from accumulating before a dispatch refreshes a target at ETA <= now.
      $this->records->query("UPDATE {$this->planets} SET name = '{$name}', last_update = {$now}, que_processed = {$now},
        metal_perhour = 0, crystal_perhour = 0,
        deuterium_perhour = 0, energy_max = 0, energy_used = 0 WHERE id = {$planetId}");
    }
  }
}
