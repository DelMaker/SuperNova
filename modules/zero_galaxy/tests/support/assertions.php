<?php

$assertions = 0;
$check = function ($condition, string $message) use (&$assertions): void {
  $assertions++;
  if (!$condition) { throw new \RuntimeException($message); }
};
