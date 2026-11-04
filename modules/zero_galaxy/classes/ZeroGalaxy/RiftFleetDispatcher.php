<?php

namespace ZeroGalaxy;

use Fleet\FleetDispatcher;
use Throwable;

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
    // Read outside the event transactions: reading state inside an event would
    // reverse the lifecycle updater's state -> owner -> planet lock order.
    $metadataAvailable = self::metadataAvailable();
    foreach ($candidates as $index => $event) {
      $arrival = $event->event === EVENT_FLT_ARRIVE;
      $events[$index] = $arrival && in_array($event->missionId, [MT_SPY, MT_RECYCLE], true)
        ? new RiftMissionEvent($event, $metadataAvailable)
        : new RiftAttackEvent($event, $metadataAvailable);
    }

    return $events;
  }

  /** Without module state, attacks return without combat; native scouting and recycling still run. */
  private static function metadataAvailable(): bool {
    try {
      $sql = Sql::fromGame();
      return (bool) $sql->one('SELECT id FROM ' . $sql->table('zero_galaxy_state') . ' WHERE id = 1');
    } catch (Throwable $error) {
      error_log('Zero Galaxy dispatcher: state unavailable; attacks will return without combat: ' . $error->getMessage());
      return false;
    }
  }
}
