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

$assertions = 0;
$check = function ($condition, $message) use (&$assertions) {
  $assertions++;
  if (!$condition) { throw new RuntimeException($message); }
};
$rift = function (array $category, int $version = 1): array {
  return ['rift_id' => 7, 'config_snapshot' => json_encode([
    'profile' => ['version' => $version, 'category' => $category],
  ], JSON_UNESCAPED_UNICODE)];
};
$characters = function (string $text): array {
  preg_match_all('/./us', $text, $matches);
  return $matches[0];
};
$categories = ['tiny' => 'Крошечный', 'small' => 'Малый', 'medium' => 'Средний',
  'large' => 'Большой', 'huge' => 'Огромный', 'colossal' => 'Колоссальный', 'great' => 'Великий'];
$closedNames = ['tiny' => 'Закрытый крошечный разлом', 'small' => 'Закрытый малый разлом',
  'medium' => 'Закрытый средний разлом', 'large' => 'Закрытый большой разлом',
  'huge' => 'Закрытый огромный разлом', 'colossal' => 'Закрытый колоссальный разлом',
  'great' => 'Закрытый великий разлом'];
foreach ($categories as $key => $name) {
  $snapshot = $rift(['key' => $key, 'name' => $name]);
  $before = $snapshot;
  $check(RiftName::forRift($snapshot, 'active') === $name . ' разлом', 'Active native name must include the saved size: ' . $key);
  $check(RiftName::forRift($snapshot, 'unstable', 'ru') === $name . ' нестабильный разлом', 'Unstable native name must keep size before the phase adjective: ' . $key);
  $check(RiftName::forRift($snapshot, 'active', 'en') === $name . ' rift'
    && RiftName::forRift($snapshot, 'unstable', 'en') === $name . ' unstable rift',
    'English suffixes must preserve the captured category name rather than substitute a new translation.');
  $check(RiftName::forRift($snapshot, 'energy') === 'Всплески энергии'
    && RiftName::forRift($snapshot, 'energy', 'en') === 'Energy disturbance',
    'Energy names must describe the phase without exposing a category: ' . $key);
  $check(RiftName::forRift($snapshot, 'closed') === $closedNames[$key],
    'A closed Russian rift must retain its size in lowercase: ' . $key);
  $check(RiftName::forRift($snapshot, 'closed', 'en') === 'Closed ' . $name . ' rift',
    'A closed English rift must retain the captured category spelling: ' . $key);
  $check($snapshot === $before, 'Formatting must not rewrite the shared snapshot.');
}

$custom = $rift(['key' => 'tiny', 'name' => 'Особый']);
$check(RiftName::forRift($custom, 'active') === 'Особый разлом'
  && RiftName::forRift($custom, 'unstable') === 'Особый нестабильный разлом', 'Configured category names must survive even for known keys.');
$check(RiftName::forRift($custom, 'closed') === 'Закрытый особый разлом'
  && RiftName::forRift($custom, 'closed', 'en') === 'Closed Особый rift',
  'Closed names must use the saved custom category rather than its canonical key.');
$unknown = $rift(['key' => 'custom-extension', 'name' => 'Дальний']);
$check(RiftName::forRift($unknown, 'active') === 'Дальний разлом'
  && RiftName::forRift($unknown, 'unstable', 'en') === 'Дальний unstable rift', 'An unknown category key with a saved name must remain displayable.');
$check(RiftName::forRift($unknown, 'closed') === 'Закрытый дальний разлом'
  && RiftName::forRift($unknown, 'closed', 'en') === 'Closed Дальний rift',
  'Closed names must also support category keys from an extension.');
$withoutKey = $rift(['name' => 'Безымянный']);
$check(RiftName::forRift($withoutKey, 'active') === 'Безымянный разлом', 'A saved name must not depend on the presence of a recognized key.');
$check(RiftName::forRift($rift(['name' => '  Особый  ']), 'unstable') === 'Особый нестабильный разлом', 'Padding around a configured category name must be trimmed.');
$check(RiftName::forRift($rift(['name' => '  ЁЖ ÄΩ  ']), 'closed') === 'Закрытый ёж äω разлом'
  && RiftName::forRift($rift(['name' => '  ЁЖ ÄΩ  ']), 'closed', 'en') === 'Closed ЁЖ ÄΩ rift',
  'Russian closed names need Unicode lowercase and trimming; English must preserve category case.');
$check(RiftName::forRift($rift(['name' => "Особый\u{1F680}"]), 'active') === "Особый\u{FFFD} разлом",
  'Four-byte characters must not make native utf8 planet storage fail or disagree with the universe title.');
$check(RiftName::forRift($rift(['name' => "ОСОБЫЙ\u{1F680}"]), 'closed') === "Закрытый особый\u{FFFD} разлом"
  && RiftName::forRift($rift(['name' => "ОСОБЫЙ\u{1F680}"]), 'closed', 'en') === "Closed ОСОБЫЙ\u{FFFD} rift",
  'The closed phase must retain the same native BMP normalization as physical planet names.');

foreach ([[], ['config_snapshot' => '{}'], ['config_snapshot' => '{bad json'],
  $rift(['key' => 'tiny']), $rift(['name' => '']), $rift(['name' => '   ']),
  $rift(['name' => null]), $rift(['name' => []]), $rift(['name' => 42]), $rift(['name' => 'Old version'], 2)] as $legacy) {
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
  $before = $snapshot;
  $name = RiftName::forRift($snapshot, 'closed', $locale);
  $available = 64 - count($characters($prefix)) - count($characters($suffix));
  $expected = $prefix . implode('', array_slice($characters($category), 0, $available)) . $suffix;
  $check($name === $expected, 'A long closed name may shorten only the category between its full prefix and suffix.');
  $check(count($characters($name)) === 64 && preg_match('//u', $name) === 1,
    'A closed name must fit 64 valid Unicode codepoints, including its prefix and suffix.');
  $check($snapshot === $before, 'Lowercasing and shortening a closed name must not modify its profile snapshot.');
  foreach ([$available, $available + 1] as $length) {
    $boundary = RiftName::forRift($rift(['name' => str_repeat('Ж', $length)]), 'closed', $locale);
    $letter = $locale === 'en' ? 'Ж' : 'ж';
    $check($boundary === $prefix . str_repeat($letter, $available) . $suffix,
      'Exact-limit and one-codepoint-overflow categories must preserve the complete closed-phase framing.');
  }
  $check(RiftName::forRift($snapshot, 'energy', $locale)
    === ($locale === 'en' ? 'Energy disturbance' : 'Всплески энергии'),
    'Even an oversized category must not affect an energy name.');
}

echo "Rift names: {$assertions} assertions passed; no game bootstrap or database used.\n";
