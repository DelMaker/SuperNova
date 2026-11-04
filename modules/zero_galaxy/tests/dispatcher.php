<?php

namespace Modules {
  class sn_module {
    public $manifest = [];
    protected $config = [];
    public function __construct($filename) {}
    public function initialize() {}
  }
}

namespace Fleet {
  class FleetDispatcher {
    public $events = [];
    public function __construct($gc = null) {}
    public function getFleetEvents() { return $this->events; }
  }
}

namespace ZeroGalaxy {
  class Settings { public static function load($configuration) { return []; } }
  class Profile { public static function validateSettings($settings) {} }
  class Storage {
    public static $ticks = 0;
    public static $due = false;
    public function __construct($sql = null) {}
    public function tick(...$arguments) { self::$ticks++; return self::$due; }
    public function inSystem(...$arguments) { return []; }
  }
  class NPCStatistics {
    public static $syncs = 0;
    public static function sync(...$arguments) { self::$syncs++; }
  }
  class UniverseView {
    public static $calls = 0;
    public static function render($module, $template, ...$arguments) { self::$calls++; return $template; }
  }
  class AjaxFleetSender {
    public static function send() {
      if ($GLOBALS['quickPreparation'] !== ['watchdog', 'scheduler', 'vacation', 'planet']
        || $GLOBALS['user']['current_planet'] !== 99) {
        throw new \RuntimeException('Quick missions must prepare the native request before selecting ships');
      }
      return 'QUICK_MISSION_SENT';
    }
  }
}

namespace {
  // Ordinary requests must keep working even when another module has a custom
  // dispatcher. This page also keeps initialize() away from the signed worker.
  $mode = $argv[1] ?? '';
  require __DIR__ . '/support/bootstrap.php';
  zgCoreConstants(['--worker-initialize' => 'worker', '--quick-mission' => 'flotenajax'][$mode] ?? 'messages');
  define('SN_ROOT_VIRTUAL', 'http://localhost:8080/');
  $_GET = $_POST = [];
  $_SERVER['REQUEST_METHOD'] = 'GET';
  $sn_mvc = ['model' => [], 'view' => []];
  ini_set('error_log', '/dev/null');

  class SN {
    public static $gc;
    public static $db;
    public static $config;
    public static $sys_user_logged_in = true;
    public static $lang = ['zero_galaxy_unavailable' => 'MODULE_UNAVAILABLE', 'zero_galaxy_title' => 'Zero Galaxy'];
  }
  class DispatcherUnavailable extends \RuntimeException {}
  class SnTemplate {
    public static function messageBox($message, $title) { throw new DispatcherUnavailable($message); }
  }
  function setHeader($header) {}
  function sys_redirect($url) { throw new \RuntimeException('Unexpected redirect: ' . $url); }
  function sys_get_param_str($key) { return $key === 'mode' ? 'dispatchFleets' : ''; }
  class StatUpdateLauncher {
    public static function scheduler_process() { $GLOBALS['quickPreparation'][] = 'scheduler'; }
  }
  function sys_user_vacation($user) { $GLOBALS['quickPreparation'][] = 'vacation'; }
  function SetSelectedPlanet(&$user) {
    $GLOBALS['quickPreparation'][] = 'planet';
    return $user['current_planet'] = 99;
  }
  class CustomDispatcher extends \Fleet\FleetDispatcher {}
  class DispatcherFixtureContainer {
    public $resolutions = 0;
    public $request;
    public $watchdog;
    private $dispatcher;
    private $extension;
    private $frozen;
    public function __construct($dispatcher, bool $frozen) {
      $this->dispatcher = $dispatcher;
      $this->frozen = $frozen;
      $this->request = (object) ['url' => new class { public function isSigned() { return true; } }];
      $this->watchdog = new class {
        public function execute() { $GLOBALS['quickPreparation'][] = 'watchdog'; }
      };
    }
    public function extend($service, callable $extension) {
      if ($this->frozen) { throw new \RuntimeException('The native dispatcher service is already frozen'); }
      $this->extension = $extension;
    }
    public function __get($service) {
      if ($service !== 'fleetDispatcher') { throw new \RuntimeException('Unexpected service access: ' . $service); }
      $this->resolutions++;
      if ($this->extension) {
        $extension = $this->extension;
        $this->extension = null;
        $this->dispatcher = $extension($this->dispatcher, $this);
      }
      return $this->dispatcher;
    }
  }

  require_once dirname(__DIR__) . '/zero_galaxy.php';

  $fixture = function ($dispatcher, bool $frozen = false) {
    $GLOBALS['quickPreparation'] = [];
    $GLOBALS['user'] = ['id' => 7, 'current_planet' => 11];
    \ZeroGalaxy\Storage::$ticks = \ZeroGalaxy\UniverseView::$calls = \ZeroGalaxy\NPCStatistics::$syncs = 0;
    SN::$gc = new DispatcherFixtureContainer($dispatcher, $frozen);
    SN::$db = (object) ['link' => null, 'db_prefix' => 'isolated_fixture_'];
    SN::$config = (object) ['game_maxSystem' => 199, 'game_maxPlanet' => 15];
    $module = new \zero_galaxy(__FILE__);
    $module->initialize();
    return $module;
  };
  // Valid stored world fields must not bypass the capability checks.
  $populate = function ($module) {
    $reflection = new \ReflectionClass($module);
    foreach (['storage' => new \ZeroGalaxy\Storage(), 'settings' => []] as $field => $value) {
      $property = $reflection->getProperty($field);
      $property->setAccessible(true);
      $property->setValue($module, $value);
    }
  };
  $unavailable = function (callable $callable, string $message) use ($check): void {
    $error = zgExpect($callable, DispatcherUnavailable::class, $message);
    $check($error && $error->getMessage() === 'MODULE_UNAVAILABLE', $message);
  };

