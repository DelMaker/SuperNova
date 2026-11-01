<?php

namespace ZeroGalaxy;

use Fleet\DbFleetStatic;
use InvalidArgumentException;
use Planet\DBStaticPlanet;
use SN;

/** Integration boundary for the core wizard's globals and included PHP pages. */
class NativeFleetWizard {
  private $module;

  public function __construct($module) {
    $this->module = $module;
    lng_include('fleet');
    lng_include('overview');
    require_once SN_ROOT_PHYSICAL . 'includes/includes/flt_functions.php';
    if (!defined('SN_IN_FLEET')) { define('SN_IN_FLEET', true); }
  }

  public function requestContext(): array {
    global $user, $planetrow;
    return ['userId' => (int) $user['id'], 'sourceId' => (int) $planetrow['id'],
      'now' => SN_TIME_NOW, 'secret' => SN::$sn_secret_word];
  }

  public function error(string $message): void {
    global $lang;
    \SnTemplate::messageBox(htmlspecialchars($lang[$message] ?? $message, ENT_QUOTES, 'UTF-8'),
      $lang['zero_galaxy_attack_title']);
  }

  public function prepare(AttackRequest $request, array $rift, array $acs = null): array {
    global $user, $planetrow, $lang, $debug, $template_result, $config;
    global $galaxy, $system, $planet, $planet_type, $target_mission, $TargetPlanet;
    global $MaxFleets, $FlyingFleets, $MaxExpeditions, $FlyingExpeditions;
    global $fleetarray, $missiontype, $speed_percent, $consumption, $fleet_speed, $distance, $duration, $is_transport_missions;
    $step = $request->step;
    $sourceId = $request->sourceId;
    $limit = AttackPolicy::limit($rift);
    $source = DBStaticPlanet::db_planet_by_id($sourceId);
    if (!$source || (int) $source['id_owner'] !== (int) $user['id']) {
      throw new InvalidArgumentException($lang['zero_galaxy_attack_bad_request']);
    }
    $planetrow = $source;
    $galaxy = 0; $system = (int) $rift['system']; $planet = (int) $rift['position'];
    $planet_type = PT_PLANET; $target_mission = $acs ? MT_AKS : MT_ATTACK;
    $TargetPlanet = DBStaticPlanet::db_planet_by_id((int) $rift['planet_id']);
    if (!$TargetPlanet || (int) $TargetPlanet['galaxy'] !== 0
      || (int) $TargetPlanet['id_owner'] !== (int) $rift['owner_id']) {
      throw new InvalidArgumentException($lang['zero_galaxy_attack_missing']);
    }
    $MaxFleets = GetMaxFleets($user);
    $FlyingFleets = DbFleetStatic::fleet_count_flying($user['id']);
    $MaxExpeditions = get_player_max_expeditons($user);
    $FlyingExpeditions = $MaxExpeditions ? DbFleetStatic::fleet_count_flying($user['id'], MT_EXPLORE) : 0;
    $missiontype = [$target_mission => $lang['type_mission'][$target_mission]];
    $is_transport_missions = false;
    $fleetarray = $request->ships;
    $speed_percent = $request->speed;
    if ($step > 0) {
      $cost = $acs ? AcsPolicy::playerCost($acs['fleets'], (int) $user['id'], $fleetarray) : AttackPolicy::cost($fleetarray);
      if ($cost > $limit) { throw new InvalidArgumentException(AttackPolicy::OVER_LIMIT); }
      foreach ($fleetarray as $id => $count) {
        if ($count > mrc_get_level($user, $source, $id, false, true) || get_ship_data($id, $user)['speed'] <= 0) {
          throw new InvalidArgumentException($lang['fl_attack_error'][ATTACK_NO_SHIPS]);
        }
      }
      $travel = flt_travel_data($user, $source, $TargetPlanet, $fleetarray, $speed_percent, [], \Universe::getBaseGalaxyDistance());
      if ($acs && SN_TIME_NOW + $travel['duration'] > (int) $acs['group']['ankunft']) {
        throw new InvalidArgumentException($lang['zero_galaxy_acs_too_late']);
      }
      $consumption = $travel['consumption']; $fleet_speed = $travel['fleet_speed'];
      $distance = $travel['distance']; $duration = $travel['duration'];
      $_POST['ships'] = $fleetarray;
      $_POST['usedfleet'] = str_rot13(base64_encode(json_encode($fleetarray)));
    }
    return ['source' => $source, 'travel' => $travel ?? []];
  }

