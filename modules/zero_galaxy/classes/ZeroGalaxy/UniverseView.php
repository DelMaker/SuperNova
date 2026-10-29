<?php

namespace ZeroGalaxy;

use SN;

class UniverseView {
  public static function render($module, $template, array $rifts, int $system, int $now, array $settings, bool $scan = false) {
    global $user, $planetrow, $lang, $template_result;

    lng_include('universe');
    lng_include('stat');
    lng_include('fleet');
    require_once SN_ROOT_PHYSICAL . 'includes/includes/flt_functions.php';

    $system = max(1, min(max(1, (int) SN::$config->game_maxSystem), $system));
    $maxPlanet = max(1, (int) SN::$config->game_maxPlanet);
    $recyclers = $recyclerInfo = [];
    foreach (sn_get_groups('flt_recyclers') as $id) {
      $recyclerInfo[$id] = get_ship_data($id, $user);
      if ($owned = max(0, (int) mrc_get_level($user, $planetrow, $id, false, true))) {
        $recyclers[$id] = $owned;
      }
    }
    $incomingCapacity = null;
    $template = $module->addModuleTemplate('zero_galaxy_native', $template);
    foreach (['galaxyrow', 'users', 'alliances', 'fleets', 'defense_active'] as $block) {
      $template->destroy_block_vars($block);
    }

    $byPosition = [];
    $metadata = [];
    $owners = [];
    $planetCount = 0;
    foreach ($rifts as $rift) {
      $position = (int) $rift['position'];
      $phase = Lifecycle::phase($rift, $now);
      if ($phase === '' || (int) $rift['system'] !== $system || $position < 1 || $position > $maxPlanet) {
        continue;
      }

      $riftId = (int) $rift['rift_id'];
      $ownerId = (int) ($rift['owner_id'] ?? 0);
      $planetId = (int) ($rift['planet_id'] ?? 0);
      $isPlanet = in_array($phase, ['active', 'unstable'], true) && $planetId > 0 && $ownerId > 0;
      if ($isPlanet && !array_key_exists($ownerId, $owners)) {
        $owners[$ownerId] = db_user_by_id($ownerId);
      }
      $canSpy = $isPlanet && !empty($owners[$ownerId]);
      $debris = [];
      if ($canSpy && ($rift['debris_metal'] ?? 0) + ($rift['debris_crystal'] ?? 0) > 0) {
        if ($incomingCapacity === null) {
          $incomingCapacity = self::incomingCapacity($system, (int) $user['id'], $recyclerInfo);
        }
        $debris = self::debris($rift, $recyclers, $incomingCapacity[$position] ?? 0, $planetrow);
      }
      $phaseLabel = (string) $lang['zero_galaxy_phase_' . $phase];
      $profileMetadata = self::profileMetadata($rift);
      if ($profileMetadata) {
        $name = RiftName::forRift($rift, $phase, (string) ($lang['zero_galaxy_time']['locale'] ?? 'ru'));
      } elseif ($phase === 'energy' || $phase === 'closed') {
        $name = $phaseLabel;
      } else {
        $name = sprintf($lang['zero_galaxy_rift_name'], $riftId) . ($phase === 'active' ? '' : ' — ' . $phaseLabel);
      }
      $image = $phase === 'energy' ? 'object_in_space' : 'black_planet';
      if ($isPlanet) {
        $image = in_array($rift['image'], Lifecycle::IMAGES, true) ? $rift['image'] : Lifecycle::IMAGES[0];
        $planetCount++;
      }

      $byPosition[$position] = array_merge([
        'PLANET_ID' => $isPlanet ? $planetId : 'rift_' . $riftId,
        'PLANET_NAME' => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
        'PLANET_NAME_JS' => js_safe_string($name),
        'PLANET_IMAGE' => $image,
        'USER_ID' => $canSpy ? $ownerId : 0,
        'USER_NAME' => $canSpy ? htmlspecialchars($owners[$ownerId]['username'], ENT_QUOTES, 'UTF-8') : '',
        'USER_NAME_JS' => $canSpy ? js_safe_string($owners[$ownerId]['username']) : '',
        'USER_ATTACKABLE' => $canSpy,
      ], $debris);
      $metadata[] = array_merge([
        'id' => $riftId, 'position' => $position, 'phase' => $phase, 'name' => $name,
        'phaseLabel' => $phaseLabel, 'canSpy' => $canSpy,
        'canAttack' => $canSpy && !empty($profileMetadata),
        'canRecycle' => !empty($debris['DEBRIS_WILL_GATHER']),
        'attackUrl' => SN_ROOT_RELATIVE . 'index.php?page=zero_galaxy_attack&rift_id=' . $riftId
          . '&system=' . $system . '&planet=' . $position,
        'imageUrl' => SN_ROOT_RELATIVE . $module->getRootRelative() . 'design/images/phases/' . $phase . '.png?v=' . rawurlencode($module->getVersion()),
        'nextAt' => Lifecycle::nextTransition($rift, $now),
        'nextLabel' => (string) $lang[$phase === 'closed' ? 'zero_galaxy_until_disappearance' : 'zero_galaxy_until_next_phase'],
      ], $profileMetadata);
    }

    for ($position = 1; $position <= $maxPlanet; $position++) {
      $template->assign_block_vars('galaxyrow', array_replace(self::emptyPosition($position), $byPosition[$position] ?? []));
    }
    foreach ($owners as $ownerId => $owner) {
      if (empty($owner)) {
        continue;
      }
      $template->assign_block_vars('users', [
        'ID' => $ownerId,
        'NAME' => htmlspecialchars($owner['username'], ENT_QUOTES, 'UTF-8'),
        'NAME_JS' => js_safe_string($owner['username']),
        'RANK' => '-', 'AVATAR' => 0, 'ALLY_ID' => 0, 'ALLY_TAG' => '', 'ALLY_TITLE' => '',
      ]);
    }

    $spyCount = max(1, (int) SN::$user_options[PLAYER_OPTION_FLEET_SPY_DEFAULT]);
    $payload = [
      'now' => $now,
      'refreshSeconds' => max(2, (int) ($settings['refresh_seconds'] ?? 30)),
      'rifts' => $metadata,
      'spyUrl' => SN_ROOT_RELATIVE . 'index.php?page=zero_galaxy_spy',
      'spyMission' => MT_SPY, 'planetType' => PT_PLANET, 'spyCount' => $spyCount,
      'recycleUrl' => SN_ROOT_RELATIVE . 'index.php?page=zero_galaxy_recycle',
      'recycleMission' => MT_RECYCLE, 'debrisType' => PT_DEBRIS,
      'labels' => [
        'badRequest' => (string) $lang['zero_galaxy_spy_bad_request'],
        'missing' => (string) $lang['zero_galaxy_spy_missing'],
        'failed' => (string) $lang['zero_galaxy_spy_failed'],
        'recycleBadRequest' => (string) $lang['zero_galaxy_recycle_bad_request'],
        'recycleMissing' => (string) $lang['zero_galaxy_recycle_missing'],
        'recycleFailed' => (string) $lang['zero_galaxy_recycle_failed'],
        'category' => (string) $lang['zero_galaxy_category'],
        'fleetLimit' => (string) $lang['zero_galaxy_fleet_limit'],
        'points' => (string) $lang['zero_galaxy_points_short'],
        'attack' => (string) $lang['zero_galaxy_attack'],
        'time' => $lang['zero_galaxy_time'],
      ],
    ];
    $template->assign_vars([
      'PAGE_HEADER' => $lang['zero_galaxy_title'],
      'galaxy' => 0, 'system' => $system, 'planet' => 1, 'MODE' => 1,
      'GALAXY_NAME' => $lang['zero_galaxy_title'], 'SYSTEM_NAME' => '',
      'curPlanetID' => $planetrow['id'], 'curPlanetG' => $planetrow['galaxy'],
      'curPlanetS' => $planetrow['system'], 'curPlanetP' => $planetrow['planet'],
      'curPlanetPT' => $planetrow['planet_type'],
      'userCount' => (int) SN::$config->users_amount, 'ALLY_COUNT' => 0,
      'USER_ID' => $user['id'], 'ALLY_ID' => 0,
      'planets' => $planetCount, 'PLANET_EXPEDITION' => $maxPlanet + 1,
      'SPs' => \HelperString::numberFloorAndFormat(mrc_get_level($user, $planetrow, SHIP_SPY, false, true)),
      'fleet_count' => \Fleet\DbFleetStatic::fleet_count_flying($user['id']),
      'fleet_max' => GetMaxFleets($user),
      'ACT_SPIO' => $spyCount, 'ACT_SPY' => 1,
      'ACT_WRITE' => 0, 'ACT_STATISTICS' => 0, 'ACT_INFO' => 0, 'ACT_FRIEND' => 0,
      'ACT_MISSILE' => 0, 'MIPs' => 0, 'deathStars' => 0, 'PLANET_PHALANX' => 0,
      'PLANET_RECYCLERS' => array_sum($recyclers),
      'PLANET_RECYCLERS_TEXT' => \HelperString::numberFloorAndFormat(array_sum($recyclers)),
      'opt_uni_tooltip_time' => 500, 'opt_uni_avatar_user' => 0, 'opt_uni_avatar_ally' => 0,
      'SHOW_ADMIN' => 0, 'COL_SPAN' => 8, 'COL_SPAN_PLUS' => 2,
      'COL_SPAN_NEW' => 3, 'COL_SPAN_NEW_COLONIZE' => 1,
      'PLAYER_OPTION_UNIVERSE_OLD' => SN::$user_options[PLAYER_OPTION_UNIVERSE_OLD],
      'PLAYER_OPTION_UNIVERSE_DISABLE_COLONIZE' => true,
      'UNIVERSE_SCAN_MODE' => $scan,
      'PAGE_HINT' => $lang['zero_galaxy_native_hint'],
      'ZERO_HAS_RIFTS' => !empty($metadata),
      'ZERO_RIFTS_JSON' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
      'ZERO_SCRIPT_URL' => SN_ROOT_RELATIVE . $module->getRootRelative() . 'design/js/zero_galaxy.js?v='
        . rawurlencode($module->getVersion()) . '-'
        . substr(sha1_file(SN_ROOT_PHYSICAL . $module->getRootRelative() . 'design/js/zero_galaxy.js'), 0, 12),
    ]);
    if ($scan) {
      $template_result['GLOBAL_DISPLAY_MENU'] = false;
      $template_result['GLOBAL_DISPLAY_NAVBAR'] = false;
    }

    return $template;
  }

