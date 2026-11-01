<?php

namespace ZeroGalaxy;

use InvalidArgumentException;
use RuntimeException;
use SN;

class AttackSender {
  public static function send(Storage $storage, array $settings, array $requestedRift, array $ships,
    int $speed, int $captainId = 0, int $sourceId = 0, int $groupId = 0): array {
    global $user, $planetrow, $lang;
    require_once SN_ROOT_PHYSICAL . 'includes/includes/flt_functions.php';
    lng_include('fleet');
    if (!SN::$sys_user_logged_in || empty($user['id']) || $speed < 1 || $speed > 10 || $captainId < 0 || $groupId < 0) {
      throw new InvalidArgumentException($lang['zero_galaxy_attack_bad_request']);
    }
    $riftId = (int) ($requestedRift['rift_id'] ?? 0);
    if ($riftId < 1) { throw new RuntimeException('Zero Galaxy attack cannot start in the current database context'); }
    $request = DispatchRequest::attack($riftId, AttackPolicy::ships($ships), $speed, $captainId, $sourceId, $groupId);
    try {
      $result = (new FleetDispatchService(new NativeDispatch()))->send(new DispatchContext($user, $settings, SN_TIME_NOW), $request);
    } catch (DispatchFailure $error) {
      throw new InvalidArgumentException($error->translate($lang), 0, $error);
    }
    $planetrow = $result->refreshedSource;
    return $result->toArray();
  }
}
