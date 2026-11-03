<?php

namespace {
  if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
}

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
    public function __construct($db = null, $prefix = '') {}
    public function tick(...$arguments) { self::$ticks++; }
    public function inSystem(...$arguments) { return []; }
    public function findById(...$arguments) { return ['rift_id'=>13,'system'=>10,'position'=>5]; }
  }
  class NPCStatistics { public static function sync(...$arguments) {} }
  class UniverseView {
    public static $calls = 0;
    public static function render($module, $template, ...$arguments) { self::$calls++; return $template; }
  }
  class SpySender { public static function send(...$arguments) { return 'UNSAFE_SPY_SENT'; } }
  class RecycleSender { public static function send(...$arguments) { return 'UNSAFE_RECYCLERS_SENT'; } }
}

namespace {
  define('INSIDE', true);
  define('SN_ROOT_RELATIVE', '');
  define('SN_ROOT_VIRTUAL', 'http://localhost:8080/');
  define('SN_TIME_NOW', 1700000000);
  define('PT_PLANET', 1); define('MT_ATTACK', 1); define('MT_AKS', 2);
  define('EVENT_FLT_ARRIVE', 1); define('EVENT_FLT_RETURN', 3);
  // Ordinary requests must keep working even when another module has a custom
  // dispatcher. This page also keeps initialize() away from the signed worker.
  define('INITIAL_PAGE', ($argv[1] ?? '') === '--worker-initialize' ? 'worker' : 'messages');
  $_GET = $_POST = [];
  $_SERVER['REQUEST_METHOD'] = 'GET';
  $sn_mvc = ['model' => [], 'view' => []];
  ini_set('error_log', '/dev/null');

