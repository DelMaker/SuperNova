<?php

namespace ZeroGalaxy;

use DBAL\db_mysql;
use InvalidArgumentException;
use Planet\DBStaticPlanet;
use RuntimeException;
use SN;
use Throwable;
use Unit\DBStaticUnit;

class AttackSender {
  /** All client previews are advisory; recheck target, inventory and limit under the lifecycle lock. */
  public static function send(Storage $storage, array $settings, array $requestedRift, array $ships,
    int $speed, int $captainId = 0, int $sourceId = 0, int $groupId = 0): array {
    global $user, $planetrow, $lang;

    require_once SN_ROOT_PHYSICAL . 'includes/includes/flt_functions.php';
    lng_include('fleet');
    if (!SN::$sys_user_logged_in || empty($user['id']) || $speed < 1 || $speed > 10 || $captainId < 0 || $groupId < 0) {
      throw new InvalidArgumentException($lang['zero_galaxy_attack_bad_request']);
    }
    $ships = AttackPolicy::ships($ships);
    $sourceId = $sourceId ?: (int) $user['current_planet'];
    $riftId = (int) ($requestedRift['rift_id'] ?? 0);
    $prefix = SN::$db->db_prefix;
    if ($riftId < 1 || !preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)
      || db_mysql::$db_in_transaction || SN::$db->transactionCheck()) {
      throw new RuntimeException('Zero Galaxy attack cannot start in the current database context');
    }

    db_mysql::db_transaction_start();
    try {
      $state = SN::$db->doquery("SELECT * FROM `{$prefix}zero_galaxy_state` WHERE id = 1 FOR UPDATE", true);
      $rift = SN::$db->doquery("SELECT * FROM `{$prefix}zero_galaxy_rifts` WHERE rift_id = {$riftId} FOR UPDATE", true);
      $limit = $rift ? AttackPolicy::limit($rift) : null;
      if (!$state || !$rift || $limit === null || empty($rift['planet_id'])
        || !in_array(Lifecycle::phase($rift, SN_TIME_NOW), ['active', 'unstable'], true)) {
        throw new InvalidArgumentException($lang['zero_galaxy_attack_missing']);
      }
      $ownerId = RiftTarget::ownerId($rift, (int) $state['npc_owner_id']);
      $targetId = (int) $rift['planet_id'];
      $group = $groupId ? AcsService::group($groupId, (int) $user['id'], $rift, $settings, true) : null;
      $owners = [(int) $user['id'], $ownerId];
      if ($group) { $owners = array_merge($owners, array_map('intval', explode(',', $group['eingeladen']))); }
      SN::$gc->db->lockRecords(['users' => array_values(array_unique($owners)), 'planets' => [$sourceId, $targetId]]);
      $user = db_user_by_id($user['id'], true);
      $source = DBStaticPlanet::db_planet_by_id($sourceId, true);
      $target = DBStaticPlanet::db_planet_by_id($targetId, true);
      $npc = db_user_by_id($ownerId, true);
      if (!$source || (int) $source['id_owner'] !== (int) $user['id']
        || !RiftTarget::matches($rift, $target, $npc, (int) $state['npc_owner_id'], SN_TIME_NOW)) {
        throw new InvalidArgumentException($lang['zero_galaxy_attack_missing']);
      }
      $context = $group ? AcsService::load($groupId, (int) $user['id'], $rift, $settings, true) : null;
      if ($context && count($context['fleets']) >= 5) { throw new InvalidArgumentException($lang['zero_galaxy_acs_full']); }
      $cost = $context ? AcsPolicy::playerCost($context['fleets'], (int) $user['id'], $ships) : AttackPolicy::cost($ships);
      if ($cost > $limit) { throw new InvalidArgumentException(AttackPolicy::OVER_LIMIT); }

      foreach ($ships as $id => $count) {
        if ($count > mrc_get_level($user, $source, $id, true, true)) {
          throw new InvalidArgumentException($lang['fl_attack_error'][ATTACK_NO_SHIPS]);
        }
      }
      $allowed = NativeDispatch::allowed($user, $source, $target, $ships, MT_ATTACK, $speed);
      // NPC fleet budgets replace player point protection. All preceding native
      // availability, inventory, speed, fuel and slot checks remain in force.
      if ($allowed !== ATTACK_ALLOWED && $allowed !== ATTACK_NOOB) {
        throw new InvalidArgumentException($lang['fl_attack_error'][$allowed] ?? $lang['zero_galaxy_attack_failed']);
      }
      $travel = flt_travel_data($user, $source, $target, $ships, $speed, [], \Universe::getBaseGalaxyDistance());
      if ($group && SN_TIME_NOW + $travel['duration'] > (int) $group['ankunft']) {
        throw new InvalidArgumentException($lang['zero_galaxy_acs_too_late']);
      }
      if ($travel['fleet_speed'] <= 0 || $travel['duration'] < 1 || $travel['consumption'] > $travel['capacity']
        || $source['deuterium'] < $travel['consumption']) {
        throw new InvalidArgumentException($lang['fl_attack_error'][ATTACK_NO_FUEL]);
      }
      $captain = null;
      if ($captainId) {
        $module = moduleCaptain();
        $captain = is_object($module) ? $module->unit_captain_get($sourceId, LOC_PLANET, true, false, $user['id']) : null;
        if (!$captain || (int) $captain['unit_id'] !== $captainId || (int) $captain['unit_player_id'] !== (int) $user['id']
          || (int) $captain['unit_location_type'] !== LOC_PLANET || (int) $captain['unit_location_id'] !== $sourceId) {
          throw new InvalidArgumentException($lang['zero_galaxy_attack_captain_missing']);
        }
      }
      $dispatch = NativeDispatch::launch($user, $source, $target, $ships, $group ? MT_AKS : MT_ATTACK, $travel,
        PT_PLANET, $groupId, $group ? (int) $group['ankunft'] : 0);
      if ($captain && !DBStaticUnit::db_unit_set_by_id($captainId,
        'unit_location_type = ' . LOC_FLEET . ', unit_location_id = ' . $dispatch['fleet_id'])) {
        throw new RuntimeException('Zero Galaxy captain was not assigned to the fleet');
      }
      db_mysql::db_transaction_commit();
    } catch (Throwable $error) {
      if (db_mysql::$db_in_transaction) { db_mysql::db_transaction_rollback(); }
      throw $error;
    }
    $planetrow = NativeDispatch::refreshSource($source, (int) $travel['consumption']);
    return $dispatch + ['fleet' => $ships, 'travel' => $travel, 'source' => $source, 'target' => $target];
  }
}
