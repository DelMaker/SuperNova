<?php

namespace ZeroGalaxy;

use Fleet\DbFleetStatic;
use Fleet\FleetDispatchEvent;
use SN;

class RiftAttackEvent extends RiftMissionEvent {
  private $requestedMission;

  public function __construct(FleetDispatchEvent $event, int $legacyOwnerId, bool $metadataAvailable = true) {
    parent::__construct($event, $legacyOwnerId, $metadataAvailable);
    $this->requestedMission = (int) $event->missionId;
    if ($this->requestedMission !== MT_ATTACK) {
      // Native HOLD/EXPLORE arrivals are skipped before refreshMissionData.
      // Use a local no-op ID so this event can return their forbidden fleets.
      // The database fleet_mission value is never changed here.
      $this->missionId = MT_NONE;
    }
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

    if ($this->requestedMission !== MT_ATTACK || $this->event !== EVENT_FLT_ARRIVE
      || !empty($this->fleet['fleet_group']) || (int) $this->fleet['fleet_end_type'] !== PT_PLANET
      || !$this->isModuleTarget()) {
      $this->returnWithoutCombat();
      return;
    }

    try {
      $limit = AttackPolicy::limit($this->lockedRift);
      $cost = AttackPolicy::cost(sys_unit_str2arr($this->fleet['fleet_array']));
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
