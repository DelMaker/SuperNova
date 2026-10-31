<?php

namespace ZeroGalaxy;

use Fleet\DbFleetStatic;
use Fleet\FleetDispatchEvent;
use SN;

class RiftAttackEvent extends RiftMissionEvent {
  private $requestedMission;
  private $acsEnabled;
  private $lockedGroupId = 0;
  private $lockedGroup;
  private $groupFleets = [];

  public function __construct(FleetDispatchEvent $event, int $legacyOwnerId, bool $metadataAvailable = true,
    bool $acsEnabled = false) {
    parent::__construct($event, $legacyOwnerId, $metadataAvailable);
    $this->acsEnabled = $acsEnabled;
    $this->requestedMission = (int) $event->missionId;
    if ($this->requestedMission !== MT_ATTACK && !($acsEnabled && $this->requestedMission === MT_AKS)) {
      // Native HOLD/EXPLORE arrivals are skipped before refreshMissionData.
      // Use a local no-op ID so this event can return their forbidden fleets.
      // The database fleet_mission value is never changed here.
      $this->missionId = MT_NONE;
    }
  }

  protected function refreshAdditionalLocks(): void {
    $groupId = (int) ($this->fleet['fleet_group'] ?? 0);
    if (!$groupId || $this->event !== EVENT_FLT_ARRIVE) { return; }
    $prefix = SN::$db->db_prefix;
    $this->lockedGroupId = $groupId;
    $this->lockedGroup = $this->query("SELECT * FROM `{$prefix}aks` WHERE id={$groupId} FOR UPDATE")->fetch_assoc();
    $owners = $this->userIdsToLock;
    foreach (explode(',', (string) ($this->lockedGroup['eingeladen'] ?? '')) as $id) {
      if (ctype_digit($id) && (int) $id > 0) { $owners[] = (int) $id; }
    }
    $owners = array_values(array_unique($owners)); sort($owners, SORT_NUMERIC);
    // SERIALIZABLE reads lock fleet rows too: lock invited owners and known
    // planets first, so a concurrent manual recall keeps the native lock order.
    SN::$gc->db->lockRecords(['users' => $owners, 'planets' => $this->planetIdsToLock]);
    $this->groupFleets = $this->query("SELECT * FROM `{$prefix}fleets` WHERE fleet_group={$groupId}
      ORDER BY fleet_id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
    foreach ($this->groupFleets as $member) {
      $owner = (int) $member['fleet_owner'];
      if (!in_array($owner, $owners, true)) {
        // A legitimate late join is invited before it can launch. Retry a
        // corrupted/new discovery snapshot instead of reversing owner locks.
        throw new \RuntimeException('Zero Galaxy ACS membership changed before record locking');
      }
      $this->fleetIdsToLock[] = (int) $member['fleet_id'];
    }
    $this->userIdsToLock = $owners;
    $this->fleetIdsToLock = array_values(array_unique($this->fleetIdsToLock));
  }

  public function refreshMissionData() {
    if ($this->processed) {
      return;
    }
    if ((int) $this->fleet['fleet_mess'] !== FLEET_STATUS_FLYING) {
      $this->finish();
      return;
    }
    parent::refreshMissionData();

    $groupId = (int) ($this->fleet['fleet_group'] ?? 0);
    if ($groupId) {
      if (!$this->moduleRecordsLocked) { $this->returnWithoutCombat(); return; }
      if ($this->acsEnabled && $groupId !== $this->lockedGroupId) {
        throw new \RuntimeException('Zero Galaxy ACS changed after event discovery; retry dispatch');
      }
      if (!$this->prepareGroup()) { $this->finish(); return; }
    } elseif ($this->requestedMission !== MT_ATTACK || $this->event !== EVENT_FLT_ARRIVE
      || (int) $this->fleet['fleet_end_type'] !== PT_PLANET || !$this->isModuleTarget()) {
      $this->returnWithoutCombat();
      return;
    }

    try {
      $limit = AttackPolicy::limit($this->lockedRift);
      $cost = $groupId ? AcsPolicy::playerCost($this->groupFleets, (int) $this->fleet['fleet_owner'])
        : AttackPolicy::cost(sys_unit_str2arr($this->fleet['fleet_array']));
    } catch (\Throwable $error) {
      error_log('Zero Galaxy attack eligibility: ' . $error->getMessage());
      $this->returnWithoutCombat();
      return;
    }
    if ($limit === null) {
      $this->returnWithoutCombat();
      return;
    }
    if ($cost > $limit) {
      $this->returnWithoutCombat(true);
      return;
    }

    if (!function_exists('flt_mission_attack')) {
      require_once SN_ROOT_PHYSICAL . 'includes/includes/flt_mission_attack.php';
    }
    global $functions;
    $hadCaptureHook = array_key_exists('flt_planet_capture', $functions);
    $captureHook = $functions['flt_planet_capture'] ?? null;
    $functions['flt_planet_capture'] = [[RiftBattleRules::class, 'beforeApply']];
    try {
      // The native group list still supplies every attacker. Treat a rift ACS
      // as an attack for the existing captain/raid XP rules, without rewriting
      // the stored mission or its native return handling.
      $this->missionId = MT_ATTACK;
      // Native prepare/calculate/report/apply preserves partial defense
      // restoration, loot, fleet returns and the captain module's hooks.
      $combat = flt_mission_attack($this);
    } finally {
      if ($hadCaptureHook) {
        $functions['flt_planet_capture'] = $captureHook;
      } else {
        unset($functions['flt_planet_capture']);
      }
    }

    if (is_array($combat) && ($combat[UBE_OUTCOME][UBE_COMBAT_RESULT] ?? null) === UBE_COMBAT_RESULT_WIN) {
      $this->markUnstable();
    }
    $this->finish();
  }

  private function prepareGroup(): bool {
    $groupId = (int) $this->fleet['fleet_group'];
    $group = $this->lockedGroup;
    if (!$group || (int) ($group['galaxy'] ?? -1) !== 0) {
      $this->detach($this->fleet, true);
      return false;
    }
    $limit = $this->lockedRift ? AttackPolicy::limit($this->lockedRift) : null;
    $allowed = $this->acsEnabled && $this->event === EVENT_FLT_ARRIVE && $limit !== null
      && $this->isModuleTarget() && (int) $group['system'] === (int) $this->lockedRift['system']
      && (int) $group['planet'] === (int) $this->lockedRift['position']
      && (int) $group['planet_type'] === PT_PLANET;
    $eligible = [];
    $invited = array_map('intval', explode(',', (string) $group['eingeladen']));
    $allowed = $allowed && count(array_unique($invited)) <= 5;
    foreach ($this->groupFleets as $member) {
      if (!$allowed || !in_array((int) $member['fleet_owner'], $invited, true)
        || !AcsPolicy::validMember($member, $this->lockedRift ?? [], $groupId, (int) $group['ankunft'])) {
        $this->detach($member, (int) $member['fleet_end_galaxy'] === 0);
      } else { $eligible[] = $member; }
    }
    if (count($eligible) > 5) {
      foreach ($eligible as $member) { $this->detach($member, true); }
      $eligible = [];
    }
    $owners = array_unique(array_map('intval', array_column($eligible, 'fleet_owner')));
    $rejected = [];
    foreach ($owners as $owner) {
      try { $over = AcsPolicy::playerCost($eligible, $owner) > $limit; }
      catch (\Throwable $error) { $over = true; }
      if ($over) { $rejected[$owner] = true; }
    }
    $this->groupFleets = [];
    foreach ($eligible as $member) {
      if (isset($rejected[(int) $member['fleet_owner']])) {
        $this->detach($member, true);
        msg_send_simple_message($member['fleet_owner'], '', SN_TIME_NOW, MSG_TYPE_COMBAT,
          SN::$lang['sys_mess_qg'] ?? 'Штаб флота', SN::$lang['zero_galaxy_title'] ?? 'Нулевая галактика',
          AttackPolicy::OVER_LIMIT, STRING_NEED_ESCAPING, true);
      } else { $this->groupFleets[] = $member; }
    }
    if (!$this->groupFleets) {
      $prefix = SN::$db->db_prefix;
      $this->query("DELETE FROM `{$prefix}aks` WHERE id={$groupId} AND galaxy=0");
    }
    return in_array((int) $this->fleet['fleet_id'], array_map('intval', array_column($this->groupFleets, 'fleet_id')), true);
  }

  private function detach(array $member, bool $sendBack): void {
    $prefix = SN::$db->db_prefix;
    $id = (int) $member['fleet_id'];
    if ($sendBack && (int) $member['fleet_mess'] === FLEET_STATUS_FLYING) { DbFleetStatic::fleet_send_back($member); }
    // Native recall retains fleet_group; remove it even for a returning fleet
    // so flt_mission_attack cannot include that fleet in its unfiltered list.
    $this->query("UPDATE `{$prefix}fleets` SET fleet_group=0 WHERE fleet_id={$id}");
  }

  private function returnWithoutCombat(bool $overLimit = false): void {
    DbFleetStatic::fleet_send_back($this->fleet);
    if ($overLimit) {
      msg_send_simple_message($this->fleet['fleet_owner'], '', SN_TIME_NOW, MSG_TYPE_COMBAT,
        SN::$lang['sys_mess_qg'] ?? 'Штаб флота', SN::$lang['zero_galaxy_title'] ?? 'Нулевая галактика',
        AttackPolicy::OVER_LIMIT, STRING_NEED_ESCAPING, true);
    }
    $this->finish();
  }

  private function markUnstable(): void {
    $prefix = SN::$db->db_prefix;
    $riftId = (int) $this->lockedRift['rift_id'];
    $planetId = (int) $this->lockedRift['planet_id'];
    $ownerId = $this->targetOwnerId();
    $now = (int) SN_TIME_NOW;
    $this->query("UPDATE `{$prefix}zero_galaxy_rifts` SET unstable_at = LEAST(unstable_at, {$now})
      WHERE rift_id = {$riftId} AND planet_id = {$planetId}");
    $this->query("UPDATE `{$prefix}zero_galaxy_state` SET next_tick_at = LEAST(next_tick_at, {$now}) WHERE id = 1");
    $name = SN::$db->link->real_escape_string(RiftName::forRift($this->lockedRift, 'unstable'));
    $this->query("UPDATE `{$prefix}planets` SET name = '{$name}' WHERE id = {$planetId} AND id_owner = {$ownerId} AND galaxy = 0");
  }

}
