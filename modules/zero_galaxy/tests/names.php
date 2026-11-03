<?php

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Profile.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/Storage.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/RiftName.php';

use ZeroGalaxy\RiftName;
use ZeroGalaxy\Storage;

require __DIR__ . '/support/assertions.php';
$rift = function (array $category, int $version = 1): array {
  return ['rift_id' => 7, 'config_snapshot' => json_encode([
    'profile' => ['version' => $version, 'category' => $category],
  ], JSON_UNESCAPED_UNICODE)];
};
$characters = function (string $text): array {
  preg_match_all('/./us', $text, $matches);
  return $matches[0];
};
$snapshot = $rift(['key' => 'custom-extension', 'name' => 'Особый']);
foreach ([
  'active' => ['Особый разлом', 'Особый rift'],
  'unstable' => ['Особый нестабильный разлом', 'Особый unstable rift'],
  'closed' => ['Закрытый особый разлом', 'Closed Особый rift'],
  'energy' => ['Всплески энергии', 'Energy disturbance'],
] as $phase => [$ru, $en]) {
  $check(RiftName::forRift($snapshot, $phase) === $ru && RiftName::forRift($snapshot, $phase, 'en') === $en,
    'Names must use the saved category and localized phase framing: ' . $phase);
}
$check(RiftName::forRift($rift(['name' => '  Особый  ']), 'unstable') === 'Особый нестабильный разлом', 'Padding around a configured category name must be trimmed.');
$check(RiftName::forRift($rift(['name' => '  ЁЖ ÄΩ  ']), 'closed') === 'Закрытый ёж äω разлом'
  && RiftName::forRift($rift(['name' => '  ЁЖ ÄΩ  ']), 'closed', 'en') === 'Closed ЁЖ ÄΩ rift',
  'Russian closed names need Unicode lowercase and trimming; English must preserve category case.');
$check(RiftName::forRift($rift(['name' => "Особый\u{1F680}"]), 'active') === "Особый\u{FFFD} разлом",
  'Four-byte characters must not make native utf8 planet storage fail or disagree with the universe title.');
$check(RiftName::forRift($rift(['name' => "ОСОБЫЙ\u{1F680}"]), 'closed') === "Закрытый особый\u{FFFD} разлом"
  && RiftName::forRift($rift(['name' => "ОСОБЫЙ\u{1F680}"]), 'closed', 'en') === "Closed ОСОБЫЙ\u{FFFD} rift",
  'The closed phase must retain the same native BMP normalization as physical planet names.');

foreach ([[], $rift(['name' => '   ']), $rift(['name' => []])] as $legacy) {
  foreach (['ru', 'en'] as $locale) {
    $check(RiftName::forRift($legacy, 'active', $locale) === Storage::ACTIVE_NAME,
      'A legacy or nameless profile must retain the existing empty active-planet name.');
    $check(RiftName::forRift($legacy, 'unstable', $locale) === Storage::UNSTABLE_NAME,
      'A legacy or nameless profile must retain the existing empty unstable-planet name.');
    $check(RiftName::forRift($legacy, 'energy', $locale)
      === ($locale === 'en' ? 'Energy disturbance' : 'Всплески энергии'),
      'Energy names must not depend on a valid profile or category.');
    $check(RiftName::forRift($legacy, 'closed', $locale)
      === ($locale === 'en' ? 'Closed rift' : 'Закрытый разлом'),
      'A legacy or nameless closed rift needs a localized name without a category.');
  }
}

$long = str_repeat('Жя', 45);
foreach (['ru' => ['active' => ' разлом', 'unstable' => ' нестабильный разлом'],
  'en' => ['active' => ' rift', 'unstable' => ' unstable rift']] as $locale => $phases) {
  foreach ($phases as $phase => $suffix) {
    $name = RiftName::forRift($rift(['key' => 'tiny', 'name' => $long]), $phase, $locale);
    $prefixLength = 64 - count($characters($suffix));
    $expected = implode('', array_slice($characters($long), 0, $prefixLength)) . $suffix;
    $check($name === $expected, 'Only the category portion may be truncated, without an ellipsis or a lost phase suffix.');
    $check(count($characters($name)) === 64 && preg_match('//u', $name) === 1,
      'The native limit is 64 Unicode codepoints, not 64 bytes or invalid UTF-8 fragments.');
  }
}

foreach (['ru' => ['Закрытый ', ' разлом', str_repeat('жя', 45)],
  'en' => ['Closed ', ' rift', $long]] as $locale => $parts) {
  [$prefix, $suffix, $category] = $parts;
  $snapshot = $rift(['name' => $long]);
  $name = RiftName::forRift($snapshot, 'closed', $locale);
  $available = 64 - count($characters($prefix)) - count($characters($suffix));
  $expected = $prefix . implode('', array_slice($characters($category), 0, $available)) . $suffix;
  $check($name === $expected, 'A long closed name may shorten only the category between its full prefix and suffix.');
  $check(count($characters($name)) === 64 && preg_match('//u', $name) === 1,
    'A closed name must fit 64 valid Unicode codepoints, including its prefix and suffix.');
  foreach ([$available, $available + 1] as $length) {
    $boundary = RiftName::forRift($rift(['name' => str_repeat('Ж', $length)]), 'closed', $locale);
    $letter = $locale === 'en' ? 'Ж' : 'ж';
    $check($boundary === $prefix . str_repeat($letter, $available) . $suffix,
      'Exact-limit and one-codepoint-overflow categories must preserve the complete closed-phase framing.');
  }
}

echo "Rift names: {$assertions} assertions passed; no game bootstrap or database used.\n";
