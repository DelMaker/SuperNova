<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/support/autoload.php';
require __DIR__ . '/support/assertions.php';
foreach (['MT_ATTACK'=>1, 'MT_AKS'=>2, 'MT_SPY'=>6, 'MT_RECYCLE'=>8, 'SHIP_SPY'=>210,
  'PT_PLANET'=>1, 'PT_DEBRIS'=>2,
  'ATTACK_ALLOWED'=>0, 'ATTACK_NOOB'=>1, 'ATTACK_NO_SPIES'=>2, 'ATTACK_NO_DEBRIS'=>3, 'ATTACK_NO_FUEL'=>4,
  'ATTACK_NO_TARGET'=>5, 'ATTACK_NO_RECYCLERS'=>6] as $name=>$value) {
  define($name, $value);
}
function sn_get_groups($group) { return $group === 'flt_recyclers' ? [209] : [206, 209, 210]; }

use ZeroGalaxy\DispatchContext;
use ZeroGalaxy\DispatchFailure;
use ZeroGalaxy\DispatchRequest;
use ZeroGalaxy\FleetDispatchGateway;
use ZeroGalaxy\FleetDispatchService;
use ZeroGalaxy\LockedRiftTarget;

class ServiceFixtureGateway implements FleetDispatchGateway {
  public $target;
  public $fleets = [];
  public $stock = 10;
  public $fuel = 1000;
  public $allowed = ATTACK_ALLOWED;
  public $rollbacks = 0;
  public $captain = false;
  public $captainFailure = false;
  public $assigned = [];

  public function __construct() {
    $rift = ['system'=>3,'position'=>2,'config_snapshot'=>json_encode(['profile'=>['version'=>1,'fleet_limit'=>100]])];
    $this->target = new LockedRiftTarget(['id'=>7], ['id'=>22,'deuterium'=>1000],
      ['id'=>9,'debris_metal'=>100,'debris_crystal'=>50], $rift, null);
  }
  public function transaction(callable $action, DispatchRequest $request = null) {
    $before = [$this->fleets,$this->stock,$this->fuel,$this->assigned];
    try { return $action(); }
    catch (Throwable $error) {
      [$this->fleets,$this->stock,$this->fuel,$this->assigned] = $before;
      $this->rollbacks++;
      throw $error;
    }
  }
  public function lockTarget(DispatchContext $context, DispatchRequest $request): ?LockedRiftTarget { return $this->target; }
  public function cost(LockedRiftTarget $target, array $ships): int { return array_sum($ships)*10; }
  public function inventory(LockedRiftTarget $target, int $unitId): int { return $this->stock; }
  public function recyclerFleet(LockedRiftTarget $target, float $debris): array { return [[209=>3],$this->stock]; }
  public function allowed(LockedRiftTarget $target, array $ships, int $mission, int $speed): int { return $this->allowed; }
  public function travel(LockedRiftTarget $target, array $ships, int $speed): array { return ['duration'=>10,'consumption'=>2]; }
  public function hasCaptain(LockedRiftTarget $target, int $captainId): bool { return $this->captain; }
  public function launch(LockedRiftTarget $target, DispatchRequest $request, array $ships, array $travel, int $now): array {
    $this->stock -= array_sum($ships); $this->fuel -= $travel['consumption'];
    $row = ['fleet_id'=>count($this->fleets)+1,'arrival'=>$now+10,'return_at'=>$now+20,'mission'=>$request->mission];
    $this->fleets[] = $row;
    return $row;
  }
  public function assignCaptain(int $captainId, int $fleetId): void {
    if ($this->captainFailure) { throw new RuntimeException('Captain persistence failed'); }
    $this->assigned[$captainId] = $fleetId;
  }
  public function refreshSource(array $source, int $fuel): array { $source['deuterium']=$this->fuel; return $source; }
}

$context = new DispatchContext(['id'=>7,'current_planet'=>22], ['acs_enabled'=>true], 1000);
$request = DispatchRequest::attack(1, [206=>2], 10, 0, 22, 0);
$gateway = new ServiceFixtureGateway(); $service = new FleetDispatchService($gateway);
$first = $service->send($context,$request);
$second = $service->send($context,$request);
$check($first->dispatch['fleet_id'] !== $second->dispatch['fleet_id'], 'A repeated valid command must dispatch another fleet.');
$check(count($gateway->fleets) === 2 && $gateway->stock === 6 && $gateway->fuel === 996,
  'Each accepted repeat debits its own ships and fuel.');
$check($first->toArray()['fleet'] === [206=>2] && $first->dispatch['arrival'] === 1010,
  'The result retains the requested fleet and uses the injected application clock.');
$check($second->refreshedSource['deuterium'] === 996, 'Post-commit display state must reflect the accepted dispatch.');

$failure = function (callable $operation, string $key) use ($check): void {
  try { $operation(); }
  catch (DispatchFailure $error) { $check($error->languageKey === $key, 'Expected dispatch rejection: '.$key); return; }
  $check(false, 'Expected dispatch rejection: '.$key);
};
$before = [$gateway->fleets,$gateway->stock,$gateway->fuel];
$failure(function () use ($service,$context) { $service->send($context,DispatchRequest::attack(1,[206=>11],10,0,22,0)); },
  ZeroGalaxy\AttackPolicy::OVER_LIMIT);
$check([$gateway->fleets,$gateway->stock,$gateway->fuel] === $before, 'An over-limit attempt must not dispatch or debit resources.');
$gateway->target = null;
$failure(function () use ($service,$context,$request) { $service->send($context,$request); }, 'zero_galaxy_attack_missing');
$check(count($gateway->fleets) === 2, 'A disappeared target cannot create another fleet.');

