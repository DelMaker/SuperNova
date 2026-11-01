<?php

namespace ZeroGalaxy;

class AcsApplicationService {
  private $store;
  private $settings;
  private $now;
  private $findRift;
  private $playerCost;

  public function __construct(AcsStore $store, array $settings, int $now, callable $playerCost,
    callable $findRift = null) {
    $this->store = $store;
    $this->settings = $settings;
    $this->now = $now;
    $this->playerCost = $playerCost;
    $this->findRift = $findRift;
  }

  private function fail(string $key = 'zero_galaxy_acs_missing'): void { throw new AcsFailure($key); }

  public function requireEnabled(): void {
    if (!AcsPolicy::enabled($this->settings)) { $this->fail('zero_galaxy_acs_disabled'); }
  }

  public function group(int $groupId, int $userId, array $rift, bool $lock = false): array {
    $this->requireEnabled();
    if ($groupId < 1) { $this->fail(); }
    $group = $this->store->group($groupId, $lock);
    if (!$group || (int) $group['galaxy'] !== 0 || (int) $group['planet_type'] !== PT_PLANET
      || (int) $group['system'] !== (int) $rift['system'] || (int) $group['planet'] !== (int) $rift['position']
      || (int) $group['ankunft'] <= $this->now || !AcsPolicy::invited($group, $userId)
      || !in_array(Lifecycle::phase($rift, $this->now), ['active', 'unstable'], true)) { $this->fail(); }
    return $group;
  }

  public function load(int $groupId, int $userId, array $rift, bool $lock = false): array {
    $group = $this->group($groupId, $userId, $rift, $lock);
    $flying = [];
    foreach ($this->store->fleets($groupId, $lock) as $fleet) {
      if ((int) $fleet['fleet_mess'] !== FLEET_STATUS_FLYING) { continue; }
      if (!AcsPolicy::validMember($fleet, $rift, $groupId, (int) $group['ankunft'])
        || !AcsPolicy::invited($group, (int) $fleet['fleet_owner'])) { $this->fail(); }
      $flying[] = $fleet;
    }
    if (!$flying || count($flying) > AcsPolicy::MAX_FLEETS
      || count(array_unique(explode(',', (string) $group['eingeladen']))) > AcsPolicy::MAX_PLAYERS) { $this->fail(); }
    return ['group' => $group, 'fleets' => $flying];
  }

  public function invitedGroups(array $rift, int $userId): array {
    if (!AcsPolicy::enabled($this->settings)) { return []; }
    $available = [];
    foreach ($this->store->invitedAt($rift, $userId, $this->now) as $group) {
      try {
        $context = $this->load((int) $group['id'], $userId, $rift);
        if (count($context['fleets']) >= AcsPolicy::MAX_FLEETS) { continue; }
        $available[] = ['id' => (int) $group['id'], 'name' => $group['name'], 'rift_id' => (int) $rift['rift_id'],
          'system' => (int) $rift['system'], 'position' => (int) $rift['position']];
      } catch (AcsFailure $error) {}
    }
    return $available;
  }

  public function overview(int $userId, int $fleetId): array {
    $this->requireEnabled();
    if ($fleetId < 0) { $this->fail(); }
    if (!$this->findRift) { throw new \LogicException('ACS page requires a rift lookup'); }
    $fleet = $fleetId ? $this->store->fleet($fleetId) : null;
    $rift = $fleet ? ($this->findRift)((int) $fleet['fleet_end_system'], (int) $fleet['fleet_end_planet'], $this->now) : null;
    if ($fleetId && !AcsPolicy::manageableFleet($fleet, $rift, $userId, $this->now)) { $this->fail(); }
    $page = ['fleet' => $fleet, 'rift' => $rift, 'context' => null, 'members' => [], 'invitations' => []];
    if ($fleet && $fleet['fleet_group']) {
      $page['context'] = $this->load((int) $fleet['fleet_group'], $userId, $rift);
      foreach (explode(',', $page['context']['group']['eingeladen']) as $memberId) {
        if ($member = $this->store->user((int) $memberId)) { $page['members'][] = $member; }
      }
    } elseif (!$fleet) {
      foreach ($this->store->invitedCoordinates($userId, $this->now) as $coordinates) {
        $current = ($this->findRift)((int) $coordinates['system'], (int) $coordinates['planet'], $this->now);
        if ($current) { $page['invitations'] = array_merge($page['invitations'], $this->invitedGroups($current, $userId)); }
      }
    }
    return $page;
  }

  /** Returns the invited player after commit so notification failure cannot undo the group. */
  public function manage(array $preview, array $rift, int $userId, string $inviteName): ?array {
    $fleetId = (int) $preview['fleet_id'];
    $invite = $inviteName !== '' ? $this->store->userByName($inviteName) : null;
    if ($inviteName !== '' && (!$invite || (int) $invite['user_bot'] !== 0)) { $this->fail('zero_galaxy_acs_player_missing'); }
    return $this->store->transaction(function () use ($preview, $rift, $userId, $fleetId, $invite) {
      $current = $this->store->lockRift((int) $rift['rift_id']);
      if (!$current || !in_array(Lifecycle::phase($current, $this->now), ['active', 'unstable'], true)) { $this->fail(); }
      $existing = $this->store->leaderGroup($preview, $fleetId);
      if ($existing && (int) $existing['flotten'] !== $fleetId) { $this->fail(); }
      $owners = [$userId, (int) ($current['npc_owner_id'] ?? 0)];
      if ($existing) { $owners = array_merge($owners, array_map('intval', explode(',', $existing['eingeladen']))); }
      $this->store->lockFleetContext($owners, [(int) $preview['fleet_start_planet_id'], (int) $current['planet_id']], $fleetId);
      $fleet = $this->store->fleet($fleetId, true);
      if (!AcsPolicy::manageableFleet($fleet, $current, $userId, $this->now)) { $this->fail(); }
      $limit = AttackPolicy::limit($current);
      if ($limit === null) { $this->fail(); }
      $groupId = (int) $fleet['fleet_group'] ?: $this->store->createGroup($userId, $fleet);
      $context = $this->load($groupId, $userId, $current, true);
      if (($this->playerCost)($context['fleets'], $userId) > $limit) { $this->fail(); }
      if ($invite) {
        if (AcsPolicy::invited($context['group'], (int) $invite['id'])) { $this->fail('zero_galaxy_acs_already_invited'); }
        if (count(array_unique(explode(',', $context['group']['eingeladen']))) >= AcsPolicy::MAX_PLAYERS) {
          $this->fail('zero_galaxy_acs_full');
        }
        $this->store->invite((int) $invite['id'], $fleetId);
      }
      return $invite;
    });
  }
}
