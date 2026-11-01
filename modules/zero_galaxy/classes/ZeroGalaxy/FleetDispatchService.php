<?php

namespace ZeroGalaxy;

final class FleetDispatchService {
  private $gateway;

  public function __construct(FleetDispatchGateway $gateway) { $this->gateway = $gateway; }

  public function send(DispatchContext $context, DispatchRequest $request): DispatchResult {
    $result = $this->gateway->transaction(function () use ($context, $request): DispatchResult {
      $target = $this->gateway->lockTarget($context, $request);
      if (!$target) {
        $key = $request->mission === MT_ATTACK ? 'attack' : ($request->mission === MT_SPY ? 'spy' : 'recycle');
        throw new DispatchFailure('zero_galaxy_' . $key . '_missing');
      }
      $remaining = 0;
      if ($request->mission === MT_ATTACK) {
        $ships = $request->ships;
        $limit = AttackPolicy::limit($target->rift);
        if ($limit === null) { throw new DispatchFailure('zero_galaxy_attack_missing'); }
        if ($target->acs && count($target->acs['fleets']) >= AcsPolicy::MAX_FLEETS) { throw new DispatchFailure('zero_galaxy_acs_full'); }
        if ($this->gateway->cost($target, $ships) > $limit) { throw new DispatchFailure(AttackPolicy::OVER_LIMIT); }
      } elseif ($request->mission === MT_SPY) {
        $owned = $this->gateway->inventory($target, SHIP_SPY);
        $probes = min($owned, $request->probeCount);
        if ($probes < 1) { throw new DispatchFailure('', ATTACK_NO_SPIES); }
        $ships = [SHIP_SPY => $probes];
        $remaining = $owned - $probes;
      } else {
        $debris = (float) $target->planet['debris_metal'] + (float) $target->planet['debris_crystal'];
        if (!is_finite($debris) || $debris <= 0) { throw new DispatchFailure('', ATTACK_NO_DEBRIS); }
        [$ships, $owned] = $this->gateway->recyclerFleet($target, $debris);
        $remaining = $owned - array_sum($ships);
      }
      $allowed = $this->gateway->allowed($target, $ships, $request->mission, $request->speed);
      if ($allowed !== ATTACK_ALLOWED && !($allowed === ATTACK_NOOB && $request->mission !== MT_RECYCLE)) {
        throw new DispatchFailure('', $allowed);
      }
      $travel = $this->gateway->travel($target, $ships, $request->speed);
      if ($target->acs && $context->now + $travel['duration'] > (int) $target->acs['group']['ankunft']) {
        throw new DispatchFailure('zero_galaxy_acs_too_late');
      }
      if ($travel['duration'] < 1) {
        throw new DispatchFailure($request->mission === MT_ATTACK ? '' : 'gs_c13',
          $request->mission === MT_ATTACK ? ATTACK_NO_FUEL : null);
      }
      if ($request->captainId && !$this->gateway->hasCaptain($target, $request->captainId)) {
        throw new DispatchFailure('zero_galaxy_attack_captain_missing');
      }
      $dispatch = $this->gateway->launch($target, $request, $ships, $travel, $context->now);
      if ($request->captainId) { $this->gateway->assignCaptain($request->captainId, $dispatch['fleet_id']); }
      return new DispatchResult($dispatch, $ships, $travel, $target, $remaining);
    });
    $result->refreshedSource = $this->gateway->refreshSource($result->target->source, (int) $result->travel['consumption']);
    return $result;
  }
}
