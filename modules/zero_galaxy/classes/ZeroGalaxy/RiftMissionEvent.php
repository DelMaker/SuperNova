<?php

namespace ZeroGalaxy;

use Fleet\FleetDispatchEvent;
use SN;

/**
 * Zero-galaxy arrival with the shared lifecycle locks and current target identity.
 * Reconnaissance and recycling then run in the native handlers unchanged.
 */
class RiftMissionEvent extends FleetDispatchEvent {
  protected $processed = false;
  protected $moduleRecordsLocked = false;
  protected $lockedRift;
  private $metadataAvailable;

  public function __construct(FleetDispatchEvent $event, bool $metadataAvailable = true) {
    // Keep native locks, ordering and snapshots without repeating its lookups.
    foreach (get_object_vars($event) as $name => $value) {
      $this->$name = $value;
    }
    $this->metadataAvailable = $metadataAvailable;
  }

  public function lockEventRecords() {
    $this->moduleRecordsLocked = $this->lockModuleRecords();
    if ($this->moduleRecordsLocked) {
      // A tick can replace the planet between event discovery and processing.
      // Add current IDs before native owner -> planet -> fleet locking.
      if (!empty($this->lockedRift['planet_id'])) {
        $this->planetIdsToLock[] = (int) $this->lockedRift['planet_id'];
      }
      if ($ownerId = $this->targetOwnerId()) {
        $this->userIdsToLock[] = $ownerId;
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

  public function refreshMissionData() {
    if ($this->isModuleTarget()) {
      // The native refresh recomputes basic income even for planets without mines.
      $sql = Sql::fromGame();
      $sql->query('UPDATE ' . $sql->table('planets') . ' SET last_update = GREATEST(last_update, '
        . (int) $this->eventTimeStamp . ') WHERE id = ' . (int) $this->dstPlanetId);
    }
    parent::refreshMissionData();
  }

  private function lockModuleRecords(): bool {
    if (!$this->metadataAvailable || !Sql::validPrefix(SN::$db->db_prefix)) {
      return false;
    }
    $sql = Sql::fromGame();
    // SERIALIZABLE turns even plain SELECTs into locking reads. The shared
    // lifecycle mutex must precede every native owner/planet/fleet lock.
    if (!$sql->one('SELECT id FROM ' . $sql->table('zero_galaxy_state') . ' WHERE id = 1 FOR UPDATE')) {
      return false;
    }
    $this->lockedRift = $sql->one('SELECT * FROM ' . $sql->table('zero_galaxy_rifts')
      . ' WHERE system = ' . (int) $this->fleet['fleet_end_system']
      . ' AND position = ' . (int) $this->fleet['fleet_end_planet'] . ' FOR UPDATE');
    return true;
  }

  protected function targetOwnerId(): int {
    return (int) ($this->lockedRift['npc_owner_id'] ?? 0);
  }

  protected function isModuleTarget(): bool {
    if (!$this->moduleRecordsLocked || !$this->lockedRift) {
      return false;
    }
    $ownerId = $this->targetOwnerId();
    $owner = $ownerId ? db_user_by_id($ownerId) : null;
    return RiftTarget::matches($this->lockedRift, $this->dstPlanetRow, $owner, SN_TIME_NOW);
  }

  protected function finish(): void {
    $this->processed = true;
    // Only suppress this already handled event's native switch. The stored
    // fleet_mission and independent native return events stay unchanged.
    $this->missionId = MT_NONE;
  }
}
