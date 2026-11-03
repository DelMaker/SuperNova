<?php

namespace ZeroGalaxy;

use InvalidArgumentException;
use SN;

/** Compatibility entry points for native routes and fleet adapters. */
class AcsService {
  public static function application(array $settings, Storage $storage = null): AcsApplicationService {
    $repository = new AcsRepository(SN::$db, static function (array $records): void { SN::$gc->db->lockRecords($records); });
    return new AcsApplicationService($repository, $settings, SN_TIME_NOW,
      static function (array $fleets, int $userId): int { return AcsPolicy::playerCost($fleets, $userId); },
      $storage ? [$storage, 'findActive'] : null);
  }

  public static function invited(array $group, int $userId): bool { return AcsPolicy::invited($group, $userId); }

  private static function translated(callable $operation) {
    try { return $operation(); }
    catch (AcsFailure $error) { throw new InvalidArgumentException(SN::$lang[$error->getMessage()]); }
  }

  public static function group(int $groupId, int $userId, array $rift, array $settings, bool $lock = false): array {
    return self::translated(static function () use ($groupId, $userId, $rift, $settings, $lock) {
      return self::application($settings)->group($groupId, $userId, $rift, $lock);
    });
  }

  public static function load(int $groupId, int $userId, array $rift, array $settings, bool $lock = false): array {
    return self::translated(static function () use ($groupId, $userId, $rift, $settings, $lock) {
      return self::application($settings)->load($groupId, $userId, $rift, $lock);
    });
  }

  public static function invitedGroups(array $rift, int $userId, array $settings): array {
    return array_map(static function (array $group): array {
      return ['id' => $group['id'], 'name' => $group['name'],
        'url' => AcsPresenter::joinUrl($group['rift_id'], $group['id'], SN_ROOT_RELATIVE)];
    }, self::application($settings)->invitedGroups($rift, $userId));
  }

  public static function render($module, Storage $storage, array $settings, $template = null) {
    global $user;
    lng_include('fleet');
    $controller = new AcsController(self::application($settings, $storage), new AcsTokens(SN::$sn_secret_word),
      SN::$lang, SN_TIME_NOW, SN_ROOT_RELATIVE, [self::class, 'notify']);
    return $controller->handle($module, $template, $user, $_GET, $_POST, $_SERVER['REQUEST_METHOD'] ?? '');
  }

  public static function notify(array $actor, array $invite): void {
    $join = SN_ROOT_VIRTUAL . 'index.php?page=zero_galaxy_acs';
    msg_send_simple_message((int) $invite['id'], (int) $actor['id'], SN_TIME_NOW, MSG_TYPE_COMBAT, $actor['username'],
      SN::$lang['zero_galaxy_acs_title'], sprintf(SN::$lang['zero_galaxy_acs_invitation'],
        htmlspecialchars($actor['username'], ENT_QUOTES, 'UTF-8'), htmlspecialchars($join, ENT_QUOTES, 'UTF-8')));
  }
}
