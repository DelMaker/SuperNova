<?php

require __DIR__ . '/support/bootstrap.php';
zgCoreConstants();
require __DIR__ . '/support/template.php';

class SN { public static $lang = []; }

$partial = file_get_contents(ZgTemplate::native('fleet_miniatures'));
$names = ['Literal &copy; &amp; разлом', 'Cash $& [PLANET_IMAGE] разлом', 'Имя "Альфа" и \'Бета\' разлом'];
foreach ($names as $name) {
  $template = ZgTemplate::fixture();
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
