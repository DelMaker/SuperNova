<?php

require __DIR__ . '/support/bootstrap.php';

use ZeroGalaxy\RiftRepository;
use ZeroGalaxy\Settings;
use ZeroGalaxy\WorldBodies;
use ZeroGalaxy\WorldReconciler;

class WorldMemoryRepository implements RiftRepository {
  public $timeline = ['id' => 1, 'next_tick_at' => 0, 'next_generation_at' => 0, 'seeded_systems' => 0];
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
  public function state(bool $lock = false): array {
    if ($lock && !$this->locked) { throw new RuntimeException('State lock outside transaction'); }
    return $this->timeline;
  }
  public function allForUpdate(): array {
    if (!$this->locked) { throw new RuntimeException('Rift lock outside transaction'); }
    return array_values($this->rifts);
  }
  public function delete(int $riftId): void { unset($this->rifts[$riftId]); }
  public function insert(array $rift, int $system, int $position, array $snapshot): void {
    $id = count($this->rifts) + 1;
    $this->rifts[$id] = $rift + ['rift_id' => $id, 'system' => $system, 'position' => $position,
      'planet_id' => null, 'config_snapshot' => json_encode($snapshot)];
  }
  public function schedule(int $nextTick, int $nextGeneration, int $seededSystems): void {
    $this->timeline = array_replace($this->timeline, ['next_tick_at' => $nextTick,
      'next_generation_at' => $nextGeneration, 'seeded_systems' => $seededSystems]);
  }
}
class WorldMemoryBodies implements WorldBodies {
  public $planets = [];
  public $finished = 0;
  public $failMaterialize = false;
  public function syncOwners(array $categories, int $now): array {
    return array_fill_keys(array_keys($categories), 17);
  }
  public function lockPlanets(array $rifts): array {
    return array_intersect_key($this->planets, array_flip(array_column($rifts, 'rift_id')));
  }
  public function materialize(array $rift, int $ownerId, int $now, string $phase, ?array $planet): void {
    if ($this->failMaterialize) { throw new RuntimeException('Native planet adapter failure'); }
    $this->planets[$rift['rift_id']] = ['owner' => $ownerId, 'phase' => $phase];
  }
  public function removePlanet(array $rift, int $ownerId, ?array $planet): void { unset($this->planets[$rift['rift_id']]); }
  public function finish(int $now): void { $this->finished++; }
}

$settings = Settings::load(['target_active_per_system' => 1, 'generation_seconds' => 100]);
$settings['categories'] = ['tiny' => $settings['categories']['tiny']];
foreach (['energy' => 2, 'active' => 3, 'unstable' => 4, 'closed' => 2] as $phase => $seconds) {
  $settings['categories']['tiny']['phases_hours'][$phase] = ['mean' => $seconds / 3600, 'spread_percent' => 0];
}
$records = new WorldMemoryRepository(); $bodies = new WorldMemoryBodies();
$world = new WorldReconciler($records, $bodies, function (string $key, array $category): array {
  return zgProfile(['category' => ['key' => $key, 'name' => $category['name'], 'color' => $category['color']]]);
});
$check($world->tick(1000, $settings, 1, 1) === true && $records->rifts && $bodies->finished === 1,
  'A due tick must reconcile, seed the system and finish its batched writes');
$check($world->tick(1001, $settings, 1, 1) === false && $records->transactions === 1,
  'A non-due request must skip the world transaction');
$rift = reset($records->rifts);
$world->tick((int) $rift['opens_at'], $settings, 1, 1);
$check(($bodies->planets[$rift['rift_id']]['owner'] ?? null) === 17, 'An opened rift must materialize for its category owner');
$records->rifts[$rift['rift_id']]['npc_owner_id'] = 18;
zgExpect(function () use ($world, $rift, $settings) { $world->tick((int) $rift['opens_at'] + 1, $settings, 1, 1, true); },
  RuntimeException::class, 'A rift owned by another NPC must stop reconciliation instead of being altered');
$records->rifts[$rift['rift_id']]['npc_owner_id'] = 17;
$paused = array_replace($settings, ['target_active_per_system' => 0]);
$before = [$records->timeline, $records->rifts]; $bodies->failMaterialize = true;
$error = zgExpect(function () use ($world, $paused, $rift) { $world->tick((int) $rift['opens_at'] + 1, $paused, 1, 1, true); },
  RuntimeException::class, 'A failed native adapter must propagate its error');
$check($error && $error->getMessage() === 'Native planet adapter failure', 'The original native failure must remain visible');
$check([$records->timeline, $records->rifts] === $before && $records->rollbacks === 2,
  'A native adapter failure must roll back the world transaction and its schedule');

echo "World reconciliation: {$assertions} assertions passed; explicit ports, no engine bootstrap or database.\n";
