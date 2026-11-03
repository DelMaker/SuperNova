<?php

namespace ZeroGalaxy;

use SN;
use Throwable;

class RecycleSender {
  public static function send(Storage $storage, array $settings): string {
    global $user, $planetrow, $lang;
    lng_include('universe');
    lng_include('fleet');
    require_once SN_ROOT_PHYSICAL . 'includes/includes/flt_functions.php';
    $system = self::integer('system');
    $position = self::integer('planet');
    if (!SN::$sys_user_logged_in || empty($user['id'])
      || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
      || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest'
      || self::integer('galaxy') !== 0 || self::integer('mission') !== MT_RECYCLE
      || self::integer('planet_type') !== PT_DEBRIS
      || $system === null || $system < 1 || $system > (int) SN::$config->game_maxSystem
      || $position === null || $position < 1 || $position > (int) SN::$config->game_maxPlanet) {
      return $lang['zero_galaxy_recycle_bad_request'];
    }
    try {
      $ships = array_key_exists('ships', $_POST) ? AttackPolicy::ships($_POST['ships']) : [];
      $result = (new FleetDispatchService(new NativeDispatch()))->send(new DispatchContext($user, $settings, SN_TIME_NOW),
        DispatchRequest::recycle($system, $position, $ships));
    } catch (DispatchFailure $error) {
      return $error->translate($lang);
    } catch (Throwable $error) {
      error_log('Zero Galaxy recycling: ' . $error->getMessage());
      return $lang['zero_galaxy_recycle_failed'];
    }
    $planetrow = $result->refreshedSource;
    $ships = [];
    foreach ($result->fleet as $id => $count) { $ships[] = $count . ' ' . $lang['tech'][$id]; }
    return $lang['gs_sending'] . ' ' . implode(', ', $ships) . ' ' . $lang['gs_to']
      . " 0:{$system}:{$position}|flt_recyclers={$result->remaining}";
  }

  private static function integer(string $name): ?int {
    $value = isset($_POST[$name]) && is_scalar($_POST[$name]) ? filter_var($_POST[$name], FILTER_VALIDATE_INT) : false;
    return $value === false ? null : $value;
  }
}