  public function display(AttackRequest $request, array $rift, ?array $acs, array $page, ?array $result, array $settings): void {
    global $user, $planetrow, $lang, $debug, $template_result, $config;
    global $galaxy, $system, $planet, $planet_type, $target_mission, $TargetPlanet;
    global $MaxFleets, $FlyingFleets, $MaxExpeditions, $FlyingExpeditions;
    global $fleetarray, $missiontype, $speed_percent, $consumption, $fleet_speed, $distance, $duration, $is_transport_missions;
    $module = $this->module;
    $step = $request->step;
    $sourceId = $request->sourceId;
    $issuedAt = $request->issuedAt;
    $groupId = $request->groupId;
    $source = $page['source'];
    $travel = $page['travel'];
    $targetName = RiftName::forRift($rift, Lifecycle::phase($rift, SN_TIME_NOW),
      (string) ($lang['zero_galaxy_time']['locale'] ?? 'ru'));
    $TargetPlanet['name'] = $targetName;
    $payload = self::payload($rift, $step, $fleetarray, $targetName, $sourceId, $issuedAt, $acs);
    $payload['naturalDuration'] = (int) ($travel['duration'] ?? 0);
    if ($acs && $step === 1) {
      // The leader fixes arrival; only each participant's return leg changes
      // with speed. Keep the preview on the same native calculation as send.
      foreach (range(1, 10) as $candidateSpeed) {
        $payload['travelDurations'][$candidateSpeed] = (int) flt_travel_data($user, $source, $TargetPlanet,
          $fleetarray, $candidateSpeed, [], \Universe::getBaseGalaxyDistance())['duration'];
      }
    }
    $payload['groups'] = !$groupId && $step === 0 ? AcsService::invitedGroups($rift, (int) $user['id'], $settings) : [];
    $template_result = array_replace((array) $template_result, [
      'thisgalaxy' => (int) $source['galaxy'], 'thissystem' => (int) $source['system'],
      'thisplanet' => (int) $source['planet'], 'thisplanet_type' => (int) $source['planet_type'],
      'galaxy' => 0, 'system' => $system, 'planet' => $planet, 'planet_type' => PT_PLANET,
      'target_mission' => $target_mission, 'MISSION_NAME' => $lang['type_mission'][$target_mission],
      'TYPE_NAME' => $lang['fl_planettype'][PT_PLANET], 'ZERO_ATTACK_STEP' => $step,
      'ZERO_ATTACK_JSON' => json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
      'ZERO_ATTACK_SCRIPT_URL' => SN_ROOT_RELATIVE . $module->getRootRelative()
        . 'design/js/zero_galaxy_attack.js?v=' . rawurlencode($module->getVersion()) . '-'
        . substr(sha1_file(SN_ROOT_PHYSICAL . $module->getRootRelative() . 'design/js/zero_galaxy_attack.js'), 0, 12),
    ]);
    if ($step === 0) { include SN_ROOT_PHYSICAL . 'includes/includes/flt_page0.inc'; }
    elseif ($step === 1) { include SN_ROOT_PHYSICAL . 'includes/includes/flt_page1.inc'; }
    elseif ($step === 2) {
      require_once SN_ROOT_PHYSICAL . 'includes/includes/flt_page2.inc';
      sn_fleet_page2();
    } else { $this->sent($result, $TargetPlanet); }
  }

