<?php

namespace ZeroGalaxy;

use DBAL\db_mysql;
use Planet\DBStaticPlanet;
use SN;
use Throwable;

class RecycleSender {
  /** Quick native recycler selection, with the rift lifecycle locked until commit. */
  public static function send(Storage $storage, array $settings): string {
    global $user, $planetrow, $lang;

    lng_include('universe');
    lng_include('fleet');
    require_once SN_ROOT_PHYSICAL . 'includes/includes/flt_functions.php';

    $system = self::integer('system');
    $position = self::integer('planet');
    $riftId = self::integer('rift_id');
    if (!SN::$sys_user_logged_in || empty($user['id'])
      || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
      || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest'
      || self::integer('galaxy') !== 0 || self::integer('mission') !== MT_RECYCLE
      || self::integer('planet_type') !== PT_DEBRIS || $riftId === null || $riftId < 1
      || $system === null || $system < 1 || $system > (int) SN::$config->game_maxSystem
      || $position === null || $position < 1 || $position > (int) SN::$config->game_maxPlanet) {
      return $lang['zero_galaxy_recycle_bad_request'];
    }
    $prefix = SN::$db->db_prefix;
    if (db_mysql::$db_in_transaction || SN::$db->transactionCheck()
      || !preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) {
      return $lang['zero_galaxy_recycle_failed'];
    }

    db_mysql::db_transaction_start();
    try {
      $state = SN::$db->doquery("SELECT * FROM `{$prefix}zero_galaxy_state` WHERE id = 1 FOR UPDATE", true);
      $rift = SN::$db->doquery("SELECT * FROM `{$prefix}zero_galaxy_rifts`
        WHERE system = {$system} AND position = {$position} FOR UPDATE", true);
      if (!$state || !$rift || (int) $rift['rift_id'] !== $riftId || empty($rift['planet_id'])
        || !in_array(Lifecycle::phase($rift, SN_TIME_NOW), ['active', 'unstable'], true)) {
        return self::rollback($lang['zero_galaxy_recycle_missing']);
      }
      $sourceId = (int) $user['current_planet'];
      $ownerId = RiftTarget::ownerId($rift, (int) $state['npc_owner_id']);
      $targetId = (int) $rift['planet_id'];
      SN::$gc->db->lockRecords([
        'users' => [(int) $user['id'], $ownerId], 'planets' => [$sourceId, $targetId],
      ]);
      $user = db_user_by_id($user['id'], true);
      $source = DBStaticPlanet::db_planet_by_id($sourceId, true);
      $target = DBStaticPlanet::db_planet_by_id($targetId, true);
      $npc = db_user_by_id($ownerId, true);
      if (!$source || (int) $source['id_owner'] !== (int) $user['id']
        || !RiftTarget::matches($rift, $target, $npc, (int) $state['npc_owner_id'], SN_TIME_NOW)) {
        return self::rollback($lang['zero_galaxy_recycle_missing']);
      }
      $debris = (float) $target['debris_metal'] + (float) $target['debris_crystal'];
      if (!is_finite($debris) || $debris <= 0) {
        return self::rollback($lang['fl_attack_error'][ATTACK_NO_DEBRIS]);
      }

      // Native selection includes fuel in transport capacity and selects only
      // needed ships, up to actual inventory and available deuterium.
      $from = $source;
      $from['galaxy'] = 1;
      $available = [];
      $owned = 0;
      foreach (sn_get_groups('flt_recyclers') as $id) {
        $count = max(0, (int) mrc_get_level($user, $source, $id, false, true));
        $owned += $count;
        $single = flt_travel_data($user, $from, $target, [$id => 1], 10);
        if ($count > 0 && $single['fleet_speed'] > 0 && $single['hold'] > 0 && $single['consumption'] > 0) {
          $available[$id] = $count;
        }
      }
      if (!$available) {
        return self::rollback($lang['fl_attack_error'][$owned ? ATTACK_TOO_FAR : ATTACK_NO_RECYCLERS]);
      }
      $selection = flt_calculate_fleet_to_transport($available, $debris, $from, $target);
      $fleet = array_map('intval', $selection['fleet']);
      if (!$fleet || min($fleet) < 1) {
        return self::rollback($lang['fl_attack_error'][ATTACK_NO_FUEL]);
      }
      $allowed = NativeDispatch::allowed($user, $source, $target, $fleet, MT_RECYCLE, 10);
      if ($allowed !== ATTACK_ALLOWED) {
        return self::rollback($lang['fl_attack_error'][$allowed]);
      }
      $travel = flt_travel_data($user, $source, $target, $fleet, 10, [], \Universe::getBaseGalaxyDistance());
      if ($travel['fleet_speed'] <= 0 || $travel['duration'] < 1
        || $travel['consumption'] > $travel['capacity']
        || mrc_get_level($user, $source, RES_DEUTERIUM) < $travel['consumption']) {
        return self::rollback($lang['gs_c13']);
      }
      NativeDispatch::launch($user, $source, $target, $fleet, MT_RECYCLE, $travel, PT_DEBRIS);
      $remaining = $owned - array_sum($fleet);
      db_mysql::db_transaction_commit();
    } catch (Throwable $error) {
      if (db_mysql::$db_in_transaction) { db_mysql::db_transaction_rollback(); }
      error_log('Zero Galaxy recycling: ' . $error->getMessage());
      return $lang['zero_galaxy_recycle_failed'];
    }
    $planetrow = NativeDispatch::refreshSource($source, (int) $travel['consumption']);
    $ships = [];
    foreach ($fleet as $id => $count) { $ships[] = $count . ' ' . $lang['tech'][$id]; }
    return $lang['gs_sending'] . ' ' . implode(', ', $ships) . ' ' . $lang['gs_to']
      . " 0:{$system}:{$position}|flt_recyclers={$remaining}";
  }

  private static function integer(string $name): ?int {
    $value = isset($_POST[$name]) && is_scalar($_POST[$name]) ? filter_var($_POST[$name], FILTER_VALIDATE_INT) : false;
    return $value === false ? null : $value;
  }

  private static function rollback(string $message): string {
    db_mysql::db_transaction_rollback();
    return $message;
  }
}
