<?php

spl_autoload_register(function (string $class): void {
  $prefix = 'ZeroGalaxy\\';
  if (strpos($class, $prefix) !== 0) { return; }
  $file = dirname(__DIR__, 2) . '/classes/' . str_replace('\\', '/', $class) . '.php';
  if (is_file($file)) { require_once $file; }
});
