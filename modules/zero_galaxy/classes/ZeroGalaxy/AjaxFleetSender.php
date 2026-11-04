<?php

namespace ZeroGalaxy;

use SN;
use Throwable;

/** Quick reconnaissance and recycling posted by the native universe `doit()` to flotenajax.php. */
class AjaxFleetSender {
  /** @return string The native flotenajax.php response: a message, or `message|counter=remaining` on success. */
  public static function send(): string {
    global $user, $planetrow, $lang;
    lng_include('universe');
    lng_include('fleet');
    require_once SN_ROOT_PHYSICAL . 'includes/includes/flt_functions.php';
    $mission = Input::int($_POST['mission'] ?? null);
    $recycle = $mission === MT_RECYCLE;
    $prefix = $recycle ? 'zero_galaxy_recycle_' : 'zero_galaxy_spy_';
    $system = Input::system($_POST['system'] ?? null);
    $position = Input::position($_POST['planet'] ?? null);
    if (!SN::$sys_user_logged_in || empty($user['id']) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
      || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest'
      || Input::int($_POST['galaxy'] ?? null) !== 0 || !in_array($mission, [MT_SPY, MT_RECYCLE], true)
      || Input::int($_POST['planet_type'] ?? null) !== ($recycle ? PT_DEBRIS : PT_PLANET)
      || $system === null || $position === null) {
      return $lang[$prefix . 'bad_request'];
    }
    if ($recycle) {
      $request = DispatchRequest::recycle($system, $position);
    } else {
      $probes = array_key_exists('shipcount', $_POST) ? Input::int($_POST['shipcount'])
        : abs((int) SN::$user_options[PLAYER_OPTION_FLEET_SPY_DEFAULT]);
      if ($probes === null || $probes < 1) { return $lang['fl_attack_error'][ATTACK_NO_SPIES]; }
      $request = DispatchRequest::spy($system, $position, $probes);
    }
    try {
      $result = (new NativeDispatch())->send($user, $request, SN_TIME_NOW);
    } catch (DispatchFailure $error) {
      return $error->translate($lang);
    } catch (Throwable $error) {
      error_log('Zero Galaxy quick mission: ' . $error->getMessage());
      return $lang[$prefix . 'failed'];
    }
    $planetrow = $result->refreshedSource;
    $ships = [];
    foreach ($result->fleet as $id => $count) { $ships[] = $count . ' ' . $lang['tech'][$id]; }
    return $lang['gs_sending'] . ' ' . implode(', ', $ships) . ' ' . $lang['gs_to']
      . " 0:{$system}:{$position}|" . ($recycle ? 'flt_recyclers' : 'flt_spies') . "={$result->remaining}";
  }
}
