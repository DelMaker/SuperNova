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
  private $owners = [];
  private $knownOwners = [];
  private $legacyOwnerId = 0;

  public function __construct(RiftRepositorySql $records) {
    $this->records = $records;
    foreach (['rifts' => 'zero_galaxy_rifts', 'state' => 'zero_galaxy_state', 'npcs' => 'zero_galaxy_npcs',
      'users' => 'users', 'planets' => 'planets', 'units' => 'unit', 'fleets' => 'fleets'] as $property => $table) {
      $this->$property = $records->table($table);
    }
  }

  public function syncOwners(array $categories, array $state, int $now): array {
    $this->owners = $this->knownOwners = [];
    $this->legacyOwnerId = (int) $state['npc_owner_id'];
    $registered = [];
    $rows = $this->records->query("SELECT u.*, n.category_key FROM {$this->npcs} n
      JOIN {$this->users} u ON u.id = n.owner_id ORDER BY u.id FOR UPDATE");
    while ($owner = $rows->fetch_assoc()) {
      $this->verifyOwner($owner);
      $registered[$owner['category_key']] = (int) $owner['id'];
    }
    if ($this->legacyOwnerId && !isset($this->knownOwners[$this->legacyOwnerId])) {
      $owner = $this->records->query("SELECT * FROM {$this->users} WHERE id = {$this->legacyOwnerId} FOR UPDATE")->fetch_assoc();
      $this->verifyOwner($owner);
    }
    foreach ($categories as $key => $category) {
      $ownerId = $registered[$key] ?? $this->createOwner($key, $category['name'], $now);
      $this->owners[$key] = $ownerId;
      $this->syncTech($ownerId, $category['tech']);
      $this->records->query("UPDATE {$this->users} SET onlinetime = {$now} WHERE id = {$ownerId}");
    }
    return $this->owners;
  }

  private function verifyOwner(?array $owner): void {
    if (!$owner || (int) $owner['user_bot'] !== Storage::NPC_BOT_TYPE || (int) $owner['authlevel'] !== 0
      || (int) $owner['galaxy'] !== 0 || (int) $owner['id_planet'] !== 0 || (int) $owner['current_planet'] !== 0
      || (int) $owner['parent_account_id'] !== 0 || (int) $owner['parent_account_global'] !== 0
      || strlen($owner['password']) !== 64 || $owner['password'][0] !== '!') {
      throw new RuntimeException('Zero Galaxy NPC identity is invalid');
    }
    $this->knownOwners[(int) $owner['id']] = true;
  }

  private function createOwner(string $key, string $categoryName, int $now): int {
    $categoryName = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\u{FFFD}", $categoryName);
    preg_match('/^.{1,32}/us', Storage::NPC_NAME . ' ' . $categoryName, $match);
    $name = $this->records->escape($match[0]);
    while ($this->records->query("SELECT id FROM {$this->users} WHERE username = '{$name}' LIMIT 1")->num_rows) {
      $name = 'Rift ' . bin2hex(random_bytes(8));
    }
    // A non-MD5 sentinel disables the native legacy login; no account is created.
    $password = '!' . bin2hex(random_bytes(31)) . '!';
    $this->records->query("INSERT INTO {$this->users}
      (username, password, salt, authlevel, user_bot, galaxy, system, planet, id_planet, current_planet,
       parent_account_id, parent_account_global, register_time, onlinetime, vacation, deltime, email, email_2)
      VALUES ('{$name}', '{$password}', '', 0, " . Storage::NPC_BOT_TYPE . ", 0, 0, 0, 0, 0,
        0, 0, {$now}, {$now}, 0, 0, '', '')");
    $ownerId = $this->records->insertId();
    $key = $this->records->escape($key);
    $this->records->query("INSERT INTO {$this->npcs} (owner_id, category_key) VALUES ({$ownerId}, '{$key}')");
    $this->knownOwners[$ownerId] = true;
    return $ownerId;
  }

  private function syncTech(int $ownerId, array $tech): void {
    $levels = [];
    $where = "unit_player_id = {$ownerId} AND unit_location_type = 4 AND unit_location_id = {$ownerId} AND unit_type = 100";
    $rows = $this->records->query("SELECT unit_snid, unit_level FROM {$this->units} WHERE {$where} FOR UPDATE");
    while ($row = $rows->fetch_assoc()) { $levels[(int) $row['unit_snid']] = (int) $row['unit_level']; }
    $tech = array_filter($tech);
    if ($levels == $tech) { return; }
    $this->records->query("DELETE FROM {$this->units} WHERE {$where}");
    foreach ($tech as $id => $level) {
      $this->records->query("INSERT INTO {$this->units}
        (unit_player_id, unit_location_type, unit_location_id, unit_type, unit_snid, unit_level)
        VALUES ({$ownerId}, 4, {$ownerId}, 100, " . (int) $id . ", " . (int) $level . ")");
    }
  }

  public function assignOwner(array &$rift, int $ownerId, int $legacyOwnerId): void {
    $oldOwnerId = (int) ($rift['npc_owner_id'] ?? 0) ?: $legacyOwnerId;
    if ($oldOwnerId === $ownerId) { return; }
    if ($oldOwnerId && !isset($this->knownOwners[$oldOwnerId])) {
      throw new RuntimeException('Zero Galaxy rift has an unregistered owner');
    }
    $planet = $this->planet($rift, $oldOwnerId);
    if ($planet) {
      $planetId = (int) $planet['id'];
      $this->records->query("UPDATE {$this->units} SET unit_player_id = {$ownerId}
        WHERE unit_location_type = 1 AND unit_location_id = {$planetId} AND unit_player_id = {$oldOwnerId}");
      $this->records->query("UPDATE {$this->planets} SET id_owner = {$ownerId} WHERE id = {$planetId}");
    }
    $this->records->query("UPDATE {$this->fleets} SET fleet_target_owner = {$ownerId}
      WHERE fleet_target_owner = {$oldOwnerId} AND fleet_end_galaxy = 0
        AND fleet_end_system = " . (int) $rift['system'] . " AND fleet_end_planet = " . (int) $rift['position']);
    $this->records->query("UPDATE {$this->rifts} SET npc_owner_id = {$ownerId} WHERE rift_id = " . (int) $rift['rift_id']);
    $rift['npc_owner_id'] = $ownerId;
  }

  public function planet(array $rift, int $ownerId): ?array {
    $planetId = (int) $rift['planet_id'];
    if (!$planetId) {
      return null;
    }
    if (!isset($this->knownOwners[$ownerId])) {
      throw new RuntimeException('Zero Galaxy planet has an unregistered owner');
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

  public function removeUnusedOwners(): void {
    foreach (array_diff(array_keys($this->knownOwners), array_values($this->owners)) as $ownerId) {
      if ($this->records->query("SELECT id FROM {$this->planets} WHERE id_owner = {$ownerId} LIMIT 1 FOR UPDATE")->num_rows
        || $this->records->query("SELECT fleet_id FROM {$this->fleets} WHERE fleet_owner = {$ownerId} LIMIT 1 FOR UPDATE")->num_rows) {
        throw new RuntimeException('Zero Galaxy retired NPC still owns planets or fleets');
      }
      $this->records->query("UPDATE {$this->fleets} SET fleet_target_owner = 0 WHERE fleet_target_owner = {$ownerId}");
      $this->records->query("DELETE FROM {$this->units} WHERE unit_player_id = {$ownerId}");
      $this->records->query("DELETE FROM {$this->users} WHERE id = {$ownerId}");
      $this->records->query("DELETE FROM {$this->npcs} WHERE owner_id = {$ownerId}");
    }
    if ($this->legacyOwnerId) {
      $this->records->query("UPDATE {$this->state} SET npc_owner_id = 0 WHERE id = 1");
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
