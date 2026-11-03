<?php

namespace ZeroGalaxy;

use InvalidArgumentException;
use Planet\DBStaticPlanet;
use SN;
use Throwable;

class NativeFleetForm {
  private static $sending = false;

  public static function intercept(): void {
    if (INITIAL_PAGE === 'fleet' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
      && sys_get_param_int('galaxy', -1) === 0
      && sys_get_param_int('fleet_page', sys_get_param_int('mode')) === 3) {
      self::$sending = true;
      // Let common.php prepare the player before replacing the final native action.
      $_GET['fleet_page'] = $_POST['fleet_page'] = 2;
    }
  }

  public static function decorate($module, $template, array $settings, ?Storage $storage = null) {
    if (INITIAL_PAGE !== 'fleet' || !is_object($template)) { return $template; }
    $adapter = new NativeTemplateAdapter($template);
    $step = $adapter->isNative('fleet0') ? 0 : ($adapter->isNative('fleet1') ? 1 : ($adapter->isNative('fleet2') ? 2 : -1));
    if ($step < 0 || ($step !== 1 && (int) $adapter->value('galaxy') !== 0)) { return $template; }
    global $user, $planetrow;
    try {
      $group = self::integer($_POST['fleet_group'] ?? $_GET['fleet_group'] ?? 0);
      $fields = ['fleet_group' => $group];
      if ($step === 2) {
        $system = self::integer($_POST['system'] ?? null);
        $position = self::integer($_POST['planet'] ?? null);
        $type = self::integer($_POST['planet_type'] ?? null);
        $speed = self::integer($_POST['speed'] ?? null);
        if ($system < 1 || $system > (int) SN::$config->game_maxSystem
          || $position < 1 || $position > (int) SN::$config->game_maxPlanet
          || !in_array($type, [PT_PLANET, PT_DEBRIS, PT_MOON], true) || $speed < 1 || $speed > 10) {
          throw new InvalidArgumentException('zero_galaxy_attack_bad_request');
        }
        $ships = AttackPolicy::decode($_POST['usedfleet'] ?? null);
        $mission = self::integer($_POST['target_mission'] ?? 0);
        if (self::$sending) {
          self::$sending = false;
          if (!(SN::$gc->fleetDispatcher instanceof RiftFleetDispatcher)) {
            throw new InvalidArgumentException('zero_galaxy_unavailable');
          }
          $sourceId = self::integer($_POST['source_id'] ?? null);
          $issuedAt = self::integer($_POST['token_at'] ?? null);
          if (!AttackPolicy::validToken($_POST['token'] ?? null, $issuedAt, (int) $user['id'], $sourceId, 0, SN_TIME_NOW)) {
            throw new InvalidArgumentException('zero_galaxy_attack_expired_form');
          }
          foreach (['resource0', 'resource1', 'resource2'] as $resource) {
            if (self::integer($_POST[$resource] ?? 0) !== 0) { throw new InvalidArgumentException('zero_galaxy_attack_bad_request'); }
          }
          $request = DispatchRequest::flight($system, $position, $type, $mission, $ships, $speed,
            self::integer($_POST['captain_id'] ?? 0), $sourceId, $group);
          $result = (new FleetDispatchService(new NativeDispatch()))->send(new DispatchContext($user, $settings, SN_TIME_NOW), $request);
          $planetrow = $result->refreshedSource;
          $target = $result->target->planet + ['name' => ''];
          $target['planet_type'] = $type;
          self::sent($result->toArray(), $target);
          return $template;
        }
        $missions = DispatchRequest::missions($type, $ships, $group);
        if (!$missions) { throw new InvalidArgumentException('fl_bad_mission'); }
        if (!in_array($mission, $missions, true)) {
          $mission = count($ships) === 1 && in_array(MT_SPY, $missions, true) ? MT_SPY : $missions[0];
        }
        $adapter->replaceRows('missions', array_map(static function ($id) use ($mission): array {
          return ['ID' => $id, 'NAME' => SN::$lang['type_mission'][$id], 'CHECKED' => $id === $mission];
        }, $missions));
        $target = DBStaticPlanet::db_planet_by_gspt(0, $system, $position, $type === PT_MOON ? PT_MOON : PT_PLANET)
          ?: ['galaxy' => 0, 'system' => $system, 'planet' => $position, 'name' => ''];
        $travel = flt_travel_data($user, $planetrow, $target, $ships, $speed, [], \Universe::getBaseGalaxyDistance());
        $template->assign_vars(['consumption' => $travel['consumption'], 'fleet_capacity' => $travel['hold'],
          'planet_deuterium' => max(0, $planetrow['deuterium'] - $travel['consumption']),
          'MISSION_NAME' => SN::$lang['type_mission'][$mission], 'target_mission' => $mission, 'IS_TRANSPORT_MISSIONS' => false]);
        $adapter->mapRows('fleets', static function ($row) use ($target, $type): array {
          return array_replace($row, ['END_COORDS' => uni_render_coordinates($target),
            'END_TYPE_TEXT_SH' => SN::$lang['sys_planet_type_sh'][$type],
            'END_NAME' => $target['name']]);
        });
        $sourceId = (int) $planetrow['id'];
        $fields += ['source_id' => $sourceId, 'token_at' => SN_TIME_NOW,
          'token' => AttackPolicy::token((int) $user['id'], $sourceId, 0, SN_TIME_NOW)];
      }
      $payload = self::payload($adapter, $storage, $settings, $step, $group, $fields);
      if ($step === 2) { $payload['naturalDuration'] = (int) ($travel['duration'] ?? 0); }
      $template->assign_vars(['ZERO_ATTACK_STEP' => $step,
        'ZERO_ATTACK_JSON' => json_encode($payload,
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
        'ZERO_ATTACK_SCRIPT_URL' => SN_ROOT_RELATIVE . $module->getRootRelative() . 'design/js/zero_galaxy_attack.js?v='
          . substr(sha1_file(__DIR__ . '/../../design/js/zero_galaxy_attack.js'), 0, 12)]);
      $adapter->escapeFleetNames();
      return $adapter->wrap($module, 'zero_galaxy_attack');
    } catch (Throwable $error) {
      self::$sending = false;
      $message = $error instanceof DispatchFailure ? $error->translate(SN::$lang)
        : (SN::$lang[$error->getMessage()] ?? SN::$lang['zero_galaxy_attack_bad_request']);
      if (!($error instanceof DispatchFailure) && !($error instanceof InvalidArgumentException)) {
        error_log('Zero Galaxy native fleet form: ' . $error->getMessage());
        $message = SN::$lang['zero_galaxy_attack_failed'];
      }
      \SnTemplate::messageBox(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), SN::$lang['fl_error']);
      return $template;
    }
  }

