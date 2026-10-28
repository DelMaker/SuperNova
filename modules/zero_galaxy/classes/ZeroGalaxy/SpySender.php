<?php

namespace ZeroGalaxy;

use DBAL\db_mysql;
use DBAL\OldDbChangeSet;
use Fleet\DbFleetStatic;
use Planet\DBStaticPlanet;
use RuntimeException;
use SN;
use Throwable;

class SpySender {
  /**
   * The caller reconciles lifecycle changes before entering this method.
   * A normal MT_SPY fleet is dispatched by the existing game worker afterwards.
   */
  public static function send(Storage $storage, array $settings): string {
    global $user, $planetrow, $lang;

    lng_include('universe');
    lng_include('fleet');
    require_once SN_ROOT_PHYSICAL . 'includes/includes/flt_functions.php';

    if (!SN::$sys_user_logged_in || empty($user['id'])
      || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
      || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
      return self::message('zero_galaxy_spy_bad_request', $lang['gs_c00']);
    }

    $system = self::integer('system');
    $position = self::integer('planet');
    $riftId = array_key_exists('rift_id', $_POST) ? self::integer('rift_id') : null;
    if (self::integer('galaxy') !== 0 || self::integer('mission') !== MT_SPY
      || self::integer('planet_type') !== PT_PLANET
      || $system === null || $system < 1 || $system > (int) SN::$config->game_maxSystem
      || $position === null || $position < 1 || $position > (int) SN::$config->game_maxPlanet
      || (array_key_exists('rift_id', $_POST) && ($riftId === null || $riftId < 1))) {
      return self::message('zero_galaxy_spy_bad_request', $lang['gs_c02']);
    }

    $requested = array_key_exists('shipcount', $_POST)
      ? self::integer('shipcount')
      : abs((int) SN::$user_options[PLAYER_OPTION_FLEET_SPY_DEFAULT]);
    if ($requested === null || $requested < 1) {
      return $lang['fl_attack_error'][ATTACK_NO_SPIES];
    }

    if (db_mysql::$db_in_transaction || SN::$db->transactionCheck()) {
      return self::message('zero_galaxy_spy_failed', $lang['gs_c00']);
    }

    $prefix = SN::$db->db_prefix;
    if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) {
      return self::message('zero_galaxy_spy_failed', $lang['gs_c00']);
    }

