<?php

namespace {
  if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
}

namespace Modules {
  class sn_module {
    public $manifest = [];
    protected $config = [];
    public function __construct($filename) {}
  }
}

namespace {
  define('INSIDE', true);
  define('INITIAL_PAGE', 'fleet');
  define('SN_ROOT_RELATIVE', '/');
  define('SN_ROOT_VIRTUAL', 'http://localhost:8080/');
  define('SN_TIME_NOW', 1700000000);
  require_once dirname(__DIR__, 3) . '/includes/general/general_params.php';
  require_once dirname(__DIR__, 3) . '/includes/general/general_validators.php';
  require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Settings.php';
  require_once dirname(__DIR__) . '/classes/ZeroGalaxy/AcsPolicy.php';
  require_once dirname(__DIR__) . '/zero_galaxy.php';

  class SN {
    public static $db;
    public static $lang = ['zero_galaxy_acs_disabled' => 'ACS_DISABLED', 'zero_galaxy_title' => 'Zero Galaxy'];
  }
  class AcsRouteBlocked extends \RuntimeException {}
  class AcsRouteRedirect extends \RuntimeException {}
  class SnTemplate {
    public static function messageBox($message, $title) { throw new AcsRouteBlocked($message); }
  }
  function sys_redirect($url) { throw new AcsRouteRedirect($url); }

  class AcsRouteFixtureDb {
    public $queries = [];
    public function doquery($sql, $single = false) {
      $this->queries[] = $sql;
      if (preg_match('/^SELECT fleet_end_galaxy FROM \{\{fleets\}\} WHERE fleet_id=([0-9]+)$/D', $sql, $match)) {
        $rows = [22 => ['fleet_end_galaxy' => '0'], 23 => ['fleet_end_galaxy' => '4']];
        return $rows[(int) $match[1]] ?? null;
      }
      if (preg_match('/^SELECT galaxy,system,planet FROM \{\{aks\}\} WHERE id=([0-9]+)$/D', $sql, $match)) {
        $rows = [77 => ['galaxy'=>'0','system'=>'10','planet'=>'5'],
          78 => ['galaxy'=>'4','system'=>'10','planet'=>'5']];
        return $rows[(int) $match[1]] ?? null;
      }
      throw new \RuntimeException('Unexpected query or mutation before ACS route admission: ' . $sql);
    }
  }

  $assertions = 0;
  $check = function ($condition, string $message) use (&$assertions): void {
    $assertions++; if (!$condition) { throw new \RuntimeException($message); }
  };
  $route = new \ReflectionMethod(zero_galaxy::class, 'routeNativeAcs');
  $route->setAccessible(true);
  $configuration = new \ReflectionProperty(\Modules\sn_module::class, 'config');
  $configuration->setAccessible(true);
  $settings = new \ReflectionProperty(zero_galaxy::class, 'settings');
  $settings->setAccessible(true);
  $run = function (array $get, array $post = [], array $config = ['acs_enabled'=>false])
    use ($route, $configuration, $settings): array {
    $_GET = $get; $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    SN::$db = new AcsRouteFixtureDb();
    $module = new zero_galaxy(); $configuration->setValue($module, $config);
    // The native pre-PHP-8 parser intentionally reads absent POST/GET keys.
    // Leave its real precedence/coercion intact without printing those notices.
    $reporting = error_reporting(E_ALL & ~E_NOTICE);
    $outcome = 'native'; $message = null;
    try { $route->invoke($module); }
    catch (AcsRouteBlocked $error) { $outcome = 'blocked'; $message = $error->getMessage(); }
    catch (AcsRouteRedirect $error) { $outcome = 'redirect'; $message = $error->getMessage(); }
    finally { error_reporting($reporting); }
    return ['outcome'=>$outcome, 'message'=>$message, 'queries'=>SN::$db->queries,
      'settings'=>$settings->getValue($module)];
  };

