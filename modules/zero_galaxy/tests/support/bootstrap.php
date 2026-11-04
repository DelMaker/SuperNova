<?php

// Shared by every PHP check: CLI guard, module autoloader, $check() and small fixtures.
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

const ZG_TEST_NOW = 1700000000;
const ZG_TEST_SKIP = 77;

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/assertions.php';

/** Real core constants without the game bootstrap; $page becomes INITIAL_PAGE. */
function zgCoreConstants(string $page = 'zero_galaxy_test'): void {
  defined('INSIDE') || define('INSIDE', true);
  defined('SN_ROOT_PHYSICAL') || define('SN_ROOT_PHYSICAL', dirname(__DIR__, 4) . '/');
  defined('SN_ROOT_RELATIVE') || define('SN_ROOT_RELATIVE', '');
  defined('SN_TIME_MICRO') || define('SN_TIME_MICRO', (float) ZG_TEST_NOW);
  $_GET['page'] = $page;
  require_once SN_ROOT_PHYSICAL . 'includes/constants/constants.php';
  unset($_GET['page']);
}

function zgSkip(string $reason): void {
  echo "SKIP: {$reason}\n";
  exit(ZG_TEST_SKIP);
}

function zgDb(bool $serializable = false): mysqli {
  if (getenv('ZG_TEST_NO_DB')) { zgSkip('database checks are disabled'); }
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  $db = new mysqli(getenv('SUPERNOVA_DB_HOST') ?: 'db', getenv('SUPERNOVA_DB_USER') ?: 'supernova',
    getenv('SUPERNOVA_DB_PASSWORD') ?: 'supernova-local', getenv('SUPERNOVA_DB_NAME') ?: 'supernova');
  $db->set_charset('utf8');
  if ($serializable) {
    $db->query('SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    $db->query('SET SESSION innodb_lock_wait_timeout = 3');
  }
  return $db;
}

/** Prepares an anonymous request for checks that then require the real common.php. */
function zgGameRequest(string $name): void {
  if (getenv('ZG_TEST_NO_DB') || getenv('SUPERNOVA_DB_HOST') !== 'db' || getenv('SUPERNOVA_DB_NAME') !== 'supernova'
    || !is_file('/var/lib/supernova/cookie-secret')) {
    zgSkip('requires the local SuperNova Docker environment');
  }
  chdir(dirname(__DIR__, 4));
  $_SERVER = array_replace($_SERVER, [
    'SCRIPT_NAME' => "/{$name}.php", 'SCRIPT_FILENAME' => getcwd() . "/{$name}.php",
    'HTTP_HOST' => 'localhost:8080', 'SERVER_NAME' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1',
    'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/{$name}.php", 'HTTP_USER_AGENT' => 'Zero Galaxy test',
  ]);
  $_GET = $_POST = $_COOKIE = $_REQUEST = [];
  $GLOBALS['allow_anonymous'] = true;
  define('IN_AJAX', true);
}

/** Expects $class from $operation and returns it for message checks. */
function zgExpect(callable $operation, string $class, string $message): ?Throwable {
  global $check;
  try {
    $operation();
  } catch (Throwable $error) {
    if (!$error instanceof $class) { throw $error; }
    $check(true, $message);
    return $error;
  }
  $check(false, $message);
  return null;
}

/** A complete saved rift profile; checks override only the fields they exercise. */
function zgProfile(array $overrides = []): array {
  return array_replace_recursive(['version' => 1,
    'category' => ['key' => 'tiny', 'name' => 'Крошечный', 'color' => '#ADFF2F'],
    'target_points' => 100, 'garrison_points' => 100, 'fleet_points' => 60, 'defence_points' => 40,
    'fleet_limit' => 125, 'fleet' => [], 'defence' => [], 'resources' => [901 => 0, 902 => 0, 903 => 0]], $overrides);
}

function zgSnapshot(array $overrides = []): string {
  return json_encode(['profile' => zgProfile($overrides)], JSON_UNESCAPED_UNICODE);
}

function zgLanguage(string $locale): array {
  $a_lang_array = [];
  include dirname(__DIR__, 2) . "/language/{$locale}/zero_galaxy.mo.php";
  return $a_lang_array;
}

/** Runs this script again with $arguments, e.g. as a competing locking worker. */
function zgWorker(array $arguments): array {
  $process = proc_open(array_merge([PHP_BINARY, get_included_files()[0]], $arguments),
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($process)) { throw new RuntimeException('Could not start a test worker'); }
  return [$process, $pipes];
}

/** @return array [exit code, stdout, stderr] */
function zgFinish(array $worker): array {
  [$process, $pipes] = $worker;
  if (is_resource($pipes[0])) { fclose($pipes[0]); }
  $output = stream_get_contents($pipes[1]);
  $errors = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  return [proc_close($process), $output, $errors];
}