  private static function payload(NativeTemplateAdapter $adapter, ?Storage $storage, array $settings,
    int $step, int $groupId, array $fields): array {
    global $user;
    $lang = SN::$lang;
    $mission = $groupId ? MT_AKS : (int) $adapter->value('target_mission');
    $payload = ['step' => $step, 'mission' => $mission, 'groupId' => $groupId,
      'attackMissions' => [MT_ATTACK, MT_AKS], 'distance' => \Universe::getBaseGalaxyDistance(), 'fields' => $fields];
    if (!$storage || (int) $adapter->value('galaxy') !== 0
      || (int) $adapter->value('planet_type') !== PT_PLANET) { return $payload; }
    $rift = $storage->findActive((int) $adapter->value('system'), (int) $adapter->value('planet'), SN_TIME_NOW);
    if (!$rift || ($limit = AttackPolicy::limit($rift)) === null) { return $payload; }
    if ($step < 2 && !in_array($mission, [MT_ATTACK, MT_AKS], true)) { return $payload; }
    $acs = $groupId ? AcsService::load($groupId, (int) $user['id'], $rift, $settings) : null;
    $prices = []; $scale = 1;
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
    $profile = Profile::read($rift);
    return $payload + ['target' => ['system' => (int) $rift['system'], 'position' => (int) $rift['position'],
      'name' => RiftName::forRift($rift, Lifecycle::phase($rift, SN_TIME_NOW), $lang['zero_galaxy_time']['locale'] ?? 'ru'),
      'color' => $profile['category']['color'], 'limit' => (string) $limit . ($scale === 1 ? '' : '000000')],
      'unitCosts' => $prices, 'costScale' => (string) $scale,
      'fixedFleet' => $step ? array_map('strval', AttackPolicy::decode($adapter->value('usedfleet'))) : [],
      'existingFleet' => array_map('strval', $existing),
      'groupArrival' => (int) ($acs['group']['ankunft'] ?? 0), 'serverTimeZone' => date_default_timezone_get(),
      'labels' => ['limit' => $lang['zero_galaxy_fleet_limit'],
        'selected' => $groupId ? $lang['zero_galaxy_acs_selected'] : $lang['zero_galaxy_attack_selected'],
        'remaining' => $lang['zero_galaxy_attack_remaining'], 'over' => $lang['zero_galaxy_attack_over'],
        'missing' => $lang['zero_galaxy_attack_missing'],
        'points' => $lang['zero_galaxy_points_short'], 'attackTitle' => $lang['zero_galaxy_attack_title'],
        'acsArrival' => $lang['zero_galaxy_acs_arrival'], 'acsReturn' => $lang['zero_galaxy_acs_return'],
        'acsWait' => $lang['zero_galaxy_acs_wait']]];
  }

  private static function sent(array $result, array $target): void {
    global $lang, $template_result;
    $template = \SnTemplate::gettemplate('fleet3', true);
    $source = $result['source'];
    $template->assign_block_vars('fleets', [
      'ID' => 1, 'START_TYPE_TEXT_SH' => $lang['sys_planet_type_sh'][$source['planet_type']],
      'START_COORDS' => uni_render_coordinates($source), 'START_NAME' => htmlspecialchars($source['name'], ENT_QUOTES, 'UTF-8'),
      'START_TIME_TEXT' => date(FMT_DATE_TIME, $result['return_at'] + SN_CLIENT_TIME_DIFF),
      'START_LEFT' => $result['return_at'] + 1 - SN_TIME_NOW,
      'END_TYPE_TEXT_SH' => $lang['sys_planet_type_sh'][$target['planet_type']],
      'END_COORDS' => uni_render_coordinates($target), 'END_NAME' => htmlspecialchars($target['name'], ENT_QUOTES, 'UTF-8'),
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

  private static function integer($value): int {
    if (!is_scalar($value) || is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
      throw new InvalidArgumentException('zero_galaxy_attack_bad_request');
    }
    return (int) $value;
  }
}
