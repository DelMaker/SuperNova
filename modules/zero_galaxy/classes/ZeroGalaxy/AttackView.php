<?php

namespace ZeroGalaxy;

/** Native MVC entry points; request handling and presentation have separate boundaries. */
class AttackView {
  public static function render($module, Storage $storage, array $settings) {
    $controller = new AttackController($storage, $settings, new NativeFleetWizard($module));
    $controller->handle($_GET, $_POST, $_SERVER['REQUEST_METHOD'] ?? '');
  }

  public static function escapeFleetNames($template): void {
    (new NativeTemplateAdapter($template))->mapRows('fleets', function (array $fleet): array {
      foreach (['START_NAME', 'END_NAME'] as $field) {
        if (isset($fleet[$field])) { $fleet[$field] = htmlspecialchars((string) $fleet[$field], ENT_QUOTES, 'UTF-8'); }
      }
      return $fleet;
    });
  }
}