  if ($mode === '--quick-mission') {
    // The native universe doit() posts every quick mission to flotenajax.php.
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_POST = ['galaxy' => $argv[3], 'system' => '1', 'planet' => '1', 'planet_type' => '1', 'mission' => '6'];
    $module = $fixture($argv[2] === 'custom' ? new CustomDispatcher() : new \Fleet\FleetDispatcher());
    $populate($module);
    register_shutdown_function(function () use ($argv): void {
      $expected = $argv[2] === 'native' && $argv[3] === '0' ? ['watchdog', 'scheduler', 'vacation', 'planet'] : [];
      if ($GLOBALS['quickPreparation'] !== $expected) {
        throw new \RuntimeException('Native request preparation must run once only for supported galaxy 0 quick missions');
      }
    });
    $template = (object) ['ordinary' => true];
    echo $module->route($template) === $template ? 'NATIVE' : 'UNEXPECTED';
    exit;
  }
  if ($mode === '--worker-initialize') {
    $module = $fixture(new \Fleet\FleetDispatcher());
    $check(SN::$gc->resolutions === 0 && \ZeroGalaxy\Storage::$ticks === 1,
      'Signed worker initialization must reconcile deadlines without resolving the dispatcher before later modules initialize');
    echo "WORKER_INITIALIZED_LAZILY";
    exit;
  }

  foreach ([[new CustomDispatcher(), false], [new \Fleet\FleetDispatcher(), true]] as [$dispatcher, $frozen]) {
    $module = $fixture($dispatcher, $frozen);
    $check(SN::$gc->resolutions === 0, 'Module initialization must not resolve the dispatcher before later modules initialize');
    $ordinaryTemplate = (object) ['ordinary' => true];
    $check($module->route($ordinaryTemplate) === $ordinaryTemplate, 'Ordinary pages must return their own template after module refusal');
    $check(SN::$gc->fleetDispatcher === $dispatcher,
      'Unsupported/frozen custom dispatchers and ordinary native missions must stay untouched');
    $check(\ZeroGalaxy\Storage::$ticks === 0, 'Unsupported routes must not initialize or mutate module state');
    $populate($module);
    $unavailable(function () use ($module) { $module->render(); }, 'The module overview must reject unsupported dispatchers');
    $check(\ZeroGalaxy\UniverseView::$calls === 0, 'Blocked module pages must not render the overview');
  }

  $module = $fixture(new \Fleet\FleetDispatcher());
  $check(array_keys($module->manifest['mvc']['view']) === ['zero_galaxy', ''],
    'Quick missions use the native endpoint, so the module registers only its overview page');
  $template = (object) ['ordinary' => true];
  $check($module->route($template) === $template && SN::$gc->fleetDispatcher instanceof \ZeroGalaxy\RiftFleetDispatcher,
    'Normal late service resolution must install the module adapter');
  $check(\ZeroGalaxy\Storage::$ticks === 1 && \ZeroGalaxy\NPCStatistics::$syncs === 0,
    'A request must check the world schedule without synchronizing rankings when nothing was due');
  \ZeroGalaxy\Storage::$due = true;
  $module->route($template);
  $check(\ZeroGalaxy\Storage::$ticks === 2 && \ZeroGalaxy\NPCStatistics::$syncs === 1,
    'Only a reconciliation that ran may change NPCs, so only it synchronizes rankings');
  \ZeroGalaxy\Storage::$due = false;
  $check($module->render($template) === $template && \ZeroGalaxy\UniverseView::$calls === 1,
    'The supported overview must remain available');
  $ordinaryAcs = (object) ['event'=>EVENT_FLT_ARRIVE, 'fleet'=>['fleet_end_galaxy'=>1,'fleet_mission'=>MT_AKS]];
  SN::$gc->fleetDispatcher->events = [$ordinaryAcs];
  $check(SN::$gc->fleetDispatcher->getFleetEvents() === [$ordinaryAcs],
    'The module dispatcher must leave ordinary-galaxy ACS events with the native handler');

  foreach ([['custom', '0', 'MODULE_UNAVAILABLE', 'An unsupported dispatcher must stop a quick mission before its sender'],
    ['native', '0', 'QUICK_MISSION_SENT', 'A galaxy 0 quick mission must reach the module sender instead of flotenajax.php'],
    ['native', '1', 'NATIVE', 'Ordinary galaxies must keep the native flotenajax.php handler']] as [$kind, $galaxy, $expected, $message]) {
    [$code, $output, $errors] = zgFinish(zgWorker(['--quick-mission', $kind, $galaxy]));
    $check($code === 0 && $output === $expected && $errors === '', $message . ': ' . $output . $errors);
  }
  [$code, $output, $errors] = zgFinish(zgWorker(['--worker-initialize']));
  $check($code === 0 && $output === 'WORKER_INITIALIZED_LAZILY' && $errors === '',
    'Signed worker initialization must leave the dispatcher service available to later module extensions');
  echo "Zero Galaxy dispatcher: {$assertions} assertions passed\n";
}