$gateway = new ServiceFixtureGateway(); $service = new FleetDispatchService($gateway);
$gateway->stock=5; $gateway->allowed=ATTACK_ALLOWED;
$spy = $service->send($context,DispatchRequest::spy(3,2,1,20));
$check($spy->fleet === [SHIP_SPY=>5] && $spy->remaining === 0, 'Reconnaissance uses at most the owned probes.');
$gateway->stock=5; $gateway->allowed=ATTACK_NOOB;
try { $service->send($context,DispatchRequest::spy(3,2,1,1)); $check(false,'Reconnaissance must retain native admission.'); }
catch (DispatchFailure $error) { $check($error->nativeError === ATTACK_NOOB,'Reconnaissance retains native point protection.'); }
$gateway->target->planet['debris_metal']=0; $gateway->target->planet['debris_crystal']=0;
try { $service->send($context,DispatchRequest::recycle(3,2)); $check(false,'Automatic selection needs a debris volume.'); }
catch (DispatchFailure $error) { $check($error->nativeError === ATTACK_NO_DEBRIS,'Empty debris returns the native rejection.'); }
$gateway->target->planet['debris_metal']=100;
try { $service->send($context,DispatchRequest::recycle(3,2)); $check(false,'Recycling must retain native admission.'); }
catch (DispatchFailure $error) { $check($error->nativeError === ATTACK_NOOB,'Recycling cannot bypass native point protection.'); }
$gateway->stock=10; $gateway->allowed=ATTACK_ALLOWED;
$recycle = $service->send($context,DispatchRequest::recycle(3,2));
$check($recycle->fleet === [209=>3] && $recycle->remaining === 7, 'The selected native recycler fleet and remaining stock are preserved.');
$gateway->target->planet['debris_metal']=0;
foreach ([ATTACK_NO_TARGET, ATTACK_NO_DEBRIS] as $allowed) {
  $gateway->stock=10; $gateway->allowed=$allowed;
  $before = count($gateway->fleets);
  $advance = $service->send($context,DispatchRequest::recycle(3,2,[209=>2]));
  $check(count($gateway->fleets) === $before+1 && $advance->fleet === [209=>2],
    'Selected recyclers can launch before a planet or debris exists.');
}

$gateway = new ServiceFixtureGateway(); $service = new FleetDispatchService($gateway);
$gateway->captain=true; $gateway->captainFailure=true;
try { $service->send($context,DispatchRequest::attack(1,[206=>2],10,77,22,0)); $check(false,'Captain persistence must fail.'); }
catch (RuntimeException $error) { $check($gateway->fleets === [] && $gateway->stock === 10 && $gateway->fuel === 1000,
  'A captain persistence failure rolls back fleet creation and both debits.'); }
$gateway->captainFailure=false;
$withCaptain = $service->send($context,DispatchRequest::attack(1,[206=>2],10,77,22,0));
$check($gateway->assigned === [77=>$withCaptain->dispatch['fleet_id']], 'The captain is attached to the same accepted fleet.');
$gateway->target->acs=['group'=>['ankunft'=>1009],'fleets'=>[]];
$failure(function () use ($service,$context,$request) { $service->send($context,$request); },'zero_galaxy_acs_too_late');
$check(count($gateway->fleets) === 1, 'A fleet that misses the shared arrival cannot be dispatched.');

$gateway = new ServiceFixtureGateway(); $service = new FleetDispatchService($gateway);
$before = [$gateway->fleets,$gateway->stock,$gateway->fuel];
$failure(function () use ($service,$context) {
  $service->send($context,DispatchRequest::flight(3,2,PT_PLANET,MT_ATTACK,[206=>11],10));
}, 'Такой флот не сможет пролететь через разлом!');
$check([$gateway->fleets,$gateway->stock,$gateway->fuel] === $before,
  'An over-limit attack from the native form must fail before any debit.');
$gateway->target->rift = [];
$failure(function () use ($service,$context) {
  $service->send($context,DispatchRequest::attack(1,[206=>1],10,0,22,0));
}, 'zero_galaxy_attack_missing');
foreach ([
  [MT_ATTACK,PT_PLANET,[206=>2],ATTACK_NO_TARGET],
  [MT_SPY,PT_PLANET,[206=>1,SHIP_SPY=>2],ATTACK_NO_TARGET],
  [MT_RECYCLE,PT_DEBRIS,[206=>1,209=>2],ATTACK_NO_DEBRIS],
] as [$mission,$type,$ships,$allowed]) {
  $gateway->stock=10; $gateway->allowed=$allowed;
  $result=$service->send($context,DispatchRequest::flight(3,2,$type,$mission,$ships,10));
  $check($result->fleet === $ships,
    'Native fleet selection must preserve the complete composition when no target or debris exists.');
  if ($mission === MT_RECYCLE) {
    $check($result->remaining === 8, 'Escorting ships do not reduce the remaining recycler count.');
  }
}
$gateway->stock=10; $gateway->allowed=ATTACK_NO_FUEL;
$before = [$gateway->fleets,$gateway->stock,$gateway->fuel];
try { $service->send($context,DispatchRequest::flight(3,2,PT_PLANET,MT_ATTACK,[206=>1],10)); $check(false,'Empty-coordinate flights still require fuel.'); }
catch (DispatchFailure $error) { $check($error->nativeError === ATTACK_NO_FUEL && [$gateway->fleets,$gateway->stock,$gateway->fuel] === $before,
  'Native fuel rejection must retain all inventory even for empty-coordinate flights.'); }

echo "Dispatch application service: {$assertions} assertions passed; no game bootstrap, database or global engine state.\n";