  private static function incomingCapacity(int $system, int $userId, array $recyclerInfo): array {
    $capacity = [];
    foreach (\Fleet\DbFleetStatic::fleet_list_by_planet_coords(0, $system, 0, PT_DEBRIS) as $fleet) {
      if ((int) $fleet['fleet_owner'] !== $userId || (int) $fleet['fleet_mission'] !== MT_RECYCLE
        || (int) $fleet['fleet_mess'] !== FLEET_STATUS_FLYING || (int) $fleet['fleet_end_galaxy'] !== 0
        || (int) $fleet['fleet_end_system'] !== $system || (int) $fleet['fleet_end_type'] !== PT_DEBRIS) {
        continue;
      }
      $position = (int) $fleet['fleet_end_planet'];
      foreach (sys_unit_str2arr($fleet['fleet_array']) as $id => $count) {
        $capacity[$position] = ($capacity[$position] ?? 0) + $count * ($recyclerInfo[$id][P_CAPACITY] ?? 0);
      }
    }
    return $capacity;
  }

  /** Use native source-fleet/fuel estimates with the module's fixed distance. */
  private static function debris(array $rift, array $recyclers, $reserved, array $source): array {
    $total = $rift['debris_metal'] + $rift['debris_crystal'];
    $available = max(0, $total - $reserved);
    $source['galaxy'] = 1;
    $target = ['galaxy' => 0, 'system' => (int) $rift['system'], 'planet' => (int) $rift['position']];
    $transport = flt_calculate_fleet_to_transport($recyclers, $available, $source, $target);
    $gather = max(0, min($transport['capacity'], $available));
    $reservedPercent = min(100, floor($reserved / $total * 100));
    return [
      'DEBRIS' => $total,
      'DEBRIS_METAL' => $rift['debris_metal'], 'DEBRIS_CRYSTAL' => $rift['debris_crystal'],
      'DEBRIS_RESERVED' => $reserved, 'DEBRIS_RESERVED_PERCENT' => $reservedPercent,
      'DEBRIS_WILL_GATHER' => $gather,
      'DEBRIS_WILL_GATHER_PERCENT' => $available ? floor($gather / $available * (100 - $reservedPercent)) : 0,
      'DEBRIS_GATHER_TOTAL' => $gather + $reserved,
      'DEBRIS_GATHER_TOTAL_PERCENT' => min(100, floor(($gather + $reserved) / $total * 100)),
    ];
  }

