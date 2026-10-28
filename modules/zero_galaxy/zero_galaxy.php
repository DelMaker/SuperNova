<?php

use Modules\sn_module;

class zero_galaxy extends sn_module {
  public $versionCommitted = '';

  public function __construct($filename = __FILE__) {
    $this->manifest['package'] = 'universe';
    $this->manifest['name'] = 'zero_galaxy';
    $this->manifest['version'] = '0.1.0';
    $this->manifest['mvc']['view']['zero_galaxy'] = ['callable' => 'render'];
    $this->manifest['menu']['menu_zero_galaxy'] = [
      'LEVEL' => 'submenu',
      'TYPE' => 'lang',
      'ITEM' => 'zero_galaxy_title',
      'LINK' => 'index.php?page=zero_galaxy',
      'LOCATION' => '+menu_empire_universe',
      'ICON' => SN_ROOT_RELATIVE . 'modules/zero_galaxy/design/images/rift.svg',
    ];
    $this->manifest['i18n']['']['zero_galaxy'] = ['file' => 'zero_galaxy', 'path' => ''];

    parent::__construct($filename);
  }

  public function initialize() {
    global $sn_mvc;

    // The base module expects an entry for every registered MVC handler type.
    $this->manifest['mvc'] += array_fill_keys(array_keys($sn_mvc), []);
    parent::initialize();
  }

  protected function __assign_vars() {
    return [
      'sn_mvc[pages][zero_galaxy]' => ['filename' => '', 'options' => []],
    ];
  }

  public function render($template = null) {
    global $lang;

    $maxSystem = max(1, (int) SN::$config->game_maxSystem);
    $maxPlanet = max(1, (int) SN::$config->game_maxPlanet);
    $requestedSystem = isset($_GET['system']) && is_scalar($_GET['system']) ? (int) $_GET['system'] : 1;
    $system = max(1, min($maxSystem, $requestedSystem));

    $template = $this->addModuleTemplate('zero_galaxy', $template);
    $template->assign_vars([
      'PAGE_HEADER' => $lang['zero_galaxy_title'],
      'ZERO_SYSTEM' => $system,
      'ZERO_MAX_SYSTEM' => $maxSystem,
      'ZERO_PREVIOUS_SYSTEM' => $system > 1 ? $system - 1 : 0,
      'ZERO_NEXT_SYSTEM' => $system < $maxSystem ? $system + 1 : 0,
    ]);

    // Zero is a display coordinate only; no ordinary galaxy or planet is created.
    for ($position = 1; $position <= $maxPlanet; $position++) {
      $template->assign_block_vars('zero_position', [
        'NUMBER' => $position,
        'COORDINATES' => '0:' . $system . ':' . $position,
      ]);
    }

    return $template;
  }
}