    db_mysql::db_transaction_start();
    try {
      // Same first lock as Storage::tick(): a rift cannot close while we launch.
      $state = SN::$db->doquery("SELECT * FROM `{$prefix}zero_galaxy_state` WHERE id = 1 FOR UPDATE", true);
      $rift = SN::$db->doquery("SELECT * FROM `{$prefix}zero_galaxy_rifts`
        WHERE system = {$system} AND position = {$position} FOR UPDATE", true);
      if (!$state || empty($state['npc_owner_id']) || !$rift || empty($rift['planet_id'])
        || !in_array(Lifecycle::phase($rift, SN_TIME_NOW), ['active', 'unstable'], true)
        || ($riftId !== null && $riftId !== (int) $rift['rift_id'])) {
        return self::rollbackMissing();
      }

      $sourceId = (int) $user['current_planet'];
      $targetId = (int) $rift['planet_id'];
      $ownerId = (int) $state['npc_owner_id'];
      SN::$gc->db->lockRecords([
        'users' => [(int) $user['id'], $ownerId],
        'planets' => [$sourceId, $targetId],
      ]);

      $user = db_user_by_id($user['id'], true);
      $source = DBStaticPlanet::db_planet_by_id($sourceId, true);
      $target = DBStaticPlanet::db_planet_by_id($targetId, true);
      if (!$source || (int) $source['id_owner'] !== (int) $user['id']
        || !$target || (int) $target['id_owner'] !== $ownerId
        || (int) $target['galaxy'] !== 0 || (int) $target['system'] !== $system
        || (int) $target['planet'] !== $position || (int) $target['planet_type'] !== PT_PLANET) {
        return self::rollbackMissing();
      }

      $owned = max(0, (int) mrc_get_level($user, $source, SHIP_SPY, false, true));
      $probes = min($owned, $requested);
      if ($probes < 1) {
        db_mysql::db_transaction_rollback();
        return $lang['fl_attack_error'][ATTACK_NO_SPIES];
      }

      $fleet = [SHIP_SPY => $probes];
      // The native validator calculates fuel internally. This local coordinate
      // copy makes that check use one intergalactic distance from every source.
      // The actual source coordinates below are never changed.
      $validationSource = $source;
      $validationSource['galaxy'] = 1;
      $options = [
        P_FLEET_ATTACK_SPEED_PERCENT_TENTH => 10,
        P_FLEET_ATTACK_RES_LIST => [],
        P_FLEET_ATTACK_RESOURCES_SUM => 0,
        P_FLEET_ATTACK_FLEET_GROUP => 0,
        P_FLEET_ATTACK_FLYING_COUNT => DbFleetStatic::fleet_count_flying($user['id']),
        P_FLEET_ATTACK_TARGET_STRUCTURE => 0,
        P_FLEET_ATTACK_STAY_TIME => 0,
      ];
      $allowed = flt_can_attack($validationSource, $target, $fleet + [RES_DEUTERIUM => 0], MT_SPY, $options);
      // NPC reconnaissance is independent of player point protection. All
      // native vacation, availability, fuel, cargo and fleet-slot checks run
      // before this result; the target's module ownership is already verified.
      if ($allowed !== ATTACK_ALLOWED && $allowed !== ATTACK_NOOB) {
        db_mysql::db_transaction_rollback();
        return $lang['fl_attack_error'][$allowed];
      }

      $travel = flt_travel_data($user, $source, $target, $fleet, 10, [], \Universe::getBaseGalaxyDistance());
      if ($travel['fleet_speed'] <= 0 || $travel['duration'] < 1
        || $travel['consumption'] > $travel['capacity']
        || mrc_get_level($user, $source, RES_DEUTERIUM) < $travel['consumption']) {
        db_mysql::db_transaction_rollback();
        return $lang['gs_c13'];
      }

      $arrival = SN_TIME_NOW + (int) $travel['duration'];
      $fleetId = DbFleetStatic::fleet_insert_set_dbq([
        'fleet_owner' => (int) $user['id'],
        'fleet_mission' => MT_SPY,
        'fleet_amount' => $probes,
        'fleet_array' => SHIP_SPY . ',' . $probes,
        'fleet_start_time' => $arrival,
        'fleet_start_planet_id' => $sourceId,
        'fleet_start_galaxy' => (int) $source['galaxy'],
        'fleet_start_system' => (int) $source['system'],
        'fleet_start_planet' => (int) $source['planet'],
        'fleet_start_type' => (int) $source['planet_type'],
        'fleet_end_time' => $arrival + (int) $travel['duration'],
        'fleet_end_planet_id' => $targetId,
        'fleet_end_galaxy' => 0,
        'fleet_end_system' => $system,
        'fleet_end_planet' => $position,
        'fleet_end_type' => PT_PLANET,
        'fleet_target_owner' => $ownerId,
        'start_time' => SN_TIME_NOW,
      ]);
      if (!$fleetId) {
        throw new RuntimeException('Zero Galaxy reconnaissance fleet was not created');
      }

      $change = OldDbChangeSet::db_changeset_prepare_unit(SHIP_SPY, -$probes, $user, $source);
      $fuel = (int) $travel['consumption'];
      if (!DBStaticPlanet::db_planet_set_by_id($sourceId, "`deuterium` = `deuterium` - {$fuel}")
        || !OldDbChangeSet::db_changeset_apply(['unit' => [$change]])) {
        throw new RuntimeException('Zero Galaxy reconnaissance resources were not deducted');
      }
      db_mysql::db_transaction_commit();
      $planetrow = DBStaticPlanet::db_planet_by_id($sourceId);
      $remaining = max(0, (int) mrc_get_level($user, $planetrow, SHIP_SPY, false, true));

      return $lang['gs_sending'] . ' ' . $probes . ' ' . $lang['tech'][SHIP_SPY] . ' '
        . $lang['gs_to'] . " 0:{$system}:{$position}|flt_spies={$remaining}";
    } catch (Throwable $error) {
      if (db_mysql::$db_in_transaction) {
        db_mysql::db_transaction_rollback();
      }
      error_log('Zero Galaxy spy: ' . $error->getMessage());

      return self::message('zero_galaxy_spy_failed', $lang['gs_c00']);
    }
  }

  private static function integer($name): ?int {
    if (!array_key_exists($name, $_POST) || !is_scalar($_POST[$name])) {
      return null;
    }
    $value = filter_var($_POST[$name], FILTER_VALIDATE_INT);

    return $value === false ? null : $value;
  }

  private static function rollbackMissing(): string {
    global $lang;

    db_mysql::db_transaction_rollback();

    return self::message('zero_galaxy_spy_missing', $lang['gs_c02']);
  }

  private static function message($key, $fallback): string {
    global $lang;

    return !empty($lang[$key]) ? $lang[$key] : $fallback;
  }
}