  class SN {
    public static $gc;
    public static $db;
    public static $config;
    public static $sys_user_logged_in = true;
    public static $lang = ['zero_galaxy_unavailable' => 'MODULE_UNAVAILABLE', 'zero_galaxy_title' => 'Zero Galaxy',
      'zero_galaxy_attack_bad_request'=>'BAD_REQUEST','zero_galaxy_acs_disabled'=>'ACS_DISABLED','fl_error'=>'Fleet error'];
  }
  class DispatcherUnavailable extends \RuntimeException {}
  class DispatcherRedirect extends \RuntimeException {}
  class SnTemplate {
    public static function messageBox($message, $title) { throw new DispatcherUnavailable($message); }
  }
  function setHeader($header) {}
  function sys_redirect($url) { throw new DispatcherRedirect($url); }
  function sys_get_param_id($name, $default = 0) { return (int) ($_POST[$name] ?? $_GET[$name] ?? $default); }
  function sys_get_param_str($key) { return $key === 'mode' ? 'dispatchFleets' : ''; }
  class CustomDispatcher extends \Fleet\FleetDispatcher {}
  class DispatcherFixtureContainer {
    public $resolutions = 0;
    public $request;
    private $dispatcher;
    private $extension;
    private $frozen;
    public function __construct($dispatcher, bool $frozen) {
      $this->dispatcher = $dispatcher;
      $this->frozen = $frozen;
      $this->request = (object) ['url' => new class { public function isSigned() { return true; } }];
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

  require_once __DIR__ . '/support/autoload.php';

  require_once dirname(__DIR__) . '/classes/ZeroGalaxy/RiftFleetDispatcher.php';
  require_once dirname(__DIR__) . '/zero_galaxy.php';

  require __DIR__ . '/support/assertions.php';
  $fixture = function ($dispatcher, bool $frozen = false) {
    \ZeroGalaxy\Storage::$ticks = \ZeroGalaxy\UniverseView::$calls = 0;
    SN::$gc = new DispatcherFixtureContainer($dispatcher, $frozen);
    SN::$db = (object) ['link' => null, 'db_prefix' => 'isolated_fixture_'];
    SN::$config = (object) ['game_maxSystem' => 199, 'game_maxPlanet' => 15];
    $module = new \zero_galaxy(__FILE__);
    $module->initialize();
    return $module;
  };
  $unavailable = function (callable $callable, string $message) use ($check): void {
    try { $callable(); }
    catch (DispatcherUnavailable $error) {
      $check($error->getMessage() === 'MODULE_UNAVAILABLE', $message);
      return;
    }
    $check(false, $message);
  };

  if (in_array($argv[1] ?? '', ['--spy-endpoint', '--recycle-endpoint'], true)) {
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $module = $fixture(new CustomDispatcher());
    // Populate the world fields deliberately: an unsupported dispatcher must
    // still block the endpoint even if lifecycle state is already available.
    $reflection = new \ReflectionClass($module);
    foreach (['storage' => new \ZeroGalaxy\Storage(), 'settings' => []] as $field => $value) {
      $property = $reflection->getProperty($field);
      $property->setAccessible(true);
      $property->setValue($module, $value);
    }
    if ($argv[1] === '--recycle-endpoint') { $module->sendRecyclers(); }
    else { $module->sendSpy(); }
    throw new \RuntimeException('The unsupported mission endpoint must stop before its sender');
  }
  if (($argv[1] ?? '') === '--worker-initialize') {
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
    // Valid stored world fields cannot be used to bypass capability checks.
    $reflection = new \ReflectionClass($module);
    foreach (['storage' => new \ZeroGalaxy\Storage(), 'settings' => []] as $field => $value) {
      $property = $reflection->getProperty($field);
      $property->setAccessible(true);
      $property->setValue($module, $value);
    }
    $unavailable(function () use ($module) { $module->render(); }, 'The module overview must reject unsupported dispatchers');
    $unavailable(function () use ($module) { $module->attack(); }, 'The attack endpoint must reject unsupported dispatchers');
    $check(\ZeroGalaxy\UniverseView::$calls === 0, 'Blocked module pages must not render the overview');
  }

  $module = $fixture(new \Fleet\FleetDispatcher());
  $template = (object) ['ordinary' => true];
  $check($module->route($template) === $template && SN::$gc->fleetDispatcher instanceof \ZeroGalaxy\RiftFleetDispatcher,
    'Normal late service resolution must install the module adapter');
  $check(\ZeroGalaxy\Storage::$ticks === 1, 'Supported requests must still reconcile the module world');
  $check($module->render($template) === $template && \ZeroGalaxy\UniverseView::$calls === 1,
    'The supported overview must remain available');
  $ordinaryAcs = (object) ['event'=>EVENT_FLT_ARRIVE, 'fleet'=>['fleet_end_galaxy'=>1,'fleet_mission'=>MT_AKS]];
  SN::$gc->fleetDispatcher->events = [$ordinaryAcs];
  $check(SN::$gc->fleetDispatcher->getFleetEvents() === [$ordinaryAcs],
    'The module dispatcher must leave ordinary-galaxy ACS events with the native handler');
  $_GET = ['rift_id'=>'13'];
  try { $module->attack(); $check(false, 'An old attack link must redirect into the native fleet form'); }
  catch (DispatcherRedirect $redirect) {
    parse_str(parse_url($redirect->getMessage(), PHP_URL_QUERY), $query);
    $check(strpos($redirect->getMessage(), SN_ROOT_RELATIVE . 'fleet.php?') === 0
      && $query['galaxy'] === '0' && $query['system'] === '10' && $query['planet'] === '5'
      && $query['target_mission'] === (string) MT_ATTACK && $query['planet_type'] === '1'
      && !isset($query['acs_group']) && empty($query['fleet_group']),
      'Old rift links must retain their target in the single native fleet entry');
  }
  $_GET['acs_group'] = '77';
  try { $module->attack(); $check(false, 'An old group link must not become an attack'); }
  catch (DispatcherUnavailable $error) { $check($error->getMessage() === 'ACS_DISABLED', 'A retired group link must stop before dispatch'); }
  $_GET = ['rift_id'=>'13'];
  $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['rift_id'=>'13'];
  try { $module->attack(); $check(false, 'The retired wizard must not accept a legacy final POST'); }
  catch (DispatcherUnavailable $error) {
    $check($error->getMessage() === 'BAD_REQUEST', 'A legacy wizard POST must stop before fleet dispatch');
  }
  $_GET = $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';

  foreach (['--spy-endpoint', '--recycle-endpoint'] as $endpoint) {
    $process = proc_open([PHP_BINARY, __FILE__, $endpoint],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new \RuntimeException('Could not start endpoint refusal check'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $check(proc_close($process) === 0 && $output === 'MODULE_UNAVAILABLE' && $errors === '',
      'The real mission endpoint must stop before its sender runs: ' . $endpoint);
  }
  $process = proc_open([PHP_BINARY, __FILE__, '--worker-initialize'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($process)) { throw new \RuntimeException('Could not start worker initialization check'); }
  fclose($pipes[0]);
  $output = stream_get_contents($pipes[1]);
  $errors = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $check(proc_close($process) === 0 && $output === 'WORKER_INITIALIZED_LAZILY' && $errors === '',
    'Signed worker initialization must leave the dispatcher service available to later module extensions');
  echo "Zero Galaxy dispatcher: {$assertions} assertions passed\n";
}
