<?php

namespace ZeroGalaxy;

use Fleet\DbFleetStatic;
use InvalidArgumentException;
use Planet\DBStaticPlanet;
use SN;
use Throwable;

/** The native fleet wizard, with a fixed module-owned target and a separate validated sender. */
class AttackView {
  public static function render($module, Storage $storage, array $settings) {
    global $user, $planetrow, $lang, $debug, $template_result, $config;
    global $galaxy, $system, $planet, $planet_type, $target_mission, $TargetPlanet;
    global $MaxFleets, $FlyingFleets, $MaxExpeditions, $FlyingExpeditions;
    global $fleetarray, $missiontype, $speed_percent, $consumption, $fleet_speed, $distance, $duration, $is_transport_missions;

    lng_include('fleet');
    lng_include('overview');
    require_once SN_ROOT_PHYSICAL . 'includes/includes/flt_functions.php';
    if (!defined('SN_IN_FLEET')) { define('SN_IN_FLEET', true); }
    try {
      $step = self::integer($_GET['step'] ?? 0);
      $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
      if ($step < 0 || $step > 3 || ($step > 0 && !$post) || ($step === 0 && $post)) {
        throw new InvalidArgumentException($lang['zero_galaxy_attack_bad_request']);
      }
      $riftId = self::integer($post ? ($_POST['rift_id'] ?? null) : ($_GET['rift_id'] ?? null));
      $sourceId = $post ? self::integer($_POST['source_id'] ?? null) : (int) $planetrow['id'];
      $issuedAt = $post ? self::integer($_POST['token_at'] ?? null) : SN_TIME_NOW;
      if ($post && !AttackPolicy::validToken($_POST['token'] ?? null, $issuedAt, (int) $user['id'], $sourceId, $riftId, SN_TIME_NOW)) {
        throw new InvalidArgumentException($lang['zero_galaxy_attack_expired_form']);
      }
      $rift = $storage->findById($riftId, SN_TIME_NOW);
      $limit = $rift ? AttackPolicy::limit($rift) : null;
      if (!$rift || $limit === null || empty($rift['planet_id'])
        || !in_array(Lifecycle::phase($rift, SN_TIME_NOW), ['active', 'unstable'], true)) {
        throw new InvalidArgumentException($lang['zero_galaxy_attack_missing']);
      }
      $source = DBStaticPlanet::db_planet_by_id($sourceId);
      if (!$source || (int) $source['id_owner'] !== (int) $user['id']) {
        throw new InvalidArgumentException($lang['zero_galaxy_attack_bad_request']);
      }
      $planetrow = $source;
      $galaxy = 0; $system = (int) $rift['system']; $planet = (int) $rift['position'];
      $planet_type = PT_PLANET; $target_mission = MT_ATTACK;
      $TargetPlanet = DBStaticPlanet::db_planet_by_id((int) $rift['planet_id']);
      if (!$TargetPlanet || (int) $TargetPlanet['galaxy'] !== 0
        || (int) $TargetPlanet['id_owner'] !== (int) $rift['owner_id']) {
        throw new InvalidArgumentException($lang['zero_galaxy_attack_missing']);
      }
      if ($post) { self::validateContext($rift); }
      $MaxFleets = GetMaxFleets($user);
      $FlyingFleets = DbFleetStatic::fleet_count_flying($user['id']);
      $MaxExpeditions = get_player_max_expeditons($user);
      $FlyingExpeditions = $MaxExpeditions ? DbFleetStatic::fleet_count_flying($user['id'], MT_EXPLORE) : 0;
      $missiontype = [MT_ATTACK => $lang['type_mission'][MT_ATTACK]];
      $is_transport_missions = false;
      $fleetarray = [];
      $speed_percent = $step > 1 ? self::integer($_POST['speed'] ?? null) : 10;
      if ($speed_percent < 1 || $speed_percent > 10) {
        throw new InvalidArgumentException($lang['fl_cheat_speed']);
      }
      if ($step > 0) {
        $fleetarray = $step === 1 ? AttackPolicy::ships($_POST['ships'] ?? null)
          : AttackPolicy::decode($_POST['usedfleet'] ?? null);
        if (AttackPolicy::cost($fleetarray) > $limit) { throw new InvalidArgumentException(AttackPolicy::OVER_LIMIT); }
        foreach ($fleetarray as $id => $count) {
          if ($count > mrc_get_level($user, $source, $id, false, true) || get_ship_data($id, $user)['speed'] <= 0) {
            throw new InvalidArgumentException($lang['fl_attack_error'][ATTACK_NO_SHIPS]);
          }
        }
        $travel = flt_travel_data($user, $source, $TargetPlanet, $fleetarray, $speed_percent, [], \Universe::getBaseGalaxyDistance());
        $consumption = $travel['consumption']; $fleet_speed = $travel['fleet_speed'];
        $distance = $travel['distance']; $duration = $travel['duration'];
        $_POST['ships'] = $fleetarray;
        $_POST['usedfleet'] = str_rot13(base64_encode(json_encode($fleetarray)));
      }
      $result = null;
      if ($step === 3) {
        $result = AttackSender::send($storage, $settings, $rift, $fleetarray, $speed_percent,
          isset($_POST['captain_id']) ? self::integer($_POST['captain_id']) : 0, $sourceId);
      }
      $targetName = RiftName::forRift($rift, Lifecycle::phase($rift, SN_TIME_NOW),
        (string) ($lang['zero_galaxy_time']['locale'] ?? 'ru'));
      $TargetPlanet['name'] = $targetName;
      $payload = self::payload($rift, $step, $fleetarray, $targetName, $sourceId, $issuedAt);
      $template_result = array_replace((array) $template_result, [
        'thisgalaxy' => (int) $source['galaxy'], 'thissystem' => (int) $source['system'],
        'thisplanet' => (int) $source['planet'], 'thisplanet_type' => (int) $source['planet_type'],
        'galaxy' => 0, 'system' => $system, 'planet' => $planet, 'planet_type' => PT_PLANET,
        'target_mission' => MT_ATTACK, 'MISSION_NAME' => $lang['type_mission'][MT_ATTACK],
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
      } else { self::sent($result, $TargetPlanet); }
    } catch (InvalidArgumentException $error) {
      \SnTemplate::messageBox(htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8'), $lang['zero_galaxy_attack_title']);
    } catch (Throwable $error) {
      error_log('Zero Galaxy attack view: ' . $error->getMessage());
      \SnTemplate::messageBox($lang['zero_galaxy_attack_failed'], $lang['zero_galaxy_attack_title']);
    }
  }

  private static function integer($value): int {
    if (!is_scalar($value) || is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
      throw new InvalidArgumentException(SN::$lang['zero_galaxy_attack_bad_request']);
    }
    return (int) $value;
  }

  private static function validateContext(array $rift): void {
    $fixed = ['galaxy' => 0, 'system' => (int) $rift['system'], 'planet' => (int) $rift['position'],
      'planet_type' => PT_PLANET, 'target_mission' => MT_ATTACK, 'fleet_group' => 0];
    foreach ($fixed as $key => $value) {
      if (isset($_POST[$key]) && self::integer($_POST[$key]) !== $value) {
        throw new InvalidArgumentException(SN::$lang['zero_galaxy_attack_bad_request']);
      }
    }
    foreach (['resource0', 'resource1', 'resource2', 'missiontime'] as $key) {
      if (isset($_POST[$key]) && self::integer($_POST[$key]) !== 0) {
        throw new InvalidArgumentException(SN::$lang['zero_galaxy_attack_bad_request']);
      }
    }
  }

  private static function payload(array $rift, int $step, array $ships, string $targetName, int $sourceId, int $issuedAt): array {
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
    $profile = Profile::read($rift);
    $limit = (string) AttackPolicy::limit($rift) . ($scale === 1 ? '' : '000000');
    return ['step' => $step, 'riftId' => (int) $rift['rift_id'], 'limit' => $limit,
      'unitCosts' => $prices, 'costScale' => (string) $scale, 'fixedFleet' => array_map('strval', $ships),
      'distance' => \Universe::getBaseGalaxyDistance(),
      'nextUrl' => SN_ROOT_RELATIVE . 'index.php?page=zero_galaxy_attack&step=' . ($step + 1),
      'fields' => ['rift_id' => (int) $rift['rift_id'], 'source_id' => $sourceId, 'token_at' => $issuedAt,
        'token' => AttackPolicy::token((int) $user['id'], $sourceId, (int) $rift['rift_id'], $issuedAt)],
      'targetName' => $targetName, 'color' => $profile['category']['color'],
      'coords' => '[0:' . $rift['system'] . ':' . $rift['position'] . ']',
      'labels' => ['limit' => $lang['zero_galaxy_fleet_limit'], 'selected' => $lang['zero_galaxy_attack_selected'],
        'remaining' => $lang['zero_galaxy_attack_remaining'], 'over' => $lang['zero_galaxy_attack_over'],
        'points' => $lang['zero_galaxy_points_short'], 'attackTitle' => $lang['zero_galaxy_attack_title'],
        'missing' => $lang['zero_galaxy_attack_missing']]];
  }

  /** Escape once at the module display hook; payloads and planet records keep their raw names. */
  public static function escapeFleetNames($template): void {
    foreach ($template->_tpldata['fleets'] ?? [] as $index => $fleet) {
      foreach (['START_NAME', 'END_NAME'] as $field) {
        if (isset($fleet[$field])) {
          $template->_tpldata['fleets'][$index][$field] = htmlspecialchars((string) $fleet[$field], ENT_QUOTES, 'UTF-8');
        }
      }
    }
  }

  private static function sent(array $result, array $target): void {
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
    $template->assign_vars(['mission' => $lang['type_mission'][MT_ATTACK],
      'dist' => \HelperString::numberFloorAndFormat($travel['distance']),
      'speed' => \HelperString::numberFloorAndFormat($travel['fleet_speed']),
      'deute_need' => \HelperString::numberFloorAndFormat($travel['consumption']),
      'time_go' => date(FMT_DATE_TIME, $result['arrival']), 'time_go_local' => date(FMT_DATE_TIME, $result['arrival'] + SN_CLIENT_TIME_DIFF),
      'time_back' => date(FMT_DATE_TIME, $result['return_at']), 'time_back_local' => date(FMT_DATE_TIME, $result['return_at'] + SN_CLIENT_TIME_DIFF)]);
    $template->assign_recursive($template_result);
    \SnTemplate::display($template, $lang['fl_title']);
  }
}
