<?php

namespace ZeroGalaxy;

use RuntimeException;

class NativeNpcPlanets implements WorldBodies {
  private $sql;
  private $owners = [];
  private $knownOwners = [];
  private $refresh = [];

  public function __construct(Sql $sql) { $this->sql = $sql; }

  public function syncOwners(array $categories, int $now): array {
    $this->owners = $this->knownOwners = $this->refresh = [];
    $registered = [];
    $rows = $this->sql->query('SELECT u.*, n.category_key FROM ' . $this->sql->table('zero_galaxy_npcs') . ' n
      JOIN ' . $this->sql->table('users') . ' u ON u.id = n.owner_id ORDER BY u.id FOR UPDATE');
    while ($owner = $rows->fetch_assoc()) {
      $this->verifyOwner($owner);
      $registered[$owner['category_key']] = (int) $owner['id'];
    }
    foreach ($categories as $key => $category) {
      $this->owners[$key] = $registered[$key] ?? $this->createOwner($key, $category['name'], $now);
    }
    if ($this->owners) {
      $this->syncTech($categories);
      $this->sql->query('UPDATE ' . $this->sql->table('users') . " SET onlinetime = {$now}
        WHERE id IN (" . implode(',', $this->owners) . ')');
    }
    return $this->owners;
  }

  private function verifyOwner(array $owner): void {
    if (!Npc::matches($owner) || (int) $owner['galaxy'] !== 0 || (int) $owner['id_planet'] !== 0
      || (int) $owner['current_planet'] !== 0 || strlen($owner['password']) !== 64 || $owner['password'][0] !== '!') {
      throw new RuntimeException('Zero Galaxy NPC identity is invalid');
    }
    $this->knownOwners[(int) $owner['id']] = true;
  }

  private function createOwner(string $key, string $categoryName, int $now): int {
    $users = $this->sql->table('users');
    $name = $this->sql->escape(RiftName::npc($categoryName));
    while ($this->sql->query("SELECT id FROM {$users} WHERE username = '{$name}' LIMIT 1")->num_rows) {
      $name = 'Rift ' . bin2hex(random_bytes(8));
    }
    // A non-MD5 sentinel disables the native legacy login; no account is created.
    $password = '!' . bin2hex(random_bytes(31)) . '!';
    $this->sql->query("INSERT INTO {$users}
      (username, password, salt, authlevel, user_bot, galaxy, system, planet, id_planet, current_planet,
       parent_account_id, parent_account_global, register_time, onlinetime, vacation, deltime, email, email_2)
      VALUES ('{$name}', '{$password}', '', 0, " . Npc::BOT_TYPE . ", 0, 0, 0, 0, 0,
        0, 0, {$now}, {$now}, 0, 0, '', '')");
    $ownerId = $this->sql->insertId();
    $this->sql->query('INSERT INTO ' . $this->sql->table('zero_galaxy_npcs') . " (owner_id, category_key)
      VALUES ({$ownerId}, '" . $this->sql->escape($key) . "')");
    $this->knownOwners[$ownerId] = true;
    return $ownerId;
  }

  /** Research belongs to the category NPC and follows the current settings. */
  private function syncTech(array $categories): void {
    $units = $this->sql->table('unit');
    $levels = [];
    $rows = $this->sql->query("SELECT unit_player_id, unit_snid, unit_level FROM {$units}
      WHERE unit_player_id IN (" . implode(',', $this->owners) . ")
        AND unit_location_type = 4 AND unit_location_id = unit_player_id AND unit_type = 100 FOR UPDATE");
    while ($row = $rows->fetch_assoc()) {
      $levels[(int) $row['unit_player_id']][(int) $row['unit_snid']] = (int) $row['unit_level'];
    }
    foreach ($this->owners as $key => $ownerId) {
      $tech = array_filter($categories[$key]['tech']);
      if (($levels[$ownerId] ?? []) == $tech) { continue; }
      $this->sql->query("DELETE FROM {$units} WHERE unit_player_id = {$ownerId} AND unit_location_type = 4
        AND unit_location_id = {$ownerId} AND unit_type = 100");
      $values = [];
      foreach ($tech as $id => $level) {
        $values[] = "({$ownerId}, 4, {$ownerId}, 100, " . (int) $id . ', ' . (int) $level . ')';
      }
      if ($values) {
        $this->sql->query("INSERT INTO {$units}
          (unit_player_id, unit_location_type, unit_location_id, unit_type, unit_snid, unit_level) VALUES " . implode(', ', $values));
      }
    }
  }

  public function lockPlanets(array $rifts): array {
    $ids = [];
    foreach ($rifts as $rift) {
      if ((int) $rift['planet_id']) { $ids[] = (int) $rift['planet_id']; }
    }
    $rows = [];
    if ($ids) {
      $result = $this->sql->query('SELECT * FROM ' . $this->sql->table('planets') . ' WHERE id IN ('
        . implode(',', $ids) . ') ORDER BY id FOR UPDATE');
      while ($planet = $result->fetch_assoc()) { $rows[(int) $planet['id']] = $planet; }
    }
    $planets = [];
    foreach ($rifts as $rift) {
      $planet = $rows[(int) $rift['planet_id']] ?? null;
      if (!$planet) { continue; }
      $ownerId = (int) $rift['npc_owner_id'];
      if (!isset($this->knownOwners[$ownerId]) || (int) $planet['id_owner'] !== $ownerId
        || (int) $planet['galaxy'] !== 0 || (int) $planet['planet_type'] !== 1
        || (int) $planet['system'] !== (int) $rift['system'] || (int) $planet['planet'] !== (int) $rift['position']) {
        throw new RuntimeException('Zero Galaxy planet ownership or coordinates changed; refusing to alter it');
      }
      $planets[(int) $rift['rift_id']] = $planet;
    }
    return $planets;
  }

  public function materialize(array $rift, int $ownerId, int $now, string $phase, ?array $planet): void {
    $planets = $this->sql->table('planets');
    $name = RiftName::forRift($rift, $phase);
    if ($planet) {
      // Never refill an existing garrison or its loot; finish() only freezes native income.
      $this->refresh[] = (int) $planet['id'];
      if ($planet['name'] !== $name) {
        $this->sql->query("UPDATE {$planets} SET name = '" . $this->sql->escape($name) . "' WHERE id = " . (int) $planet['id']);
      }
      return;
    }
    $system = (int) $rift['system'];
    $position = (int) $rift['position'];
    if ($this->sql->one("SELECT id FROM {$planets} WHERE galaxy = 0
      AND system = {$system} AND planet = {$position} LIMIT 1 FOR UPDATE")) {
      throw new RuntimeException('Zero Galaxy coordinates are occupied; refusing to replace another planet');
    }
    $profile = Profile::read($rift);
    $resources = array_map('intval', ($profile['resources'] ?? []) + [901 => 0, 902 => 0, 903 => 0]);
    $this->sql->query("INSERT INTO {$planets}
      (name, id_owner, galaxy, system, planet, planet_type, image, last_update, que_processed,
       metal, crystal, deuterium, metal_perhour, crystal_perhour, deuterium_perhour,
       energy_max, energy_used, metal_max, crystal_max, deuterium_max,
       field_current, position_original, field_max_original, temp_min_original, temp_max_original)
      VALUES ('" . $this->sql->escape($name) . "', {$ownerId}, 0, {$system}, {$position}, 1,
        '" . $this->sql->escape($rift['image']) . "', {$now}, {$now},
        {$resources[901]}, {$resources[902]}, {$resources[903]}, 0, 0, 0, 0, 0, 0, 0, 0, 0, {$position}, 163, 0, 40)");
    $planetId = $this->sql->insertId();
    $units = [];
    foreach ([200 => 'fleet', 400 => 'defence'] as $type => $group) {
      foreach ($profile[$group] ?? [] as $id => $count) {
        $units[] = "({$ownerId}, 1, {$planetId}, {$type}, " . (int) $id . ', ' . (int) $count . ')';
      }
    }
    if ($units) {
      $this->sql->query('INSERT INTO ' . $this->sql->table('unit') . '
        (unit_player_id, unit_location_type, unit_location_id, unit_type, unit_snid, unit_level) VALUES ' . implode(', ', $units));
    }
    $this->sql->query('UPDATE ' . $this->sql->table('zero_galaxy_rifts') . " SET planet_id = {$planetId}
      WHERE rift_id = " . (int) $rift['rift_id']);
  }

  public function removePlanet(array $rift, int $ownerId, ?array $planet): void {
    $planetId = (int) $rift['planet_id'];
    if ($planet) {
      $units = $this->sql->table('unit');
      $foreignUnit = $this->sql->one("SELECT unit_id FROM {$units} WHERE unit_location_type = 1
        AND unit_location_id = {$planetId} AND (unit_player_id IS NULL OR unit_player_id <> {$ownerId})
        LIMIT 1 FOR UPDATE");
      if ($foreignUnit) {
        throw new RuntimeException('Zero Galaxy planet contains units of another owner; refusing deletion');
      }
      $this->sql->query("DELETE FROM {$units} WHERE unit_location_type = 1
        AND unit_location_id = {$planetId} AND unit_player_id = {$ownerId}");
      // Native FK nulls the target ID, retaining incoming fleets and their times.
      $this->sql->query('DELETE FROM ' . $this->sql->table('planets') . " WHERE id = {$planetId} AND id_owner = {$ownerId}
        AND galaxy = 0 AND system = " . (int) $rift['system'] . ' AND planet = ' . (int) $rift['position'] . ' AND planet_type = 1');
    }
    if ($planetId) {
      $this->sql->query('UPDATE ' . $this->sql->table('zero_galaxy_rifts') . ' SET planet_id = NULL
        WHERE rift_id = ' . (int) $rift['rift_id']);
    }
  }

  public function finish(int $now): void {
    // Keep basic native income from accumulating before a dispatch refreshes a target at ETA <= now.
    foreach (array_chunk($this->refresh, 1000) as $ids) {
      $this->sql->query('UPDATE ' . $this->sql->table('planets') . " SET last_update = {$now}, que_processed = {$now},
        metal_perhour = 0, crystal_perhour = 0, deuterium_perhour = 0, energy_max = 0, energy_used = 0
        WHERE id IN (" . implode(',', $ids) . ')');
    }
    $this->refresh = [];
    $this->retireUnusedOwners();
  }

  private function retireUnusedOwners(): void {
    $planets = $this->sql->table('planets');
    $fleets = $this->sql->table('fleets');
    foreach (array_diff(array_keys($this->knownOwners), $this->owners) as $ownerId) {
      if ($this->sql->query("SELECT id FROM {$planets} WHERE id_owner = {$ownerId} LIMIT 1 FOR UPDATE")->num_rows
        || $this->sql->query("SELECT fleet_id FROM {$fleets} WHERE fleet_owner = {$ownerId} LIMIT 1 FOR UPDATE")->num_rows) {
        throw new RuntimeException('Zero Galaxy retired NPC still owns planets or fleets');
      }
      $this->sql->query("UPDATE {$fleets} SET fleet_target_owner = 0 WHERE fleet_target_owner = {$ownerId}");
      $this->sql->query('DELETE FROM ' . $this->sql->table('unit') . " WHERE unit_player_id = {$ownerId}");
      $this->sql->query('DELETE FROM ' . $this->sql->table('users') . " WHERE id = {$ownerId}");
      $this->sql->query('DELETE FROM ' . $this->sql->table('zero_galaxy_npcs') . " WHERE owner_id = {$ownerId}");
    }
  }
}
