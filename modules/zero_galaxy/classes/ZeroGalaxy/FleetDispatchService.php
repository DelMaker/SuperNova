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
        if ($limit === null && !$request->nativeForm) { throw new DispatchFailure('zero_galaxy_attack_missing'); }
        if ($limit !== null && $this->gateway->cost($target, $ships) > $limit) { throw new DispatchFailure(AttackPolicy::OVER_LIMIT); }
      } elseif ($request->mission === MT_SPY) {
        $owned = $this->gateway->inventory($target, SHIP_SPY);
        $probes = $request->nativeForm ? ($request->ships[SHIP_SPY] ?? 0) : min($owned, $request->probeCount);
        if ($probes < 1) { throw new DispatchFailure('', ATTACK_NO_SPIES); }
        $ships = $request->nativeForm ? $request->ships : [SHIP_SPY => $probes];
        $remaining = $owned - $probes;
      } else {
        if ($request->ships) {
          $ships = $request->ships;
          $owned = 0;
          foreach (sn_get_groups('flt_recyclers') as $id) { $owned += $this->gateway->inventory($target, $id); }
        } else {
          $debris = (float) $target->planet['debris_metal'] + (float) $target->planet['debris_crystal'];
          if (!is_finite($debris) || $debris <= 0) { throw new DispatchFailure('', ATTACK_NO_DEBRIS); }
          [$ships, $owned] = $this->gateway->recyclerFleet($target, $debris);
        }
        if (!array_intersect(array_keys($ships), sn_get_groups('flt_recyclers'))) {
          throw new DispatchFailure('', ATTACK_NO_RECYCLERS);
        }
        $remaining = $owned - array_sum(array_intersect_key($ships, array_flip(sn_get_groups('flt_recyclers'))));
      }
      $allowed = $this->gateway->allowed($target, $ships, $request->mission, $request->speed);
      $emptyTarget = $allowed === ATTACK_NO_TARGET && $request->nativeForm && $request->mission !== MT_RECYCLE;
      if ($allowed !== ATTACK_ALLOWED && !$emptyTarget) {
        throw new DispatchFailure('', $allowed);
      }
      $travel = $this->gateway->travel($target, $ships, $request->speed);
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
    }, $request);
    $result->refreshedSource = $this->gateway->refreshSource($result->target->source, (int) $result->travel['consumption']);
    return $result;
  }
}
