<?php

namespace ZeroGalaxy;

/** Planet and NPC names are stored natively and reappear in reports, so by default they use the server language. */
class RiftName {
  const PLANET_LENGTH = 64;
  const USER_LENGTH = 32;
  private static $serverLanguage;

  /** @param array|\ArrayAccess|null $lang Module strings of the reader; null for stored names. */
  public static function forRift(array $rift, string $phase, $lang = null): string {
    $lang = $lang ?? self::serverLanguage();
    $category = Profile::read($rift)['category']['name'] ?? null;
    $template = $lang['zero_galaxy_rift_names'][$phase] ?? null;
    if ($category === null || $template === null) {
      return (string) ($lang['zero_galaxy_phase_' . $phase] ?? '');
    }
    $category = trim($category);
    if (strpos($template, '{category_lower}') !== false) {
      $category = mb_strtolower($category, 'UTF-8');
    }
    // Shorten only the category so the phase framing always survives the native column limit.
    $placeholders = ['{category}', '{category_lower}'];
    $framing = mb_strlen(str_replace($placeholders, '', $template), 'UTF-8');
    return str_replace($placeholders, self::fit($category, self::PLANET_LENGTH - $framing), $template);
  }

  public static function npc(string $categoryName): string {
    $template = (string) self::serverLanguage()['zero_galaxy_npc_name'];
    return self::fit(str_replace('{category}', trim($categoryName), $template), self::USER_LENGTH);
  }

  /** Native names are MySQL utf8 (three bytes per character) columns limited in characters, not bytes. */
  public static function fit(string $text, int $length): string {
    $text = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\u{FFFD}", $text);
    preg_match_all('/./us', $text, $characters);
    return rtrim(implode('', array_slice($characters[0], 0, max(0, $length))));
  }

  private static function serverLanguage(): array {
    if (self::$serverLanguage === null) {
      $directory = dirname(__DIR__, 2) . '/language/';
      $file = $directory . (defined('DEFAULT_LANG') ? DEFAULT_LANG : 'ru') . '/zero_galaxy.mo.php';
      $a_lang_array = [];
      include is_file($file) ? $file : $directory . 'ru/zero_galaxy.mo.php';
      self::$serverLanguage = $a_lang_array;
    }
    return self::$serverLanguage;
  }
}
