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

  public static function decorate($module, $template, array $settings) {
    if (INITIAL_PAGE !== 'fleet' || !is_object($template)) { return $template; }
    $adapter = new NativeTemplateAdapter($template);
    $step = $adapter->isNative('fleet1') ? 1 : ($adapter->isNative('fleet2') ? 2 : 0);
    if (!$step || ($step === 2 && (int) $adapter->value('galaxy') !== 0)) { return $template; }
    global $user, $planetrow;
    $fields = [];
    if ($step === 2) {
      try {
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
        $group = self::integer($_POST['fleet_group'] ?? 0);
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
          if (!in_array($mission, [MT_ATTACK, MT_AKS, MT_SPY, MT_RECYCLE], true)
            || $group < 0 || ($mission === MT_AKS) !== ($group > 0)) {
            throw new InvalidArgumentException('zero_galaxy_attack_bad_request');
          }
          foreach (['resource0', 'resource1', 'resource2'] as $resource) {
            if (self::integer($_POST[$resource] ?? 0) !== 0) { throw new InvalidArgumentException('zero_galaxy_attack_bad_request'); }
          }
          if ($mission === MT_RECYCLE) { $type = PT_DEBRIS; }
          $request = DispatchRequest::flight($system, $position, $type, $mission, $ships, $speed,
            self::integer($_POST['captain_id'] ?? 0), $sourceId, $group);
          $result = (new FleetDispatchService(new NativeDispatch()))->send(new DispatchContext($user, $settings, SN_TIME_NOW), $request);
          $planetrow = $result->refreshedSource;
          $target = $result->target->planet + ['name' => ''];
          $target['planet_type'] = $type;
          (new NativeFleetWizard($module))->sent($result->toArray(), $target);
          return $template;
        }
        $missions = $group ? [MT_AKS] : [MT_ATTACK];
        if (!$group && $type !== PT_DEBRIS && !empty($ships[SHIP_SPY])) { $missions[] = MT_SPY; }
        if (!$group && array_intersect(array_keys($ships), sn_get_groups('flt_recyclers'))) { $missions[] = MT_RECYCLE; }
        if (!in_array($mission, $missions, true)) {
          $mission = $type === PT_DEBRIS && in_array(MT_RECYCLE, $missions, true) ? MT_RECYCLE
            : (count($ships) === 1 && in_array(MT_SPY, $missions, true) ? MT_SPY : $missions[0]);
        }
        $adapter->replaceRows('missions', array_map(static function ($id) use ($mission): array {
          return ['ID' => $id, 'NAME' => SN::$lang['type_mission'][$id], 'CHECKED' => $id === $mission];
        }, $missions));
        $target = DBStaticPlanet::db_planet_by_gspt(0, $system, $position, $type === PT_MOON ? PT_MOON : PT_PLANET)
          ?: ['galaxy' => 0, 'system' => $system, 'planet' => $position, 'name' => ''];
        $travel = flt_travel_data($user, $planetrow, $target, $ships, $speed, [], \Universe::getBaseGalaxyDistance());
        $template->assign_vars(['consumption' => $travel['consumption'], 'fleet_capacity' => $travel['hold'],
          'planet_deuterium' => max(0, $planetrow['deuterium'] - $travel['consumption']),
          'MISSION_NAME' => SN::$lang['type_mission'][$mission], 'IS_TRANSPORT_MISSIONS' => false]);
        $adapter->mapRows('fleets', static function ($row) use ($target, $type): array {
          return array_replace($row, ['END_COORDS' => uni_render_coordinates($target),
            'END_TYPE_TEXT_SH' => SN::$lang['sys_planet_type_sh'][$type],
            'END_NAME' => htmlspecialchars($target['name'], ENT_QUOTES, 'UTF-8')]);
        });
        $sourceId = (int) $planetrow['id'];
        $fields = ['source_id' => $sourceId, 'token_at' => SN_TIME_NOW,
          'token' => AttackPolicy::token((int) $user['id'], $sourceId, 0, SN_TIME_NOW)];
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
    $template->assign_vars(['ZERO_ATTACK_STEP' => $step,
      'ZERO_ATTACK_JSON' => json_encode(['native' => true, 'step' => $step, 'distance' => \Universe::getBaseGalaxyDistance(), 'fields' => $fields],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
      'ZERO_ATTACK_SCRIPT_URL' => SN_ROOT_RELATIVE . $module->getRootRelative() . 'design/js/zero_galaxy_attack.js?v='
        . substr(sha1_file(__DIR__ . '/../../design/js/zero_galaxy_attack.js'), 0, 12)]);
    return $adapter->wrap($module, 'zero_galaxy_attack');
  }

  private static function integer($value): int {
    if (!is_scalar($value) || is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
      throw new InvalidArgumentException('zero_galaxy_attack_bad_request');
    }
    return (int) $value;
  }
}
