<?php

namespace ZeroGalaxy;

use SN;
use Throwable;

class SpySender {
  public static function send(Storage $storage, array $settings): string {
    global $user, $planetrow, $lang;
    lng_include('universe');
    lng_include('fleet');
    require_once SN_ROOT_PHYSICAL . 'includes/includes/flt_functions.php';
    if (!SN::$sys_user_logged_in || empty($user['id'])
      || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
      || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
      return self::message('zero_galaxy_spy_bad_request', $lang['gs_c00']);
    }
    $system = self::integer('system');
    $position = self::integer('planet');
    if (self::integer('galaxy') !== 0 || self::integer('mission') !== MT_SPY || self::integer('planet_type') !== PT_PLANET
      || $system === null || $system < 1 || $system > (int) SN::$config->game_maxSystem
      || $position === null || $position < 1 || $position > (int) SN::$config->game_maxPlanet) {
      return self::message('zero_galaxy_spy_bad_request', $lang['gs_c02']);
    }
    $requested = array_key_exists('shipcount', $_POST) ? self::integer('shipcount')
      : abs((int) SN::$user_options[PLAYER_OPTION_FLEET_SPY_DEFAULT]);
    if ($requested === null || $requested < 1) { return $lang['fl_attack_error'][ATTACK_NO_SPIES]; }
    try {
      $result = (new FleetDispatchService(new NativeDispatch()))->send(new DispatchContext($user, $settings, SN_TIME_NOW),
        DispatchRequest::spy($system, $position, null, $requested));
    } catch (DispatchFailure $error) {
      return $error->nativeError !== null ? $error->translate($lang) : self::message($error->languageKey, $lang['gs_c02']);
    } catch (Throwable $error) {
      error_log('Zero Galaxy spy: ' . $error->getMessage());
      return self::message('zero_galaxy_spy_failed', $lang['gs_c00']);
    }
    $planetrow = $result->refreshedSource;
    return $lang['gs_sending'] . ' ' . $result->fleet[SHIP_SPY] . ' ' . $lang['tech'][SHIP_SPY] . ' '
      . $lang['gs_to'] . " 0:{$system}:{$position}|flt_spies={$result->remaining}";
  }

  private static function integer(string $name): ?int {
    $value = array_key_exists($name, $_POST) && is_scalar($_POST[$name]) ? filter_var($_POST[$name], FILTER_VALIDATE_INT) : false;
    return $value === false ? null : $value;
  }

  private static function message(string $key, string $fallback): string {
    global $lang;
    return !empty($lang[$key]) ? $lang[$key] : $fallback;
  }
}
