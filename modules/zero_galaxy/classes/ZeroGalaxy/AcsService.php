<?php

namespace ZeroGalaxy;

use DBAL\db_mysql;
use Fleet\DbFleetStatic;
use InvalidArgumentException;
use SN;
use Throwable;

/** Module-owned admission and invitations; fleets and battles retain the native ACS format. */
class AcsService {
  public static function token(int $userId, int $sourceId, int $riftId, int $issuedAt, int $groupId): string {
    return hash_hmac('sha256', 'zero-galaxy-acs-join:v1:' . $groupId . ':'
      . AttackPolicy::token($userId, $sourceId, $riftId, $issuedAt), SN::$sn_secret_word);
  }

  public static function validToken($token, int $userId, int $sourceId, int $riftId, int $issuedAt,
    int $groupId, int $now): bool {
    return is_string($token) && $issuedAt >= $now - AttackPolicy::TOKEN_TTL && $issuedAt <= $now
      && hash_equals(self::token($userId, $sourceId, $riftId, $issuedAt, $groupId), $token);
  }

  private static function query(string $sql, bool $single = false) {
    return SN::$db->doquery($sql, $single);
  }

  private static function rows(string $sql): array {
    $rows = []; $result = self::query($sql);
    while ($row = SN::$db->db_fetch($result)) { $rows[] = $row; }
    return $rows;
  }

  private static function fail(string $key = 'zero_galaxy_acs_missing'): void {
    throw new InvalidArgumentException(SN::$lang[$key]);
  }

  public static function invited(array $group, int $userId): bool {
    return in_array((string) $userId, explode(',', (string) ($group['eingeladen'] ?? '')), true);
  }

  /** Read the small invitation row before locking users/planets, and only then read member fleets. */
  public static function group(int $groupId, int $userId, array $rift, array $settings, bool $lock = false): array {
    if (!AcsPolicy::enabled($settings)) { self::fail('zero_galaxy_acs_disabled'); }
    if ($groupId < 1) { self::fail(); }
    $suffix = $lock ? ' FOR UPDATE' : '';
    $group = self::query("SELECT * FROM {{aks}} WHERE id={$groupId}" . $suffix, true);
    if (!$group || (int) $group['galaxy'] !== 0 || (int) $group['planet_type'] !== PT_PLANET
      || (int) $group['system'] !== (int) $rift['system'] || (int) $group['planet'] !== (int) $rift['position']
      || (int) $group['ankunft'] <= SN_TIME_NOW || !self::invited($group, $userId)
      || !in_array(Lifecycle::phase($rift, SN_TIME_NOW), ['active', 'unstable'], true)) { self::fail(); }
    return $group;
  }

  public static function load(int $groupId, int $userId, array $rift, array $settings, bool $lock = false): array {
    $group = self::group($groupId, $userId, $rift, $settings, $lock);
    $suffix = $lock ? ' FOR UPDATE' : '';
    $fleets = self::rows("SELECT * FROM {{fleets}} WHERE fleet_group={$groupId} ORDER BY fleet_id" . $suffix);
    $flying = [];
    foreach ($fleets as $fleet) {
      if ((int) $fleet['fleet_mess'] !== FLEET_STATUS_FLYING) { continue; }
      if (!AcsPolicy::validMember($fleet, $rift, $groupId, (int) $group['ankunft'])
        || !self::invited($group, (int) $fleet['fleet_owner'])) { self::fail(); }
      $flying[] = $fleet;
    }
    if (!$flying || count($flying) > 5 || count(array_unique(explode(',', (string) $group['eingeladen']))) > 5) {
      self::fail();
    }
    return ['group' => $group, 'fleets' => $flying];
  }

  public static function invitedGroups(array $rift, int $userId, array $settings): array {
    if (!AcsPolicy::enabled($settings)) { return []; }
    $groups = self::rows("SELECT * FROM {{aks}} WHERE galaxy=0 AND planet_type=" . PT_PLANET
      . ' AND system=' . (int) $rift['system'] . ' AND planet=' . (int) $rift['position']
      . ' AND ankunft>' . SN_TIME_NOW . " AND FIND_IN_SET('{$userId}',eingeladen) ORDER BY ankunft,id");
    $available = [];
    foreach ($groups as $group) {
      try {
        $context = self::load((int) $group['id'], $userId, $rift, $settings);
        if (count($context['fleets']) >= 5) { continue; }
        $available[] = ['id' => (int) $group['id'], 'name' => $group['name'],
          'url' => SN_ROOT_RELATIVE . 'index.php?page=zero_galaxy_attack&rift_id=' . (int) $rift['rift_id']
            . '&acs_group=' . (int) $group['id']];
      } catch (InvalidArgumentException $error) { /* Expired, closed or invalid invitations are unavailable. */ }
    }
    return $available;
  }

  private static function manageToken(int $userId, int $fleetId, int $issuedAt): string {
    return hash_hmac('sha256', "zero-galaxy-acs-manage:v1:{$userId}:{$fleetId}:{$issuedAt}", SN::$sn_secret_word);
  }

