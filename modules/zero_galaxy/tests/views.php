<?php

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

define('INSIDE', true);
require __DIR__ . '/support/autoload.php';
define('SN_ROOT_PHYSICAL', dirname(__DIR__, 3) . '/');
define('SN_ROOT_RELATIVE', '');
require_once SN_ROOT_PHYSICAL . 'classes/template_compile.php';
require_once SN_ROOT_PHYSICAL . 'classes/template.php';
require_once dirname(__DIR__) . '/classes/ZeroGalaxy/NativeTemplateAdapter.php';

// Only presentation dependencies: no sessions, game bootstrap or database.
class SN { public static $lang = []; }
class ViewFixtureTemplate extends template {
  public function renderSource(string $source): string {
    ob_start();
    $this->evaluate($this->compiler->compile($source));
    return ob_get_clean();
  }
}

require __DIR__ . '/support/assertions.php';
$partial = file_get_contents(SN_ROOT_PHYSICAL . 'design/templates/OpenGame/fleet_miniatures.tpl.html');
$names = ['Крошечный разлом', 'Literal &copy; &amp; разлом', 'Cash $& [PLANET_IMAGE] разлом', 'Имя "Альфа" и \'Бета\' разлом'];
foreach ($names as $name) {
  $template = new ViewFixtureTemplate();
  $template->_rootref = &$template->_tpldata['.'][0];
  $template->assign_block_vars('fleets', ['START_COORDS' => '[1:2:3]', 'START_NAME' => $name,
    'END_COORDS' => '[0:18:6]', 'END_NAME' => $name, 'MESSAGE' => 0, 'MISSION' => 1]);
  (new ZeroGalaxy\NativeTemplateAdapter($template))->escapeFleetNames();
  $html = $template->renderSource($partial);
  preg_match('/<span id="end_name">(.*?)<\/span>/s', $html, $match);
  $check(isset($match[1]) && html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') === $name,
    'Native miniature must show literal names without decoding an extra layer');
  $check(substr_count($html, htmlspecialchars($name, ENT_QUOTES, 'UTF-8')) === 2,
    'Source and destination names must each be escaped exactly once');
}

echo "Views: {$assertions} assertions passed; real native miniature template, no game bootstrap or database.\n";
