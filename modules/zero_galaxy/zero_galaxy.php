<?php

use Modules\sn_module;
use ZeroGalaxy\Settings;
use ZeroGalaxy\Profile;
use ZeroGalaxy\RiftFleetDispatcher;
use ZeroGalaxy\NPCStatistics;
use ZeroGalaxy\SpySender;
use ZeroGalaxy\Storage;
use ZeroGalaxy\UniverseView;

class zero_galaxy extends sn_module {
  public $versionCommitted = '';
  private $storage;
  private $settings;
  private $worldError;

  public function __construct($filename = __FILE__) {
    $this->manifest['package'] = 'universe';
    $this->manifest['name'] = 'zero_galaxy';
    $this->manifest['version'] = '0.3.0';
    $this->manifest['mvc']['model'][''] = ['callable' => 'route'];
    $this->manifest['mvc']['view']['zero_galaxy'] = ['callable' => 'render'];
    $this->manifest['mvc']['view']['zero_galaxy_spy'] = ['callable' => 'sendSpy'];
    $this->manifest['menu']['menu_zero_galaxy'] = [
      'LEVEL' => 'submenu',
      'TYPE' => 'lang',
      'ITEM' => 'zero_galaxy_title',
      'LINK' => 'galaxy.php?mode=1&galaxy=0&system=1',
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

    try {
      SN::$gc->extend('fleetDispatcher', function ($dispatcher, $gc) {
        if (get_class($dispatcher) !== \Fleet\FleetDispatcher::class) {
          error_log('Zero Galaxy: custom fleet dispatcher retained; information concealment adapter not installed');
          return $dispatcher;
        }

        return new RiftFleetDispatcher($gc);
      });
    } catch (Throwable $error) {
      // Another module may already have resolved the service. Never replace a
      // frozen/custom dispatcher behind its back or alter ordinary missions.
      error_log('Zero Galaxy dispatcher adapter: ' . $error->getMessage());
    }

    // Signed fleet workers run before the normal authenticated model hook.
    // Reconcile NPC deadlines before the native dispatcher looks up its targets.
    if (INITIAL_PAGE === 'worker' && sys_get_param_str('mode') === 'dispatchFleets'
      && SN::$gc->request->url->isSigned()) {
      $this->updateWorld(true);
    }
  }

  protected function __assign_vars() {
    return [
      'sn_mvc[pages][zero_galaxy]' => ['filename' => '', 'options' => []],
      'sn_mvc[pages][zero_galaxy_spy]' => ['filename' => '', 'options' => []],
    ];
  }

  public function route($template = null) {
    if (!SN::$sys_user_logged_in) {
      return $template;
    }
    $this->updateWorld(INITIAL_PAGE === 'zero_galaxy_spy');

    $galaxy = $_POST['galaxy'] ?? $_GET['galaxy'] ?? null;
    if (INITIAL_PAGE === 'galaxy' && is_scalar($galaxy)
      && filter_var($galaxy, FILTER_VALIDATE_INT) !== false && (int) $galaxy === 0) {
      // Finish common.php on a normal MVC request; it prepares the selected
      // planet and user options after this hook. The view reuses core templates.
      $url = SN_ROOT_VIRTUAL . 'index.php?page=zero_galaxy&system=' . $this->requestedSystem();
      if (!empty($_POST['scan']) || !empty($_GET['scan'])) {
        $url .= '&scan=1';
      }
      sys_redirect($url);
    }

    return $template;
  }

  private function updateWorld($force = false) {
    try {
      if (!$this->storage) {
        try {
          $this->settings = Settings::load($this->config);
          Profile::validateSettings($this->settings);
        } catch (InvalidArgumentException $error) {
          // A bad balance edit must not keep expired planets alive for fleets.
          error_log('Zero Galaxy configuration: ' . $error->getMessage());
          $this->settings = Settings::load(['target_active_per_system' => 0]);
        }
        $this->storage = new Storage(SN::$db->link, SN::$db->db_prefix);
      }
      $this->storage->tick(SN_TIME_NOW, $this->settings,
        max(1, (int) SN::$config->game_maxSystem), max(1, (int) SN::$config->game_maxPlanet), $force);
      NPCStatistics::sync(SN::$db->link, SN::$db->db_prefix, SN::$config);
      $this->worldError = null;
    } catch (Throwable $error) {
      $this->worldError = $error;
      error_log('Zero Galaxy: ' . $error->getMessage());
    }
  }

  private function requestedSystem() {
    $maxSystem = max(1, (int) SN::$config->game_maxSystem);
    $requested = $_POST['system'] ?? $_GET['system'] ?? 1;

    return max(1, min($maxSystem, is_scalar($requested) ? (int) $requested : 1));
  }

  public function render($template = null) {
    if ($this->worldError || !$this->storage) {
      SnTemplate::messageBox(SN::$lang['zero_galaxy_unavailable'], SN::$lang['zero_galaxy_title']);
    }

    $system = $this->requestedSystem();
    try {
      return UniverseView::render($this, $template, $this->storage->inSystem($system, SN_TIME_NOW),
        $system, SN_TIME_NOW, $this->settings, !empty($_GET['scan']));
    } catch (Throwable $error) {
      error_log('Zero Galaxy view: ' . $error->getMessage());
      SnTemplate::messageBox(SN::$lang['zero_galaxy_unavailable'], SN::$lang['zero_galaxy_title']);
    }
  }

  public function sendSpy($template = null) {
    if (!defined('IN_AJAX')) {
      define('IN_AJAX', true);
    }
    setHeader('Content-Type: text/plain; charset=utf-8');
    if ($this->worldError || !$this->storage) {
      http_response_code(503);
      die(SN::$lang['zero_galaxy_unavailable']);
    }

    die(SpySender::send($this->storage, $this->settings));
  }
}
