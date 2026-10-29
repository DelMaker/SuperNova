<?php

use Modules\sn_module;
use ZeroGalaxy\Settings;
use ZeroGalaxy\Profile;
use ZeroGalaxy\RiftFleetDispatcher;
use ZeroGalaxy\NPCStatistics;
use ZeroGalaxy\SpySender;
use ZeroGalaxy\RecycleSender;
use ZeroGalaxy\Storage;
use ZeroGalaxy\UniverseView;
use ZeroGalaxy\AttackView;
use ZeroGalaxy\AttackLink;
use ZeroGalaxy\AttackPolicy;
use ZeroGalaxy\AdminView;

class zero_galaxy extends sn_module {
  public $versionCommitted = '';
  private $storage;
  private $settings;
  private $worldError;

  public function __construct($filename = __FILE__) {
    $this->manifest['package'] = 'universe';
    $this->manifest['name'] = 'zero_galaxy';
    $this->manifest['version'] = '0.5.0';
    $this->manifest['mvc']['model'][''] = ['callable' => 'route'];
    $this->manifest['mvc']['view']['zero_galaxy'] = ['callable' => 'render'];
    $this->manifest['mvc']['view']['zero_galaxy_spy'] = ['callable' => 'sendSpy'];
    $this->manifest['mvc']['view']['zero_galaxy_recycle'] = ['callable' => 'sendRecyclers'];
    $this->manifest['mvc']['view']['zero_galaxy_attack'] = ['callable' => 'attack'];
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
    // Reconcile NPC deadlines before the native dispatcher looks up its targets.
    if (INITIAL_PAGE === 'worker' && sys_get_param_str('mode') === 'dispatchFleets'
      && SN::$gc->request->url->isSigned()) {
      // Other modules have not finished initialization yet. Reconcile deadlines
      // here, but resolve the dispatcher only on later routes/views.
      $this->updateWorld(true, false);
    }
  }

  protected function __assign_vars() {
    return [
      'sn_mvc[pages][zero_galaxy]' => ['filename' => '', 'options' => []],
      'sn_mvc[pages][zero_galaxy_spy]' => ['filename' => '', 'options' => []],
      'sn_mvc[pages][zero_galaxy_recycle]' => ['filename' => '', 'options' => []],
      'sn_mvc[pages][zero_galaxy_attack]' => ['filename' => '', 'options' => []],
    ];
  }

  public function route($template = null) {
    if (!SN::$sys_user_logged_in) {
      return $template;
    }
    $attackCoordinates = AttackLink::nativeCoordinates(INITIAL_PAGE, $_SERVER['REQUEST_METHOD'] ?? '', $_GET,
      max(1, (int) SN::$config->game_maxSystem), max(1, (int) SN::$config->game_maxPlanet));
    $this->updateWorld($attackCoordinates !== null
      || in_array(INITIAL_PAGE, ['zero_galaxy_spy', 'zero_galaxy_attack', 'zero_galaxy_recycle'], true));

    if ($attackCoordinates !== null) {
      // Old spy reports contain coordinates, not a rift generation. Resolve
      // the current target; the wizard then validates its own profile and cap.
      $rift = null;
      try {
        if (!$this->worldError && $this->storage) {
          $rift = $this->storage->findActive($attackCoordinates['system'], $attackCoordinates['position'], SN_TIME_NOW);
        }
      } catch (Throwable $error) {
        error_log('Zero Galaxy attack link: ' . $error->getMessage());
      }
      $riftId = $rift && AttackPolicy::limit($rift) !== null ? (int) $rift['rift_id'] : 0;
      sys_redirect(SN_ROOT_VIRTUAL . 'index.php?page=zero_galaxy_attack&rift_id=' . $riftId);
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

  private function worldReady(): bool {
    try {
      $this->requireDispatcher();
    } catch (Throwable $error) {
      $this->worldError = $error;
    }
    return !$this->worldError && $this->storage !== null;
  }

  private function updateWorld($force = false, $checkDispatcher = true) {
    try {
      if ($checkDispatcher) {
        $this->requireDispatcher();
      }
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

  public function sendSpy($template = null) {
    if (!defined('IN_AJAX')) {
      define('IN_AJAX', true);
    }
    setHeader('Content-Type: text/plain; charset=utf-8');
    if (!$this->worldReady()) {
      http_response_code(503);
      die(SN::$lang['zero_galaxy_unavailable']);
    }

    die(SpySender::send($this->storage, $this->settings));
  }

  public function attack($template = null) {
    if (!$this->worldReady()) {
      SnTemplate::messageBox(SN::$lang['zero_galaxy_unavailable'], SN::$lang['zero_galaxy_title']);
    }
    AttackView::render($this, $this->storage, $this->settings);
    return $template;
  }

  public function sendRecyclers($template = null) {
    if (!defined('IN_AJAX')) {
      define('IN_AJAX', true);
    }
    setHeader('Content-Type: text/plain; charset=utf-8');
    if (!$this->worldReady()) {
      http_response_code(503);
      die(SN::$lang['zero_galaxy_unavailable']);
    }

    die(RecycleSender::send($this->storage, $this->settings));
  }

  public function decorateView($template = null) {
    if (AdminView::appliesTo($template)) {
      try {
        AdminView::filter($template, SN::$db->link, SN::$db->db_prefix);
      } catch (Throwable $error) {
        // A display filter must not make the native administration unavailable.
        error_log('Zero Galaxy admin list: ' . $error->getMessage());
      }
    }
    return $this->decorateAttack($template);
  }

  public function decorateAttack($template = null) {
    if (INITIAL_PAGE !== 'zero_galaxy_attack' || !is_object($template)
      || !isset($template->_rootref['ZERO_ATTACK_STEP']) || !empty($template->_rootref['ZERO_ATTACK_WRAPPED'])
      || !array_intersect(array_keys($template->files), ['fleet0', 'fleet1', 'fleet2', 'fleet3'])) {
      return $template;
    }
    AttackView::escapeFleetNames($template);
    $step = (int) $template->_rootref['ZERO_ATTACK_STEP'];
    $template->_rootref['ZERO_ATTACK_WRAPPED'] = true;
    // Replace the native root handle; the wrapper includes that native file.
    $template->files = $template->filename = $template->files_inherit = [];
    return $this->addModuleTemplate('zero_galaxy_attack_' . $step, $template);
  }
}
