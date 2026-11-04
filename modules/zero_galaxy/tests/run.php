<?php

// Runs every PHP check in its own process; exit code 77 marks a skipped check.
// Inside the web container: php modules/zero_galaxy/tests/run.php [--no-db]
// JavaScript checks run with Node: node modules/zero_galaxy/tests/<name>.js
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

$environment = getenv();
if (in_array('--no-db', $argv, true)) {
  $environment['ZG_TEST_NO_DB'] = '1';
}
$failed = 0;
foreach (glob(__DIR__ . '/*.php') as $file) {
  if ($file === __FILE__) { continue; }
  $process = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes,
    dirname(__DIR__, 3), $environment);
  $output = stream_get_contents($pipes[1]);
  fclose($pipes[1]);
  $code = proc_close($process);
  $status = $code === 0 ? 'PASS' : ($code === 77 ? 'SKIP' : 'FAIL');
  $lines = preg_split('/\R/', trim($output));
  printf("%s  %-18s %s\n", $status, basename($file, '.php'), end($lines));
  if ($status === 'FAIL') {
    $failed++;
    echo $output, "\n";
  }
}
echo $failed ? "{$failed} PHP check(s) failed\n" : "All PHP checks passed\n";
exit($failed ? 1 : 0);
