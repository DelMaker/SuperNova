<?php

namespace ZeroGalaxy;

use RuntimeException;

class WorldReconciler {
  private $records;
  private $bodies;
  private $createProfile;

  public function __construct(RiftRepository $records, WorldBodies $bodies, callable $createProfile) {
    $this->records = $records;
    $this->bodies = $bodies;
    $this->createProfile = $createProfile;
  }

  /** @return bool False when the schedule was not due and nothing changed. */
  public function tick(int $now, array $settings, int $maxSystem, int $maxPlanet, bool $force = false): bool {
    if (!$force && (int) $this->records->state()['next_tick_at'] > $now) {
      return false;
    }
    $this->records->assertOutsideTransaction();
    return $this->records->transaction(function () use ($now, $settings, $maxSystem, $maxPlanet, $force): bool {
      $state = $this->records->state(true);
      if (!$force && (int) $state['next_tick_at'] > $now) {
        return false;
      }
      $owners = $this->bodies->syncOwners($settings['categories'], $now);
      $rifts = $this->records->allForUpdate();
      $planets = $this->bodies->lockPlanets($rifts);
      $occupied = [];
      $categoryCounts = [];
      $nextTransition = $now + $settings['tick_seconds'];
      foreach ($rifts as $rift) {
        $riftId = (int) $rift['rift_id'];
        $ownerId = (int) $rift['npc_owner_id'];
        $planet = $planets[$riftId] ?? null;
        $key = Profile::read($rift)['category']['key'] ?? '';
        if (!isset($owners[$key])) {
          $this->bodies->removePlanet($rift, $ownerId, $planet);
          $this->records->delete($riftId);
          continue;
        }
        if ($ownerId !== $owners[$key]) {
          throw new RuntimeException('Zero Galaxy rift owner differs from its category NPC; refusing to alter it');
        }
        $phase = Lifecycle::phase($rift, $now);
        if (Lifecycle::hasPlanet($phase)) {
          $this->bodies->materialize($rift, $ownerId, $now, $phase, $planet);
        } else {
          $this->bodies->removePlanet($rift, $ownerId, $planet);
        }
        if ((int) $rift['disappears_at'] <= $now) {
          $this->records->delete($riftId);
          continue;
        }
        $occupied[(int) $rift['system']][(int) $rift['position']] = true;
        $categoryCounts[$key] = ($categoryCounts[$key] ?? 0) + 1;
        $deadline = Lifecycle::nextTransition($rift, $now);
        if ($deadline > $now) {
          $nextTransition = min($nextTransition, $deadline);
        }
      }
      $this->bodies->finish($now);

      $maxSystem = max(1, $maxSystem);
      $maxPlanet = max(1, $maxPlanet);
      $generationDue = (int) $state['next_generation_at'] <= $now;
      $expectedBirths = Lifecycle::expectedBirths($settings, $settings['generation_seconds']);
      for ($system = 1; $system <= $maxSystem; $system++) {
        $initial = $system > (int) $state['seeded_systems'];
        if (!$initial && !$generationDue) {
          continue;
        }
        $free = array_values(array_diff(range(1, $maxPlanet), array_keys($occupied[$system] ?? [])));
        $mean = $initial ? $settings['target_active_per_system'] : $expectedBirths;
        $count = $this->birthCount((float) $mean, count($free), $initial);
        shuffle($free);
        for ($i = 0; $i < $count; $i++) {
          $categoryKey = Profile::choose($settings['categories'], $categoryCounts);
          if ($categoryKey === null) { break; }
          $category = $settings['categories'][$categoryKey];
          $profile = ($this->createProfile)($categoryKey, $category, $settings['fleet_limit_multiplier']);
          $snapshotData = ['profile' => $profile, 'category_settings' => $category,
            'fleet_limit_multiplier' => $settings['fleet_limit_multiplier']];
          $categoryCounts[$categoryKey] = ($categoryCounts[$categoryKey] ?? 0) + 1;
          $rift = Lifecycle::create($now, Settings::categoryPhaseRanges($category));
          $rift['npc_owner_id'] = $owners[$categoryKey];
          $this->records->insert($rift, $system, $free[$i], $snapshotData);
          $nextTransition = min($nextTransition, $rift['opens_at']);
        }
      }

      // Bounded catch-up: one generation after idle time, not every missed cycle.
      $nextGeneration = $generationDue ? $now + $settings['generation_seconds'] : (int) $state['next_generation_at'];
      $nextTick = min($nextTransition, $nextGeneration);
      $seededSystems = max($maxSystem, (int) $state['seeded_systems']);
      $this->records->schedule($nextTick, $nextGeneration, $seededSystems);
      return true;
    });
  }

  private function probability(float $chance): bool {
    return $chance >= 1 || ($chance > 0 && random_int(1, 1000000000) <= $chance * 1000000000);
  }

  private function birthCount(float $mean, int $slots, bool $initial): int {
    if ($slots === 0 || $mean <= 0) {
      return 0;
    }
    $mean = min($mean, $slots);
    if ($initial) {
      $count = 0;
      for ($i = 0; $i < $slots; $i++) {
        $count += $this->probability($mean / $slots) ? 1 : 0;
      }
      return $count;
    }
    $count = (int) floor($mean);
    return $count + ($this->probability($mean - $count) ? 1 : 0);
  }
}
