<?php

require __DIR__ . '/support/bootstrap.php';
zgCoreConstants();

use ZeroGalaxy\RiftName;

$languages = ['ru' => zgLanguage('ru'), 'en' => zgLanguage('en')];
$rift = function (array $category): array {
  return ['rift_id' => 7, 'config_snapshot' => zgSnapshot(['category' => $category])];
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
  $check(RiftName::forRift($snapshot, $phase, $languages['ru']) === $ru
    && RiftName::forRift($snapshot, $phase, $languages['en']) === $en,
    'Names must use the saved category and localized phase framing: ' . $phase);
}
$check(RiftName::forRift($snapshot, 'unstable') === 'Особый нестабильный разлом',
  'Stored native names must use the server language when no reader language is given.');
$check(RiftName::forRift($rift(['name' => '  Особый  ']), 'unstable') === 'Особый нестабильный разлом', 'Padding around a configured category name must be trimmed.');
$check(RiftName::forRift($rift(['name' => '  ЁЖ ÄΩ  ']), 'closed') === 'Закрытый ёж äω разлом'
  && RiftName::forRift($rift(['name' => '  ЁЖ ÄΩ  ']), 'closed', $languages['en']) === 'Closed ЁЖ ÄΩ rift',
  'Russian closed names need Unicode lowercase and trimming; English must preserve category case.');
$check(RiftName::forRift($rift(['name' => "Особый\u{1F680}"]), 'active') === "Особый\u{FFFD} разлом",
  'Four-byte characters must not make native utf8 planet storage fail or disagree with the universe title.');
$check(RiftName::forRift($rift(['name' => "ОСОБЫЙ\u{1F680}"]), 'closed') === "Закрытый особый\u{FFFD} разлом"
  && RiftName::forRift($rift(['name' => "ОСОБЫЙ\u{1F680}"]), 'closed', $languages['en']) === "Closed ОСОБЫЙ\u{FFFD} rift",
  'The closed phase must retain the same native BMP normalization as physical planet names.');

foreach ($languages as $locale => $lang) {
  foreach (['active', 'unstable', 'energy', 'closed'] as $phase) {
    $check(RiftName::forRift(['config_snapshot' => '{}'], $phase, $lang) === $lang['zero_galaxy_phase_' . $phase],
      'A rift without a valid profile must use the localized phase label: ' . $locale . ' ' . $phase);
  }
}

$long = str_repeat('Жя', 45);
foreach (['ru' => ['active' => ' разлом', 'unstable' => ' нестабильный разлом'],
  'en' => ['active' => ' rift', 'unstable' => ' unstable rift']] as $locale => $phases) {
  foreach ($phases as $phase => $suffix) {
    $name = RiftName::forRift($rift(['key' => 'tiny', 'name' => $long]), $phase, $languages[$locale]);
    $prefixLength = 64 - count($characters($suffix));
    $expected = implode('', array_slice($characters($long), 0, $prefixLength)) . $suffix;
    $check($name === $expected, 'Only the category portion may be truncated, without an ellipsis or a lost phase suffix.');
    $check(count($characters($name)) === 64 && preg_match('//u', $name) === 1,
      'The native limit is 64 Unicode codepoints, not 64 bytes or invalid UTF-8 fragments.');
  }
}

foreach (['ru' => ['Закрытый ', ' разлом', str_repeat('жя', 45)],
  'en' => ['Closed ', ' rift', $long]] as $locale => [$prefix, $suffix, $category]) {
  $name = RiftName::forRift($rift(['name' => $long]), 'closed', $languages[$locale]);
  $available = 64 - count($characters($prefix)) - count($characters($suffix));
  $expected = $prefix . implode('', array_slice($characters($category), 0, $available)) . $suffix;
  $check($name === $expected, 'A long closed name may shorten only the category between its full prefix and suffix.');
  $check(count($characters($name)) === 64 && preg_match('//u', $name) === 1,
    'A closed name must fit 64 valid Unicode codepoints, including its prefix and suffix.');
  foreach ([$available, $available + 1] as $length) {
    $boundary = RiftName::forRift($rift(['name' => str_repeat('Ж', $length)]), 'closed', $languages[$locale]);
    $letter = $locale === 'en' ? 'Ж' : 'ж';
    $check($boundary === $prefix . str_repeat($letter, $available) . $suffix,
      'Exact-limit and one-codepoint-overflow categories must preserve the complete closed-phase framing.');
  }
}

$npc = RiftName::npc(str_repeat('Ж', 40) . "\u{1F680}");
$check(count($characters($npc)) === 32 && strpos($npc, 'Обитатели разлома Ж') === 0,
  'NPC names must fit the 32-character native user name in the server language.');
$check(RiftName::npc('  Крошечный ') === 'Обитатели разлома Крошечный', 'NPC names must use the trimmed category name.');

echo "Rift names: {$assertions} assertions passed; no game bootstrap or database used.\n";
