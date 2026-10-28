<?php

namespace ZeroGalaxy;

use Fleet\FleetDispatchEvent;
use SN;

class RiftSpyEvent extends FleetDispatchEvent {
  private $legacyOwnerId;
  private $processed = false;
  private $moduleRecordsLocked = false;
  private $lockedRift;

  public function __construct(FleetDispatchEvent $event, int $legacyOwnerId) {
    // Preserve the native event's locks, snapshots and ordering, without doing
    // its planet lookups a second time or changing the stored fleet mission.
    foreach (get_object_vars($event) as $name => $value) {
      $this->$name = $value;
    }
    $this->legacyOwnerId = $legacyOwnerId;
  }

  public function lockEventRecords() {
    $this->moduleRecordsLocked = $this->lockModuleRecords();
    if ($this->moduleRecordsLocked) {
      // A lifecycle tick may have replaced the target after getFleetEvents().
      // Add its current IDs from locked module metadata without taking a
      // planet lock before the native owner -> planet lock order.
      if (!empty($this->lockedRift['planet_id'])) {
        $this->planetIdsToLock[] = (int) $this->lockedRift['planet_id'];
      }
      $ownerId = $this->lockedRift['npc_owner_id'] ?? $this->legacyOwnerId;
      if ($ownerId) {
        $this->userIdsToLock[] = (int) $ownerId;
      }
      $this->planetIdsToLock = array_values(array_unique($this->planetIdsToLock));
      $this->userIdsToLock = array_values(array_unique($this->userIdsToLock));
    }

    $result = parent::lockEventRecords();
    if ($this->moduleRecordsLocked) {
      $this->getDstPlanetRowFromFleet();
    }
    return $result;
  }

  private function lockModuleRecords(): bool {
    $prefix = SN::$db->db_prefix;
    if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) {
      return false;
    }
    try {
      // The game uses SERIALIZABLE: even the later plain membership SELECT can
      // acquire row locks. Take the lifecycle mutex before native owner locks.
      $state = SN::$db->link->query("SELECT id FROM `{$prefix}zero_galaxy_state` WHERE id = 1 FOR UPDATE");
      if (!$state) {
        throw new \RuntimeException('Zero Galaxy state lock failed: ' . SN::$db->link->error, SN::$db->link->errno);
      }
      if (!$state->num_rows) {
        return false;
      }
      $system = (int) $this->fleet['fleet_end_system'];
      $position = (int) $this->fleet['fleet_end_planet'];
      $rift = SN::$db->link->query("SELECT rift_id, planet_id, npc_owner_id FROM `{$prefix}zero_galaxy_rifts`
        WHERE system = {$system} AND position = {$position} FOR UPDATE");
      if (!$rift) {
        throw new \RuntimeException('Zero Galaxy rift lock failed: ' . SN::$db->link->error, SN::$db->link->errno);
      }
      $this->lockedRift = $rift->fetch_assoc();
      return true;
    } catch (\Throwable $error) {
      error_log('Zero Galaxy spy locks: ' . $error->getMessage());
      // A deadlock can roll back the entire native event transaction. Never
      // swallow a locking error and continue processing with stale DBAL flags.
      throw $error;
    }
  }

  public function refreshMissionData() {
    if ($this->processed) {
      return;
    }
    // The native dispatcher already holds the event locks and transaction here.
    parent::refreshMissionData();
    if (!$this->isModuleTarget()) {
      return;
    }

    require_once SN_ROOT_PHYSICAL . 'includes/includes/coe_simulator_helpers.php';
    $mission = RiftEspionage::buildFromArray($this);
    $mission->flt_mission_spy();
    $this->processed = true;
    // Skip only the native switch for this already processed event. Neither
    // fleet_mission in the database nor ordinary/return events are modified.
    $this->missionId = MT_NONE;
  }

  private function isModuleTarget(): bool {
    if (!$this->moduleRecordsLocked) {
      return false;
    }
    $planet = $this->dstPlanetRow;
    // FleetDispatchEvent does not cache the owner's row; retrieve the current
    // owner after refresh, while native record locks are held.
    $ownerId = (int) ($planet['id_owner'] ?? 0);
    $owner = $ownerId ? db_user_by_id($ownerId) : null;
    if (!$planet || !$owner || (int) $planet['galaxy'] !== 0 || (int) $planet['planet_type'] !== PT_PLANET
      || (int) $owner['user_bot'] !== Storage::NPC_BOT_TYPE || (int) $owner['authlevel'] !== 0
      || (int) $owner['parent_account_id'] !== 0 || (int) $owner['parent_account_global'] !== 0) {
      return false;
    }

    $prefix = SN::$db->db_prefix;
    if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) {
      return false;
    }
    $planetId = (int) $planet['id'];
    $system = (int) $planet['system'];
    $position = (int) $planet['planet'];
    $legacy = $ownerId === $this->legacyOwnerId ? 'OR npc_owner_id IS NULL' : '';
    // State and rift were locked before the native owner/planet locks. The
    // legacy owner ID was read before the event loop, so no lock inversion is
    // introduced by the fallback for older shared-owner rifts.
    try {
      $result = SN::$db->link->query("SELECT rift_id FROM `{$prefix}zero_galaxy_rifts`
        WHERE planet_id = {$planetId} AND system = {$system} AND position = {$position}
          AND (npc_owner_id = {$ownerId} {$legacy})
          AND opens_at <= " . SN_TIME_NOW . ' AND closes_at > ' . SN_TIME_NOW . ' LIMIT 1');
      if (!$result) {
        error_log('Zero Galaxy spy: rift metadata unavailable; native espionage retained');
        return false;
      }
      $rift = $result->fetch_assoc();
    } catch (\Throwable $error) {
      error_log('Zero Galaxy spy metadata: ' . $error->getMessage());
      return false;
    }

    return !empty($rift['rift_id']);
  }
}