  // These aliases are all accepted by fleet.php's real native parser. None
  // may reach the native create/invite handler while rift ACS is disabled.
  foreach ([
    'native page' => [['fleet_page'=>'4','fleetid'=>'22'], []],
    'legacy mode' => [['mode'=>'4','fleetid'=>'22'], []],
    'zero-prefixed page' => [['fleet_page'=>'04','fleetid'=>'22'], []],
    'native integer suffix' => [['fleet_page'=>'4abc','fleetid'=>'22'], []],
    'native decimal coercion' => [['fleet_page'=>'4.1','fleetid'=>'22'], []],
    'zero-prefixed fleet ID' => [['fleet_page'=>'4','fleetid'=>'00022'], []],
    'off falls back to mode' => [['fleet_page'=>'off','mode'=>'4','fleetid'=>'22'], []],
    'POST page' => [[], ['fleet_page'=>'4','fleetid'=>'22','addtogroup'=>'Fixture Player']],
    'POST legacy mode' => [[], ['mode'=>'4','fleetid'=>'22','addtogroup'=>'Fixture Player']],
    'POST page wins over GET' => [['fleet_page'=>'1'], ['fleet_page'=>'4','fleetid'=>'22']],
    'POST target wins over GET' => [['fleet_page'=>'4','fleetid'=>'23'], ['fleetid'=>'22']],
    'POST mode wins over GET' => [['mode'=>'1','fleetid'=>'22'], ['mode'=>'4']],
    'POST off uses native mode default' => [['mode'=>'4','fleetid'=>'22'], ['fleet_page'=>'off']],
    'GET target survives absent POST' => [['fleet_page'=>'4','fleetid'=>'22'], ['addtogroup'=>'Fixture Player']],
    'null POST target uses GET fallback' => [['fleet_page'=>'4','fleetid'=>'22'], ['fleetid'=>null]],
  ] as $label => [$get, $post]) {
    $result = $run($get, $post);
    $check($result['outcome'] === 'blocked' && $result['message'] === 'ACS_DISABLED',
      'A disabled rift create/invite must stop before native mutation: ' . $label);
    $check(count($result['queries']) === 1 && strpos($result['queries'][0], 'fleet_id=22') !== false,
      'The same native zero fleet must be resolved without mutation: ' . $label);
  }

  foreach ([
    'normal galaxy' => [['fleet_page'=>'4','fleetid'=>'23'], []],
    'normal galaxy legacy mode' => [['mode'=>'4','fleetid'=>'23'], []],
    'POST ordinary target overrides zero GET' => [['fleet_page'=>'4','fleetid'=>'22'], ['fleetid'=>'23']],
    'POST non-ACS page overrides GET' => [['fleet_page'=>'4','fleetid'=>'22'], ['fleet_page'=>'1']],
    'POST non-ACS mode overrides GET' => [['mode'=>'4','fleetid'=>'22'], ['mode'=>'1']],
    'explicit page overrides mode' => [['fleet_page'=>'1','mode'=>'4','fleetid'=>'22'], []],
    'missing fleet' => [['fleet_page'=>'4','fleetid'=>'404'], []],
    'ordinary group' => [['fleet_group'=>'78'], []],
    'POST ordinary group overrides zero GET' => [['fleet_group'=>'77'], ['fleet_group'=>'78']],
  ] as $label => [$get, $post]) {
    $result = $run($get, $post);
    $check($result['outcome'] === 'native' && $result['message'] === null,
      'Native ordinary pages/targets must retain their handler: ' . $label);
  }

  foreach ([
    'GET join' => [['fleet_group'=>'77'], []],
    'zero-prefixed join' => [['fleet_group'=>'00077'], []],
    'POST join' => [[], ['fleet_group'=>'77']],
    'POST zero group overrides ordinary GET' => [['fleet_group'=>'78'], ['fleet_group'=>'77']],
  ] as $label => [$get, $post]) {
    $result = $run($get, $post);
    $check($result['outcome'] === 'blocked' && $result['message'] === 'ACS_DISABLED',
      'Disabled rift groups must never enter native join: ' . $label);
    $check(count($result['queries']) === 1 && strpos($result['queries'][0], 'WHERE id=77') !== false,
      'Joining must be rejected before world mutation or dispatch: ' . $label);
  }

  foreach ([['fleet_page'=>'4','fleetid'=>'22'], ['mode'=>'4','fleetid'=>'00022'],
    ['fleet_page'=>'04','fleetid'=>'22'], ['fleet_page'=>'4abc','fleetid'=>'22']] as $get) {
    $result = $run($get, [], ['acs_enabled'=>true]);
    $check($result['outcome'] === 'redirect' && $result['message'] === SN_ROOT_VIRTUAL
      . 'index.php?page=zero_galaxy_acs&fleetid=22',
      'Enabled rift ACS must use its validated module form, including native aliases');
  }
  $result = $run(['mode'=>'4','fleetid'=>'23'], [], ['acs_enabled'=>true]);
  $check($result['outcome'] === 'native', 'Enabled rift ACS must not redirect native ordinary-galaxy forms');

  $oldLog = ini_set('error_log', '/dev/null');
  try { $result = $run(['mode'=>'4','fleetid'=>'00022'], [], ['acs_enabled'=>'false']); }
  finally { ini_set('error_log', $oldLog); }
  $check($result['outcome'] === 'blocked' && $result['message'] === 'ACS_DISABLED',
    'Malformed configuration must fail closed before native ACS creation');
  $check($result['settings']['acs_enabled'] === false && $result['settings']['target_active_per_system'] === 0.0,
    'Invalid configuration fallback must disable ACS and births while retaining cleanup settings');

  echo "ACS routing: {$assertions} assertions passed; real native parameter parser and module hook, no database or game bootstrap.\n";
}
