<?php

use Modules\sn_module;
use ZeroGalaxy\AcsView;
use ZeroGalaxy\AdminView;
use ZeroGalaxy\AjaxFleetSender;
use ZeroGalaxy\Input;
use ZeroGalaxy\NativeFleetForm;
use ZeroGalaxy\NPCStatistics;
use ZeroGalaxy\Profile;
use ZeroGalaxy\RiftFleetDispatcher;
use ZeroGalaxy\Settings;
use ZeroGalaxy\Sql;
use ZeroGalaxy\Storage;
use ZeroGalaxy\UniverseView;

class zero_galaxy extends sn_module {
  public $versionCommitted = '';
  private $storage;
  private $settings;
  private $settingsError;
  private $worldError;

  public function __construct($filename = __FILE__) {
    $this->manifest['package'] = 'universe';
    $this->manifest['name'] = 'zero_galaxy';
    $this->manifest['version'] = '0.8.0';
    $this->manifest['mvc']['model'][''] = ['callable' => 'route'];
    $this->manifest['mvc']['view']['zero_galaxy'] = ['callable' => 'render'];
    $this->manifest['mvc']['view'][''] = ['callable' => 'decorateView'];
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

    require_once __DIR__ . '/captain_compat.php';

    // The base module expects an entry for every registered MVC handler type.
    $this->manifest['mvc'] += array_fill_keys(array_keys($sn_mvc), []);
    parent::initialize();

    try {
      SN::$gc->extend('fleetDispatcher', function ($dispatcher, $gc) {
        if (get_class($dispatcher) !== \Fleet\FleetDispatcher::class) {
          error_log('Zero Galaxy: custom fleet dispatcher retained; module arrival adapter not installed');
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
    // Reconcile due NPC deadlines before the native dispatcher looks up its targets.
    if (INITIAL_PAGE === 'worker' && sys_get_param_str('mode') === 'dispatchFleets'
      && SN::$gc->request->url->isSigned()) {
      // Other modules have not finished initialization yet. Reconcile deadlines
      // here, but resolve the dispatcher only on later routes/views.
      $this->updateWorld(false);
    }
  }

  protected function __assign_vars() {
    return [
      'sn_mvc[pages][zero_galaxy]' => ['filename' => '', 'options' => []],
    ];
  }

  public function route($template = null) {
    if (!SN::$sys_user_logged_in) {
      return $template;
    }
    $this->routeNativeAcs();
    NativeFleetForm::intercept();
    $this->updateWorld();
    if (INITIAL_PAGE === 'flotenajax' && Input::int($_POST['galaxy'] ?? null) === 0) {
      $this->sendQuickMission();
    }

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

  private function requireDispatcher(): void {
    if (!(SN::$gc->fleetDispatcher instanceof RiftFleetDispatcher)) {
      throw new RuntimeException('Zero Galaxy requires its fleet arrival adapter; module actions are unavailable');
    }
  }

  private function effectiveSettings(): array {
    if ($this->settings === null) {
      try { $this->settings = Settings::load($this->config); }
      catch (InvalidArgumentException $error) {
        error_log('Zero Galaxy configuration: ' . $error->getMessage());
        $this->settingsError = $error;
        $this->settings = Settings::load(['target_active_per_system' => 0]);
      }
    }
    return $this->settings;
  }

  /** Block native group creation for zero-galaxy fleets before its mutations. */
  private function routeNativeAcs(): void {
    if (INITIAL_PAGE !== 'fleet') { return; }
    // Match the native parser, including mode=4 and zero-prefixed IDs. A
    // stricter parser here would let native-supported aliases bypass policy.
    $fleetId = (int) sys_get_param_id('fleetid');
    $page = (int) sys_get_param_int('fleet_page', sys_get_param_int('mode'));
    if ($page === 4 && $fleetId > 0) {
      $fleet = SN::$db->doquery('SELECT fleet_end_galaxy FROM {{fleets}} WHERE fleet_id=' . $fleetId, true);
      if ($fleet && (int) $fleet['fleet_end_galaxy'] === 0) {
        SnTemplate::messageBox(SN::$lang['zero_galaxy_acs_disabled'], SN::$lang['zero_galaxy_title']);
      }
    }
  }

  private function worldReady(): bool {
    try {
      $this->requireDispatcher();
    } catch (Throwable $error) {
      $this->worldError = $error;
    }
    return !$this->worldError && $this->storage !== null;
  }

  private function updateWorld(bool $checkDispatcher = true): void {
    try {
      if ($checkDispatcher) {
        $this->requireDispatcher();
      }
      if (!$this->storage) {
        $this->settings = $this->effectiveSettings();
        // A fallback must never remove categories missing from the defaults.
        if ($this->settingsError) { throw $this->settingsError; }
        Profile::validateSettings($this->settings);
        $this->storage = new Storage(Sql::fromGame());
      }
      // Only a reconciliation can create or retire NPCs, so only it needs the ranking sync.
      if ($this->storage->tick(SN_TIME_NOW, $this->settings,
        max(1, (int) SN::$config->game_maxSystem), max(1, (int) SN::$config->game_maxPlanet))) {
        NPCStatistics::sync(Sql::fromGame(), SN::$config);
      }
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
    if (!$this->worldReady()) {
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

  /** The native universe doit() posts galaxy 0 to flotenajax.php, whose own checks reject it. */
  private function sendQuickMission(): void {
    global $user;
    if (!defined('IN_AJAX')) {
      define('IN_AJAX', true);
    }
    setHeader('Content-Type: text/plain; charset=utf-8');
    if (!$this->worldReady()) {
      http_response_code(503);
      die(SN::$lang['zero_galaxy_unavailable']);
    }
    // This early response replaces the rest of init.php and common.php.
    SN::$gc->watchdog->execute();
    StatUpdateLauncher::scheduler_process();
    sys_user_vacation($user);
    SetSelectedPlanet($user);
    die(AjaxFleetSender::send());
  }

  public function decorateView($template = null) {
    if (AdminView::appliesTo($template)) {
      try {
        AdminView::filter($template, Sql::fromGame());
      } catch (Throwable $error) {
        // A display filter must not make the native administration unavailable.
        error_log('Zero Galaxy admin list: ' . $error->getMessage());
      }
    }
    $template = AcsView::decorate($this, $template);
    return NativeFleetForm::decorate($this, $template, $this->worldError ? null : $this->storage);
  }
}
