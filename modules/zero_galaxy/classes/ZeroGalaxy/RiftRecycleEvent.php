<?php

namespace ZeroGalaxy;

use Fleet\DbFleetStatic;

class RiftRecycleEvent extends RiftMissionEvent {
  public function refreshMissionData() {
    if ($this->processed) {
      return;
    }
    if ((int) $this->fleet['fleet_mess'] !== FLEET_STATUS_FLYING) {
      $this->finish();
      return;
    }
    parent::refreshMissionData();

    if ($this->missionId !== MT_RECYCLE || $this->event !== EVENT_FLT_ARRIVE
      || !empty($this->fleet['fleet_group']) || (int) $this->fleet['fleet_end_type'] !== PT_DEBRIS
      || !$this->isModuleTarget() || !$this->hasRecyclingCapacity()) {
      DbFleetStatic::fleet_send_back($this->fleet);
      $this->finish();
      return;
    }

    require_once SN_ROOT_PHYSICAL . 'includes/includes/flt_mission_recycle.php';
    // The locked native planet stores its debris. Closure cannot delete it
    // between this phase check and collection; the native return carries loot.
    flt_mission_recycle($this);
    $this->finish();
  }

  private function hasRecyclingCapacity(): bool {
    $recycling = $other = 0;
    $ships = sn_get_groups('fleet');
    $recyclers = sn_get_groups('flt_recyclers');
    foreach (sys_unit_str2arr($this->fleet['fleet_array']) as $id => $count) {
      if ($count < 0) { return false; }
      if (!in_array($id, $ships)) { continue; }
      $capacity = get_unit_param($id, P_CAPACITY) * $count;
      if (in_array($id, $recyclers)) { $recycling += $capacity; }
      else { $other += $capacity; }
    }
    $goods = [$this->fleet['fleet_resource_metal'], $this->fleet['fleet_resource_crystal'],
      $this->fleet['fleet_resource_deuterium']];
    // Native collection assumes enough free cargo space. Reject corrupted or
    // overloaded legacy fleets instead of letting negative capacity add debris.
    return $recycling > 0 && min($goods) >= 0 && array_sum($goods) <= $recycling + $other
      && $this->dstPlanetRow['debris_metal'] >= 0 && $this->dstPlanetRow['debris_crystal'] >= 0;
  }
}
