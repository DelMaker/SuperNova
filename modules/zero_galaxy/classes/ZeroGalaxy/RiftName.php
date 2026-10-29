<?php

namespace ZeroGalaxy;

/** The native planet name is also captured in espionage and combat reports. */
class RiftName {
  public static function forRift(array $rift, string $phase, string $locale = 'ru'): string {
    if ($phase === 'energy') {
      return $locale === 'en' ? 'Energy disturbance' : 'Всплески энергии';
    }
    $profile = Profile::read($rift);
    $category = $profile['category']['name'] ?? null;
    if (!is_string($category) || trim($category) === '') {
      if ($phase === 'closed') { return $locale === 'en' ? 'Closed rift' : 'Закрытый разлом'; }
      return $phase === 'unstable' ? Storage::UNSTABLE_NAME : Storage::ACTIVE_NAME;
    }

    $prefix = $phase === 'closed' ? ($locale === 'en' ? 'Closed ' : 'Закрытый ') : '';
    $suffix = $locale === 'en'
      ? ($phase === 'unstable' ? ' unstable rift' : ' rift')
      : ($phase === 'unstable' ? ' нестабильный разлом' : ' разлом');
    // Native planets use MySQL utf8 (three bytes) and a 64-character column.
    // Normalize unsupported characters consistently in the UI and stored name,
    // and keep the phase prefix and suffix when a category needs shortening.
    $category = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\u{FFFD}", trim($category));
    if ($phase === 'closed' && $locale !== 'en') { $category = mb_strtolower($category, 'UTF-8'); }
    preg_match_all('/./us', $category, $characters);
    preg_match_all('/./us', $prefix, $prefixCharacters);
    preg_match_all('/./us', $suffix, $suffixCharacters);
    $category = rtrim(implode('', array_slice($characters[0], 0, 64 - count($prefixCharacters[0]) - count($suffixCharacters[0]))));
    return $prefix . $category . $suffix;
  }
}
