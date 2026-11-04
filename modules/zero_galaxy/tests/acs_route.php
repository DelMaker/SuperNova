<?php

namespace Modules {
  class sn_module {
    public $manifest = [];
    protected $config = [];
    public function __construct($filename) {}
  }
}

namespace {
  require __DIR__ . '/support/bootstrap.php';
  zgCoreConstants('fleet');
  define('SN_ROOT_VIRTUAL', 'http://localhost:8080/');
  require SN_ROOT_PHYSICAL . 'includes/general/general_params.php';
  require SN_ROOT_PHYSICAL . 'includes/general/general_validators.php';
  require dirname(__DIR__) . '/zero_galaxy.php';

  class SN {
    public static $db;
    public static $lang = ['zero_galaxy_acs_disabled'=>'ACS_DISABLED','zero_galaxy_title'=>'Zero Galaxy'];
  }
  class AcsRouteBlocked extends \RuntimeException {}
  class SnTemplate {
    public static function messageBox($message, $title) { throw new AcsRouteBlocked($message); }
  }
  class AcsRouteFixtureDb {
    public $queries = [];
    public function doquery($sql, $single = false) {
      $this->queries[] = $sql;
      if (!preg_match('/^SELECT fleet_end_galaxy FROM \{\{fleets\}\} WHERE fleet_id=([0-9]+)$/D', $sql, $match)) {
        throw new \RuntimeException('Unexpected query before ACS admission: ' . $sql);
      }
      return [22=>['fleet_end_galaxy'=>'0'],23=>['fleet_end_galaxy'=>'4']][(int) $match[1]] ?? null;
    }
  }
  $route = new \ReflectionMethod(zero_galaxy::class, 'routeNativeAcs'); $route->setAccessible(true);
  $run = function (array $get, array $post = []) use ($route): array {
    $_GET = $get; $_POST = $post;
    SN::$db = new AcsRouteFixtureDb();
    $reporting = error_reporting(E_ALL & ~E_NOTICE);
    try { $route->invoke(new zero_galaxy()); return [null,SN::$db->queries]; }
    catch (AcsRouteBlocked $error) { return [$error->getMessage(),SN::$db->queries]; }
    finally { error_reporting($reporting); }
  };
  foreach ([
    [['fleet_page'=>'4','fleetid'=>'22'],[]],
    [['mode'=>'4','fleetid'=>'00022'],[]],
    [['fleet_page'=>'4abc','fleetid'=>'22'],[]],
    [[],['fleet_page'=>'4','fleetid'=>'22','addtogroup'=>'Pilot']],
    [['fleet_page'=>'4','fleetid'=>'23'],['fleetid'=>'22']],
  ] as [$get,$post]) {
    [$error,$queries] = $run($get,$post);
    $check($error === 'ACS_DISABLED' && count($queries) === 1 && strpos($queries[0], 'fleet_id=22') !== false,
      'Native create/invite aliases must reject rift fleets before mutation');
  }
  foreach ([
    [['fleet_page'=>'4','fleetid'=>'23'],[]],
    [['fleet_page'=>'4','fleetid'=>'22'],['fleetid'=>'23']],
    [['fleet_page'=>'1','mode'=>'4','fleetid'=>'22'],[]],
  ] as [$get,$post]) {
    [$error] = $run($get,$post);
    $check($error === null, 'Ordinary ACS pages and other fleet steps must retain native handling');
  }

  $configuration = new \ReflectionProperty(\Modules\sn_module::class, 'config'); $configuration->setAccessible(true);
  $module = new zero_galaxy();
  $configuration->setValue($module, ['categories'=>['custom'=>\ZeroGalaxy\Settings::load()['categories']['tiny']], 'typo'=>true]);
  $effective = new \ReflectionMethod(zero_galaxy::class, 'effectiveSettings'); $effective->setAccessible(true);
  $update = new \ReflectionMethod(zero_galaxy::class, 'updateWorld'); $update->setAccessible(true);
  $storage = new \ReflectionProperty(zero_galaxy::class, 'storage'); $storage->setAccessible(true);
  $worldError = new \ReflectionProperty(zero_galaxy::class, 'worldError'); $worldError->setAccessible(true);
  SN::$db = new AcsRouteFixtureDb();
  $oldLog = ini_set('error_log', '/dev/null');
  try { $fallback = $effective->invoke($module); $update->invoke($module, false); }
  finally { ini_set('error_log', $oldLog); }
  $check(!isset($fallback['categories']['custom']) && $storage->getValue($module) === null
    && $worldError->getValue($module) instanceof \InvalidArgumentException && SN::$db->queries === [],
    'A configuration typo must stop world updates before fallback defaults can retire custom categories');
  echo "ACS routing: {$assertions} assertions passed; native parser, no database or game bootstrap.\n";
}
