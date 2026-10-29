<?php

namespace ZeroGalaxy;

use Fleet\FleetDispatcher;
use SN;

class RiftFleetDispatcher extends FleetDispatcher {
  public function getFleetEvents() {
    $events = parent::getFleetEvents();
    $candidates = [];
    foreach ($events as $index => $event) {
      if ($event->event !== EVENT_FLT_RETURN && (int) $event->fleet['fleet_end_galaxy'] === 0) {
        $candidates[$index] = $event;
      }
    }
    if (!$candidates) {
      return $events;
    }
    $prefix = SN::$db->db_prefix;
    $metadataAvailable = preg_match('/^[a-zA-Z0-9_]*$/D', $prefix) === 1;
    // This runs before the event transactions. Reading state inside an event
    // would reverse the lifecycle updater's state -> owner -> planet lock order.
    try {
      if ($metadataAvailable) {
        $result = SN::$db->link->query("SELECT npc_owner_id FROM `{$prefix}zero_galaxy_state` WHERE id = 1");
        if (!$result) {
          $metadataAvailable = false;
          error_log('Zero Galaxy dispatcher: state unavailable; attacks will return without combat');
        } else {
          $state = $result->fetch_assoc();
          $metadataAvailable = !empty($state);
        }
      }
    } catch (\Throwable $error) {
      error_log('Zero Galaxy dispatcher: ' . $error->getMessage());
      $metadataAvailable = false;
    }
    $legacyOwnerId = (int) ($state['npc_owner_id'] ?? 0);

    foreach ($candidates as $index => $event) {
      if ($event->missionId === MT_SPY && $event->event === EVENT_FLT_ARRIVE) {
        if ($metadataAvailable) {
          $events[$index] = new RiftSpyEvent($event, $legacyOwnerId);
        }
      } else {
        $events[$index] = new RiftAttackEvent($event, $legacyOwnerId, $metadataAvailable);
      }
    }

    return $events;
  }
}