  public static function payload(array $rift, int $step, array $ships, string $targetName, int $sourceId, int $issuedAt,
    array $acs = null): array {
    global $user, $lang;
    $prices = [];
    $scale = 1;
    foreach (sn_get_groups('fleet') as $id) {
      $price = (float) (eco_get_total_cost($id, 1)['total'] ?? 0);
      if ($price <= 0 || !is_finite($price)) { continue; }
      if (floor($price) !== $price) { $scale = 1000000; }
      $prices[$id] = $price;
    }
    foreach ($prices as $id => $price) { $prices[$id] = sprintf('%.0F', round($price * $scale)); }
    $existing = [];
    foreach ($acs['fleets'] ?? [] as $fleet) {
      if ((int) $fleet['fleet_owner'] !== (int) $user['id']) { continue; }
      foreach (sys_unit_str2arr($fleet['fleet_array']) as $id => $count) { $existing[$id] = ($existing[$id] ?? 0) + $count; }
    }
    return AttackPresenter::payload(['userId' => (int) $user['id'], 'language' => $lang,
      'prices' => $prices, 'scale' => $scale, 'existingFleet' => $existing,
      'distance' => \Universe::getBaseGalaxyDistance(), 'root' => SN_ROOT_RELATIVE,
      'timeZone' => date_default_timezone_get(), 'secret' => SN::$sn_secret_word],
      $rift, $step, $ships, $targetName, $sourceId, $issuedAt, $acs);
  }

  private function sent(array $result, array $target): void {
    global $lang, $template_result;
    $template = \SnTemplate::gettemplate('fleet3', true);
    $source = $result['source'];
    $template->assign_block_vars('fleets', [
      'ID' => 1, 'START_TYPE_TEXT_SH' => $lang['sys_planet_type_sh'][$source['planet_type']],
      'START_COORDS' => uni_render_coordinates($source), 'START_NAME' => $source['name'],
      'START_TIME_TEXT' => date(FMT_DATE_TIME, $result['return_at'] + SN_CLIENT_TIME_DIFF),
      'START_LEFT' => $result['return_at'] + 1 - SN_TIME_NOW,
      'END_TYPE_TEXT_SH' => $lang['sys_planet_type_sh'][PT_PLANET],
      'END_COORDS' => uni_render_coordinates($target), 'END_NAME' => $target['name'],
      'END_TIME_TEXT' => date(FMT_DATE_TIME, $result['arrival'] + SN_CLIENT_TIME_DIFF),
      'END_LEFT' => $result['arrival'] + 1 - SN_TIME_NOW,
    ]);
    foreach ($result['fleet'] as $id => $count) {
      $template->assign_block_vars('fleets.ships', ['ID' => $id, 'NAME' => $lang['tech'][$id],
        'AMOUNT' => $count, 'AMOUNT_TEXT' => \HelperString::numberFloorAndFormat($count)]);
    }
    $travel = $result['travel'];
    $template->assign_vars(['mission' => $lang['type_mission'][$result['mission'] ?? MT_ATTACK],
      'dist' => \HelperString::numberFloorAndFormat($travel['distance']),
      'speed' => \HelperString::numberFloorAndFormat($travel['fleet_speed']),
      'deute_need' => \HelperString::numberFloorAndFormat($travel['consumption']),
      'time_go' => date(FMT_DATE_TIME, $result['arrival']), 'time_go_local' => date(FMT_DATE_TIME, $result['arrival'] + SN_CLIENT_TIME_DIFF),
      'time_back' => date(FMT_DATE_TIME, $result['return_at']), 'time_back_local' => date(FMT_DATE_TIME, $result['return_at'] + SN_CLIENT_TIME_DIFF)]);
    $template->assign_recursive($template_result);
    \SnTemplate::display($template, $lang['fl_title']);
  }
}