  /** Route here before native fleet_page=4; its native handler lacks module policy and CSRF checks. */
  public static function render($module, Storage $storage, array $settings, $template = null) {
    global $user;
    lng_include('fleet');
    try {
      if (!AcsPolicy::enabled($settings)) { self::fail('zero_galaxy_acs_disabled'); }
      $fleetId = filter_var($_POST['fleetid'] ?? $_GET['fleetid'] ?? 0, FILTER_VALIDATE_INT);
      if ($fleetId === false || $fleetId < 0) { self::fail(); }
      $fleet = $fleetId ? self::query("SELECT * FROM {{fleets}} WHERE fleet_id={$fleetId}", true) : null;
      $rift = $fleet ? $storage->findActive((int) $fleet['fleet_end_system'], (int) $fleet['fleet_end_planet'], SN_TIME_NOW) : null;
      if ($fleetId && (!$fleet || !$rift || (int) $fleet['fleet_owner'] !== (int) $user['id']
        || (int) $fleet['fleet_end_galaxy'] !== 0 || (int) $fleet['fleet_end_type'] !== PT_PLANET
        || (int) $fleet['fleet_mess'] !== FLEET_STATUS_FLYING || (int) $fleet['fleet_start_time'] <= SN_TIME_NOW
        || !in_array((int) $fleet['fleet_mission'], [MT_ATTACK, MT_AKS], true))) { self::fail(); }
      if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $issuedAt = filter_var($_POST['token_at'] ?? null, FILTER_VALIDATE_INT);
        if (!$fleet || $issuedAt === false || $issuedAt < SN_TIME_NOW - AttackPolicy::TOKEN_TTL || $issuedAt > SN_TIME_NOW
          || !is_string($_POST['acs_token'] ?? null)
          || !hash_equals(self::manageToken((int) $user['id'], $fleetId, $issuedAt), $_POST['acs_token'])) {
          self::fail('zero_galaxy_attack_expired_form');
        }
        self::manage($fleet, $rift, $settings, trim((string) ($_POST['addtogroup'] ?? '')));
        $fleet = self::query("SELECT * FROM {{fleets}} WHERE fleet_id={$fleetId}", true);
      }
      $template = $module->addModuleTemplate('zero_galaxy_acs', $template);
      $template->assign_vars(['PAGE_HEADER' => SN::$lang['zero_galaxy_acs_title'], 'ZERO_ACS_FLEET_ID' => $fleetId,
        'ZERO_ACS_ACTION' => SN_ROOT_RELATIVE . 'index.php?page=zero_galaxy_acs',
        'ZERO_ACS_TOKEN_AT' => SN_TIME_NOW,
        'ZERO_ACS_TOKEN' => self::manageToken((int) $user['id'], $fleetId, SN_TIME_NOW)]);
      if ($fleet) {
        $groupId = (int) $fleet['fleet_group'];
        $template->assign_vars(['ZERO_ACS_CAN_MANAGE' => !$groupId]);
        if ($groupId) {
          $context = self::load($groupId, (int) $user['id'], $rift, $settings);
          $template->assign_vars(['ZERO_ACS_GROUP_NAME' => htmlspecialchars($context['group']['name'], ENT_QUOTES, 'UTF-8'),
            'ZERO_ACS_CAN_MANAGE' => (int) $context['group']['flotten'] === $fleetId,
            'ZERO_ACS_JOIN_URL' => SN_ROOT_RELATIVE . 'index.php?page=zero_galaxy_attack&rift_id=' . (int) $rift['rift_id'] . '&acs_group=' . $groupId]);
          foreach (explode(',', $context['group']['eingeladen']) as $memberId) {
            $member = db_user_by_id((int) $memberId);
            if ($member) { $template->assign_block_vars('zero_acs_members', ['NAME' => htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8')]); }
          }
        }
      } else {
        foreach (self::rows("SELECT DISTINCT system,planet FROM {{aks}} WHERE galaxy=0 AND ankunft>" . SN_TIME_NOW
          . " AND FIND_IN_SET('" . (int) $user['id'] . "',eingeladen)") as $coordinates) {
          $current = $storage->findActive((int) $coordinates['system'], (int) $coordinates['planet'], SN_TIME_NOW);
          if (!$current) { continue; }
          foreach (self::invitedGroups($current, (int) $user['id'], $settings) as $group) {
            $template->assign_block_vars('zero_acs_groups', ['NAME' => htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8'),
              'URL' => $group['url'], 'COORDS' => '[0:' . $current['system'] . ':' . $current['position'] . ']']);
          }
        }
      }
      return $template;
    } catch (InvalidArgumentException $error) {
      \SnTemplate::messageBox(htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8'), SN::$lang['zero_galaxy_acs_title']);
    } catch (Throwable $error) {
      error_log('Zero Galaxy ACS: ' . $error->getMessage());
      \SnTemplate::messageBox(SN::$lang['zero_galaxy_attack_failed'], SN::$lang['zero_galaxy_acs_title']);
    }
    return $template;
  }

  private static function manage(array $preview, array $rift, array $settings, string $inviteName): void {
    global $user;
    $fleetId = (int) $preview['fleet_id']; $userId = (int) $user['id'];
    $invite = $inviteName !== '' ? db_user_by_username($inviteName, true) : null;
    if ($inviteName !== '' && (!$invite || (int) $invite['user_bot'] !== 0)) { self::fail('zero_galaxy_acs_player_missing'); }
    if (db_mysql::$db_in_transaction || SN::$db->transactionCheck()) { self::fail(); }
    db_mysql::db_transaction_start();
    try {
      self::query('SELECT id FROM {{zero_galaxy_state}} WHERE id=1 FOR UPDATE', true);
      $current = self::query('SELECT * FROM {{zero_galaxy_rifts}} WHERE rift_id=' . (int) $rift['rift_id'] . ' FOR UPDATE', true);
      if (!$current || !in_array(Lifecycle::phase($current, SN_TIME_NOW), ['active', 'unstable'], true)) { self::fail(); }
      $groupSelector = !empty($preview['fleet_group']) ? 'id=' . (int) $preview['fleet_group'] : "flotten={$fleetId}";
      $existing = self::query("SELECT * FROM {{aks}} WHERE {$groupSelector} FOR UPDATE", true);
      if ($existing && (int) $existing['flotten'] !== $fleetId) { self::fail(); }
      $ownerIds = [$userId, (int) ($current['npc_owner_id'] ?? 0)];
      if ($existing) { $ownerIds = array_merge($ownerIds, array_map('intval', explode(',', $existing['eingeladen']))); }
      SN::$gc->db->lockRecords(['users' => array_values(array_unique(array_filter($ownerIds))), 'planets' => [(int) $preview['fleet_start_planet_id'], (int) $current['planet_id']],
        'fleets' => [$fleetId]]);
      $fleet = self::query("SELECT * FROM {{fleets}} WHERE fleet_id={$fleetId} FOR UPDATE", true);
      if (!$fleet || (int) $fleet['fleet_owner'] !== $userId || (int) $fleet['fleet_mess'] !== FLEET_STATUS_FLYING
        || (int) $fleet['fleet_end_galaxy'] !== 0 || (int) $fleet['fleet_end_type'] !== PT_PLANET
        || (int) $fleet['fleet_end_system'] !== (int) $current['system'] || (int) $fleet['fleet_end_planet'] !== (int) $current['position']
        || (int) $fleet['fleet_start_time'] <= SN_TIME_NOW || !in_array((int) $fleet['fleet_mission'], [MT_ATTACK, MT_AKS], true)) { self::fail(); }
      $limit = AttackPolicy::limit($current);
      if ($limit === null || AttackPolicy::cost(sys_unit_str2arr($fleet['fleet_array'])) > $limit) { self::fail(); }
      $groupId = (int) $fleet['fleet_group'];
      if (!$groupId) {
        DbFleetStatic::dbAcsInsert($userId, $fleetId, $fleet);
        $group = DbFleetStatic::dbAcsGetByFleet($fleetId);
        if (!$group) { self::fail(); }
        $groupId = (int) $group['id'];
        DbFleetStatic::fleet_update_set($fleetId, ['fleet_group' => $groupId, 'fleet_mission' => MT_AKS]);
      }
      $context = self::load($groupId, $userId, $current, $settings, true);
      if (AcsPolicy::playerCost($context['fleets'], $userId) > $limit) { self::fail(); }
      if ($invite) {
        $members = array_unique(explode(',', $context['group']['eingeladen']));
        if (self::invited($context['group'], (int) $invite['id'])) { self::fail('zero_galaxy_acs_already_invited'); }
        if (count($members) >= 5) { self::fail('zero_galaxy_acs_full'); }
        $invited = SN::$db->db_escape(implode(',', $members) . ',' . (int) $invite['id']);
        self::query("UPDATE {{aks}} SET eingeladen='{$invited}' WHERE id={$groupId}");
      }
      db_mysql::db_transaction_commit();
    } catch (Throwable $error) { db_mysql::db_transaction_rollback(); throw $error; }
    if ($invite) {
      $join = SN_ROOT_VIRTUAL . 'index.php?page=zero_galaxy_acs';
      try {
        msg_send_simple_message((int) $invite['id'], $userId, SN_TIME_NOW, MSG_TYPE_COMBAT, $user['username'],
          SN::$lang['zero_galaxy_acs_title'], sprintf(SN::$lang['zero_galaxy_acs_invitation'],
            htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'), htmlspecialchars($join, ENT_QUOTES, 'UTF-8')));
      } catch (Throwable $error) { error_log('Zero Galaxy committed ACS invitation notification: ' . $error->getMessage()); }
    }
  }
}
