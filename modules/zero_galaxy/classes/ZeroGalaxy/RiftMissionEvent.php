<?php

namespace ZeroGalaxy;

use Fleet\FleetDispatchEvent;
use RuntimeException;
use SN;

/** Shared lifecycle locks and target identity for zero-galaxy arrivals. */
abstract class RiftMissionEvent extends FleetDispatchEvent {
  protected $legacyOwnerId;
  protected $processed = false;
  protected $moduleRecordsLocked = false;
  protected $lockedRift;
  private $metadataAvailable;

  public function __construct(FleetDispatchEvent $event, int $legacyOwnerId, bool $metadataAvailable = true) {
    // Keep native locks, ordering and snapshots without repeating its lookups.
    foreach (get_object_vars($event) as $name => $value) {
      $this->$name = $value;
    }
    $this->legacyOwnerId = $legacyOwnerId;
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

  private function lockModuleRecords(): bool {
    $prefix = SN::$db->db_prefix;
    if (!$this->metadataAvailable || !preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) {
      return false;
    }
    // SERIALIZABLE turns even plain SELECTs into locking reads. The shared
    // lifecycle mutex must precede every native owner/planet/fleet lock.
    $state = $this->query("SELECT id FROM `{$prefix}zero_galaxy_state` WHERE id = 1 FOR UPDATE");
    if (!$state->num_rows) {
      return false;
    }
    $system = (int) $this->fleet['fleet_end_system'];
    $position = (int) $this->fleet['fleet_end_planet'];
    $this->lockedRift = $this->query("SELECT * FROM `{$prefix}zero_galaxy_rifts`
      WHERE system = {$system} AND position = {$position} FOR UPDATE")->fetch_assoc();
    return true;
  }

  protected function query(string $sql) {
    $result = SN::$db->link->query($sql);
    if ($result === false) {
      // A deadlock can roll back the transaction behind native DBAL flags.
      // Never continue processing or fall back after losing the record locks.
      throw new RuntimeException('Zero Galaxy mission database error: ' . SN::$db->link->error, SN::$db->link->errno);
    }
    return $result;
  }

  protected function targetOwnerId(): int {
    return RiftTarget::ownerId($this->lockedRift ?? [], $this->legacyOwnerId);
  }

  protected function isModuleTarget(): bool {
    if (!$this->moduleRecordsLocked || !$this->lockedRift) {
      return false;
    }
    $ownerId = $this->targetOwnerId();
    $owner = $ownerId ? db_user_by_id($ownerId) : null;
    return RiftTarget::matches($this->lockedRift, $this->dstPlanetRow, $owner, $this->legacyOwnerId, SN_TIME_NOW);
  }

  protected function finish(): void {
    $this->processed = true;
    // Only suppress this already handled event's native switch. The stored
    // fleet_mission and independent native return events stay unchanged.
    $this->missionId = MT_NONE;
  }
}
