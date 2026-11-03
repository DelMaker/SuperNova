<?php

namespace ZeroGalaxy;

/** The only adapter that knows the native template's mutable internal layout. */
class NativeTemplateAdapter {
  private $template;

  public function __construct($template) { $this->template = $template; }

  public function value(string $key) { return $this->template->_rootref[$key] ?? null; }

  public function hasHandle(array $names): bool {
    return (bool) array_intersect(array_keys($this->template->files), $names);
  }

  public function isNative(string $name, string $directory = ''): bool {
    $file = $this->template->files[$name] ?? null;
    return is_string($file) && preg_match('~(?:^|/)' . preg_quote($directory . $name, '~')
      . '\\.tpl\\.html$~D', str_replace('\\', '/', $file)) === 1;
  }

  public function rows(string $block): array { return $this->template->_tpldata[$block] ?? []; }

  public function replaceRows(string $block, array $rows): int {
    $this->template->destroy_block_vars($block);
    $this->template->_tpldata[$block] = [];
    foreach ($rows as $row) {
      unset($row['S_ROW_COUNT'], $row['S_FIRST_ROW'], $row['S_LAST_ROW']);
      $this->template->assign_block_vars($block, $row);
    }
    return count($rows);
  }

  public function mapRows(string $block, callable $map): void {
    foreach ($this->rows($block) as $index => $row) {
      $this->template->_tpldata[$block][$index] = $map($row);
    }
  }

  public function escapeFleetNames(): void {
    $this->mapRows('fleets', static function (array $row): array {
      foreach (['START_NAME', 'END_NAME'] as $key) {
        if (isset($row[$key])) { $row[$key] = htmlspecialchars($row[$key], ENT_QUOTES, 'UTF-8'); }
      }
      return $row;
    });
  }

  public function wrap($module, string $name) {
    $this->template->files = $this->template->filename = $this->template->files_inherit = [];
    return $module->addModuleTemplate($name, $this->template);
  }
}
