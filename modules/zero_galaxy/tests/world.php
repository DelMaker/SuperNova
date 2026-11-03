<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/support/autoload.php';
require __DIR__ . '/support/assertions.php';

use ZeroGalaxy\Lifecycle;
use ZeroGalaxy\RiftRepository;
use ZeroGalaxy\Settings;
use ZeroGalaxy\WorldBodies;
use ZeroGalaxy\WorldReconciler;

class WorldMemoryRepository implements RiftRepository {
  public $timeline = ['id' => 1, 'npc_owner_id' => 17, 'next_tick_at' => 0,
    'next_generation_at' => 0, 'seeded_systems' => 0];
  public $rifts = [];
  public $transactions = 0;
  public $rollbacks = 0;
  public $locked = false;
  public function assertOutsideTransaction(): void {
    if ($this->locked) { throw new RuntimeException('Nested world transaction'); }
  }
  public function transaction(callable $operation) {
    $before = [$this->timeline, $this->rifts]; $this->transactions++; $this->locked = true;
    try { return $operation(); }
    catch (Throwable $error) { [$this->timeline, $this->rifts] = $before; $this->rollbacks++; throw $error; }
    finally { $this->locked = false; }
  }
  public function state(bool $lock = false): ?array {
    if ($lock && !$this->locked) { throw new RuntimeException('State lock outside transaction'); }
    return $this->timeline;
  }
  public function allForUpdate(): iterable {
    if (!$this->locked) { throw new RuntimeException('Rift lock outside transaction'); }
    return $this->rifts;
  }
  public function delete(int $riftId): void { unset($this->rifts[$riftId]); }
  public function insert(array $rift, int $system, int $position, array $snapshot): void {
    $id = count($this->rifts) + 1;
    $this->rifts[$id] = $rift + ['rift_id' => $id, 'system' => $system, 'position' => $position,
      'planet_id' => null, 'npc_owner_id' => null, 'config_snapshot' => json_encode($snapshot)];
  }
  public function schedule(int $nextTick, int $nextGeneration, int $seededSystems): void {
    $this->timeline = array_replace($this->timeline, ['next_tick_at' => $nextTick,
      'next_generation_at' => $nextGeneration, 'seeded_systems' => $seededSystems]);
  }
  public function inSystem(int $system, int $now): array { throw new LogicException('Read model not used by reconciliation'); }
  public function findById(int $id, int $now): ?array { throw new LogicException('Read model not used by reconciliation'); }
}
class WorldMemoryBodies implements WorldBodies {
  public $planets = [];
  public $failMaterialize = false;
  public $cleanups = 0;
  public function syncOwners(array $categories, array $state, int $now): array {
    return array_fill_keys(array_keys($categories), 17);
  }
  public function assignOwner(array &$rift, int $ownerId, int $legacyOwnerId): void { $rift['npc_owner_id'] = $ownerId; }
  public function planet(array $rift, int $ownerId): ?array { return $this->planets[$rift['rift_id']] ?? null; }
  public function materialize(array $rift, int $ownerId, int $now, string $phase, ?array $planet): void {
    if ($this->failMaterialize) { throw new RuntimeException('Native planet adapter failure'); }
    $this->planets[$rift['rift_id']] = ['owner' => $ownerId, 'phase' => $phase];
  }
  public function removePlanet(array $rift, int $ownerId, ?array $planet): void { unset($this->planets[$rift['rift_id']]); }
  public function removeUnusedOwners(): void { $this->cleanups++; }
}

$settings = Settings::load(['target_active_per_system' => 1, 'generation_seconds' => 100]);
$settings['categories'] = ['tiny' => $settings['categories']['tiny']];
foreach (['energy' => 2, 'active' => 3, 'unstable' => 4, 'closed' => 2] as $phase => $seconds) {
  $settings['categories']['tiny']['phases_hours'][$phase] = ['mean' => $seconds / 3600, 'spread_percent' => 0];
}
$records = new WorldMemoryRepository(); $bodies = new WorldMemoryBodies();
$world = new WorldReconciler($records, $bodies, function (string $key, array $category): array {
  return ['version' => 1, 'category' => ['key' => $key, 'name' => $category['name'], 'color' => $category['color']]];
});
$world->tick(1000, $settings, 1, 1);
$check(count($records->rifts) === 1, 'Initial seeding must create one rift for a single guaranteed slot');
$check($records->timeline['seeded_systems'] === 1 && $records->timeline['next_generation_at'] === 1100,
  'Seeding must retain the generation cadence');
$rift = $records->rifts[1];
$check($rift['opens_at'] === 1002 && $rift['closes_at'] === 1009 && $rift['disappears_at'] === 1011,
  'Reconciliation must preserve the consecutive lifecycle deadlines');
$check($bodies->planets === [], 'Energy phase must not materialize a native planet');
$world->tick(1001, $settings, 1, 1);
$check($records->transactions === 1, 'A non-due request must skip the world transaction');
$paused = array_replace($settings, ['target_active_per_system' => 0]);
$world->tick(1002, $paused, 1, 1);
$check($bodies->planets[1] === ['owner' => 17, 'phase' => 'active'], 'Opening must invoke the native adapter with the resolved owner');
$world->tick(1005, $paused, 1, 1);
$check($bodies->planets[1]['phase'] === 'unstable', 'Unstable deadline must update the same native planet');
$world->tick(1009, $paused, 1, 1);
$check($bodies->planets === [] && isset($records->rifts[1]), 'Closure must remove the planet but retain the closed marker');
$world->tick(1011, $paused, 1, 1);
$check($records->rifts === [], 'Final expiration must remove the marker');
$check($records->timeline['next_generation_at'] === 1100, 'Forced lifecycle transitions must not move a future generation deadline');

$records->rifts[1] = $rift; $records->timeline['next_tick_at'] = 0;
$before = [$records->timeline, $records->rifts]; $bodies->failMaterialize = true;
try { $world->tick(1003, $paused, 1, 1, true); $check(false, 'A failed native adapter must propagate its error'); }
catch (RuntimeException $error) {
  $check($error->getMessage() === 'Native planet adapter failure', 'The original native failure must remain visible');
}
$check([$records->timeline, $records->rifts] === $before && $records->rollbacks === 1,
  'A native adapter failure must roll back the world transaction and its schedule');

$bodies->failMaterialize = false;
$empty = $paused; $empty['categories'] = [];
$world->tick(1003, $empty, 1, 1, true);
$check($records->rifts === [] && $bodies->planets === [], 'Removing the last category must remove its rifts and stop generation');

echo "World reconciliation: {$assertions} assertions passed; explicit ports, no engine bootstrap or database.\n";
