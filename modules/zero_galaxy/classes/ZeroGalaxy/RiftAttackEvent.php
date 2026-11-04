<?php

namespace ZeroGalaxy;

use Fleet\DbFleetStatic;
use Fleet\FleetDispatchEvent;
use SN;

class RiftAttackEvent extends RiftMissionEvent {
  public function __construct(FleetDispatchEvent $event, bool $metadataAvailable = true) {
    parent::__construct($event, $metadataAvailable);
    if ($this->missionId !== MT_ATTACK || !empty($this->fleet['fleet_group'])) {
      // Native HOLD/EXPLORE skip refresh; use a no-op to return forbidden missions.
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

    if ($this->missionId !== MT_ATTACK || !empty($this->fleet['fleet_group'])
      || $this->event !== EVENT_FLT_ARRIVE || (int) $this->fleet['fleet_end_type'] !== PT_PLANET
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
    if (!empty($this->fleet['fleet_group'])) {
      $sql = Sql::fromGame();
      $sql->query('UPDATE ' . $sql->table('fleets') . ' SET fleet_group = 0 WHERE fleet_id = '
        . (int) $this->fleet['fleet_id'] . ' AND fleet_end_galaxy = 0');
    }
    if ($overLimit) {
      msg_send_simple_message($this->fleet['fleet_owner'], '', SN_TIME_NOW, MSG_TYPE_COMBAT,
        SN::$lang['sys_mess_qg'], SN::$lang['zero_galaxy_title'], SN::$lang[AttackPolicy::OVER_LIMIT],
        STRING_NEED_ESCAPING, true);
    }
    $this->finish();
  }

  private function markUnstable(): void {
    $sql = Sql::fromGame();
    $riftId = (int) $this->lockedRift['rift_id'];
    $planetId = (int) $this->lockedRift['planet_id'];
    $ownerId = $this->targetOwnerId();
    $now = (int) SN_TIME_NOW;
    $sql->query('UPDATE ' . $sql->table('zero_galaxy_rifts') . " SET unstable_at = LEAST(unstable_at, {$now})
      WHERE rift_id = {$riftId} AND planet_id = {$planetId}");
    $sql->query('UPDATE ' . $sql->table('zero_galaxy_state') . " SET next_tick_at = LEAST(next_tick_at, {$now}) WHERE id = 1");
    $name = $sql->escape(RiftName::forRift($this->lockedRift, 'unstable'));
    $sql->query('UPDATE ' . $sql->table('planets') . " SET name = '{$name}'
      WHERE id = {$planetId} AND id_owner = {$ownerId} AND galaxy = 0");
  }
}
