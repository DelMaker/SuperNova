<?php

namespace ZeroGalaxy;

class AttackPresenter {
  public static function payload(array $context, array $rift, int $step, array $ships, string $targetName, int $sourceId, int $issuedAt,
    array $acs = null): array {
    $lang = $context['language'];
    $prices = $context['prices'];
    $scale = $context['scale'];
    $profile = Profile::read($rift);
    $groupId = $acs ? (int) $acs['group']['id'] : 0;
    $existing = $context['existingFleet'];
    $limit = (string) AttackPolicy::limit($rift) . ($scale === 1 ? '' : '000000');
    return ['step' => $step, 'riftId' => (int) $rift['rift_id'], 'limit' => $limit,
      'unitCosts' => $prices, 'costScale' => (string) $scale, 'fixedFleet' => array_map('strval', $ships),
      'existingFleet' => array_map('strval', $existing), 'groupId' => $groupId, 'mission' => $groupId ? MT_AKS : MT_ATTACK,
      'groupName' => $acs['group']['name'] ?? '',
      'groupArrival' => (int) ($acs['group']['ankunft'] ?? 0), 'serverTimeZone' => $context['timeZone'],
      'distance' => $context['distance'],
      'nextUrl' => $context['root'] . 'index.php?page=zero_galaxy_attack&step=' . ($step + 1),
      'fields' => ['rift_id' => (int) $rift['rift_id'], 'source_id' => $sourceId, 'token_at' => $issuedAt,
        'token' => AttackPolicy::token($context['userId'], $sourceId, (int) $rift['rift_id'], $issuedAt, $context['secret']),
        'acs_group' => $groupId, 'acs_token' => (new AcsTokens($context['secret']))->join($context['userId'], $sourceId, (int) $rift['rift_id'], $issuedAt, $groupId)],
      'targetName' => $targetName, 'color' => $profile['category']['color'],
      'coords' => '[0:' . $rift['system'] . ':' . $rift['position'] . ']',
      'labels' => ['limit' => $lang['zero_galaxy_fleet_limit'], 'selected' => $groupId ? $lang['zero_galaxy_acs_selected'] : $lang['zero_galaxy_attack_selected'],
        'remaining' => $lang['zero_galaxy_attack_remaining'], 'over' => $lang['zero_galaxy_attack_over'],
        'points' => $lang['zero_galaxy_points_short'], 'attackTitle' => $lang['zero_galaxy_attack_title'],
        'missing' => $lang['zero_galaxy_attack_missing'], 'join' => $lang['zero_galaxy_acs_join'],
        'acsArrival' => $lang['zero_galaxy_acs_arrival'], 'acsReturn' => $lang['zero_galaxy_acs_return'],
        'acsWait' => $lang['zero_galaxy_acs_wait']]];
  }

}