  private static function profileMetadata(array $rift): array {
    $profile = Profile::read($rift);
    if (!$profile) {
      return [];
    }

    $category = $profile['category'] ?? null;
    $fleetLimit = $profile['fleet_limit'] ?? null;
    if (!is_array($category) || !is_string($category['name'] ?? null)
      || trim($category['name']) === '' || !is_string($category['color'] ?? null)
      || !preg_match('/^#[0-9a-fA-F]{6}$/D', $category['color'])
      || !is_int($fleetLimit) || $fleetLimit < 0) {
      return [];
    }

    return [
      'category' => ['name' => $category['name'], 'color' => $category['color']],
      // Keep all decimal digits when the configured limit exceeds JS safe integers.
      'fleetLimit' => (string) $fleetLimit,
    ];
  }

  private static function emptyPosition(int $position): array {
    return [
      'PLANET_ID' => 0, 'PLANET_NUM' => $position,
      'PLANET_NAME' => '', 'PLANET_NAME_JS' => '', 'PLANET_DESTROYED' => 0,
      'PLANET_TYPE' => PT_PLANET, 'PLANET_ACTIVITY' => 60,
      'PLANET_IMAGE' => 'object_in_space', 'PLANET_FLEET_ID' => '', 'PLANET_DIAMETER' => '0',
      'IS_CAPITAL' => 0,
      'USER_ID' => 0, 'USER_NAME' => '', 'USER_NAME_JS' => '', 'USER_RANK' => '-',
      'USER_BANNED' => 0, 'USER_VACATION' => 0, 'USER_ACTIVITY' => 0,
      'USER_ATTACKABLE' => 0, 'USER_INACTIVE' => 0, 'USER_PROTECTED' => 0,
      'USER_NOOB' => 0, 'USER_STRONG' => 0, 'USER_AUTH' => 0, 'USER_ADMIN' => '', 'USER_BIRTHDAY' => 0,
      'ALLY_ID' => 0, 'ALLY_TAG' => '',
      'MOON_NAME' => '', 'MOON_DIAMETER' => 0, 'MOON_IMAGE' => 'black_moon', 'MOON_FLEET_ID' => '',
      'DEBRIS' => 0, 'DEBRIS_METAL' => 0, 'DEBRIS_CRYSTAL' => 0,
      'DEBRIS_RESERVED' => 0, 'DEBRIS_RESERVED_PERCENT' => 0,
      'DEBRIS_WILL_GATHER' => 0, 'DEBRIS_WILL_GATHER_PERCENT' => 0,
      'DEBRIS_GATHER_TOTAL' => 0, 'DEBRIS_GATHER_TOTAL_PERCENT' => 0,
    ];
  }
}
