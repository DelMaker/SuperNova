<?php

namespace ZeroGalaxy;

class AcsPresenter {
  public static function joinUrl(int $riftId, int $groupId, string $root): string {
    return $root . 'index.php?page=zero_galaxy_attack&rift_id=' . $riftId . '&acs_group=' . $groupId;
  }

  public static function render($module, $template, array $page, int $userId, int $fleetId, int $now,
    AcsTokens $tokens, $messages, string $root) {
    $template = $module->addModuleTemplate('zero_galaxy_acs', $template);
    $template->assign_vars(['PAGE_HEADER' => $messages['zero_galaxy_acs_title'], 'ZERO_ACS_FLEET_ID' => $fleetId,
      'ZERO_ACS_ACTION' => $root . 'index.php?page=zero_galaxy_acs', 'ZERO_ACS_TOKEN_AT' => $now,
      'ZERO_ACS_TOKEN' => $tokens->manage($userId, $fleetId, $now)]);
    if ($page['fleet']) {
      $template->assign_var('ZERO_ACS_CAN_MANAGE', !$page['fleet']['fleet_group']);
      if ($context = $page['context']) {
        $template->assign_vars(['ZERO_ACS_GROUP_NAME' => htmlspecialchars($context['group']['name'], ENT_QUOTES, 'UTF-8'),
          'ZERO_ACS_CAN_MANAGE' => (int) $context['group']['flotten'] === $fleetId,
          'ZERO_ACS_JOIN_URL' => self::joinUrl((int) $page['rift']['rift_id'], (int) $context['group']['id'], $root)]);
        foreach ($page['members'] as $member) {
          $template->assign_block_vars('zero_acs_members', ['NAME' => htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8')]);
        }
      }
    } else {
      foreach ($page['invitations'] as $group) {
        $template->assign_block_vars('zero_acs_groups', ['NAME' => htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8'),
          'URL' => self::joinUrl($group['rift_id'], $group['id'], $root),
          'COORDS' => '[0:' . $group['system'] . ':' . $group['position'] . ']']);
      }
    }
    return $template;
  }
}
