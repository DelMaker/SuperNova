<?php

namespace ZeroGalaxy;

class RiftSpyEvent extends RiftMissionEvent {
  public function refreshMissionData() {
    if ($this->processed) {
      return;
    }
    // Identity and phase use the full rift snapshot locked before native rows;
    // no second membership query can mask a lost transaction or reverse locks.
    parent::refreshMissionData();
    if (!$this->isModuleTarget()) {
      return;
    }

    require_once SN_ROOT_PHYSICAL . 'includes/includes/coe_simulator_helpers.php';
    $mission = RiftEspionage::buildFromArray($this);
    $mission->flt_mission_spy();
    $this->finish();
  }
}
