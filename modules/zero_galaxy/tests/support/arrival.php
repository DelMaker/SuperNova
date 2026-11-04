<?php

// Native helpers used by the arrival adapters, backed by isolated tables on the SN::$db link.
require_once SN_ROOT_PHYSICAL . 'classes/Fleet/FleetDispatchEvent.php';
require_once SN_ROOT_PHYSICAL . 'classes/Fleet/DbFleetStatic.php';
require_once SN_ROOT_PHYSICAL . 'classes/Planet/DBStaticPlanet.php';

class SN {
  public static $db;
  public static $gc;
  public static $lang = [];

  public static function db_get_record_list($location, $filter, $fetch = false) {
    $table = self::$db->db_prefix . 'planets';
    $result = self::$db->link->query("SELECT * FROM {$table} WHERE " . str_replace('{{planets}}', $table, $filter) . ' FOR UPDATE');
    return $fetch ? $result->fetch_assoc() : $result->fetch_all(MYSQLI_ASSOC);
  }

  public static function db_upd_record_list($location, $filter, $set) {
    return self::$db->link->query('UPDATE ' . self::$db->db_prefix . "planets SET {$set} WHERE {$filter}");
  }
}

/** The native users -> planets -> fleets lock order on the isolated tables. */
class ZgFixtureLocks {
  public function lockRecords($locks) {
    foreach (['users', 'planets', 'fleets'] as $table) {
      if (empty($locks[$table])) { continue; }
      $key = $table === 'fleets' ? 'fleet_id' : 'id';
      SN::$db->link->query("SELECT {$key} FROM " . SN::$db->db_prefix . $table
        . " WHERE {$key} IN (" . implode(',', array_map('intval', $locks[$table])) . ') FOR UPDATE');
    }
    return true;
  }
}

function idval($id) { return (int) $id; }

function db_set_make_safe_string($values) {
  $parts = [];
  foreach ($values as $key => $value) { $parts[] = "`{$key}` = " . (float) $value; }
  return implode(',', $parts);
}

function doquery($sql, $fetch = false) {
  $result = SN::$db->link->query(preg_replace('/\{\{([a-z_]+)\}\}/', SN::$db->db_prefix . '$1', $sql));
  return $fetch ? $result->fetch_assoc() : $result;
}

function db_user_by_id($id) {
  return SN::$db->link->query('SELECT * FROM ' . SN::$db->db_prefix . 'users WHERE id = ' . (int) $id)->fetch_assoc();
}

function sys_o_get_updated($owner, $planet, $time) {
  return ['planet' => SN::$db->link->query('SELECT * FROM ' . SN::$db->db_prefix . 'planets WHERE id = ' . (int) $planet)
    ->fetch_assoc()];
}

function sys_unit_str2arr($text) {
  $units = [];
  foreach (explode(';', $text) as $pair) {
    if ($pair === '') { continue; }
    [$id, $count] = explode(',', $pair);
    $units[(int) $id] = (int) $count;
  }
  return $units;
}

function zgArrivalDb(ZgTables $tables): void {
  SN::$db = (object) ['db_prefix' => $tables->prefix, 'link' => $tables->db];
  SN::$gc = (object) ['db' => new ZgFixtureLocks()];
}

/** A native arrival event to planet 20 of owner 3 without the native constructor's own lookups. */
function zgArrivalEvent(array $fleet, int $mission): \Fleet\FleetDispatchEvent {
  $event = (new ReflectionClass(\Fleet\FleetDispatchEvent::class))->newInstanceWithoutConstructor();
  $event->fleet = $fleet;
  $event->event = EVENT_FLT_ARRIVE;
  $event->missionId = $mission;
  $event->fleetId = (int) $fleet['fleet_id'];
  $event->fleetOwnerId = 1;
  $event->srcPlanetId = 10;
  $event->dstPlanetId = 20;
  $event->dstPlanetOwnerId = 3;
  $event->eventTimeStamp = SN_TIME_NOW;
  $event->userIdsToLock = [1, 3];
  $event->planetIdsToLock = [10, 20];
  $event->fleetIdsToLock = [(int) $fleet['fleet_id']];
  return $event;
}
