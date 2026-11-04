<?php

require_once SN_ROOT_PHYSICAL . 'classes/template_compile.php';
require_once SN_ROOT_PHYSICAL . 'classes/template.php';

/** Native template engine without files, cache or the game bootstrap. */
class ZgTemplate extends template {
  public static function native(string $name): string {
    return SN_ROOT_PHYSICAL . 'design/templates/OpenGame/' . $name . '.tpl.html';
  }

  public static function module(string $name): string {
    return dirname(__DIR__, 2) . '/design/templates/OpenGame/' . $name . '.tpl.html';
  }

  /** @param string[] $files Template handles mapped to their files. */
  public static function fixture(array $files = []): self {
    $template = new self();
    $template->_rootref = &$template->_tpldata['.'][0];
    $template->files = $files;
    return $template;
  }

  public function renderSource(string $source): string {
    ob_start();
    $this->evaluate($this->compiler->compile($source));
    return ob_get_clean();
  }
}

/** Module stand-in for views that wrap native templates. */
class ZgModule {
  public $selected;

  public function getRootRelative() { return 'modules/zero_galaxy/'; }
  public function getVersion() { return 'fixture'; }

  public function addModuleTemplate($name, $template) {
    $this->selected = $name;
    $template->files[$name] = ZgTemplate::module($name);
    return $template;
  }
}
