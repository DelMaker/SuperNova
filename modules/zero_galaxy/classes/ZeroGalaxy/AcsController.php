<?php

namespace ZeroGalaxy;

use InvalidArgumentException;
use Throwable;

class AcsController {
  private $application;
  private $tokens;
  private $messages;
  private $now;
  private $root;
  private $notify;

  public function __construct(AcsApplicationService $application, AcsTokens $tokens, $messages, int $now,
    string $root, callable $notify) {
    $this->application = $application;
    $this->tokens = $tokens;
    $this->messages = $messages;
    $this->now = $now;
    $this->root = $root;
    $this->notify = $notify;
  }

  public function handle($module, $template, array $actor, array $get, array $post, string $method) {
    try {
      $this->application->requireEnabled();
      $fleetId = filter_var($post['fleetid'] ?? $get['fleetid'] ?? 0, FILTER_VALIDATE_INT);
      if ($fleetId === false) { throw new AcsFailure('zero_galaxy_acs_missing'); }
      $page = $this->application->overview((int) $actor['id'], $fleetId);
      if ($method === 'POST') {
        $issuedAt = filter_var($post['token_at'] ?? null, FILTER_VALIDATE_INT);
        if (!$page['fleet'] || $issuedAt === false || !$this->tokens->validManage($post['acs_token'] ?? null,
          (int) $actor['id'], $fleetId, $issuedAt, $this->now)) { throw new AcsFailure('zero_galaxy_attack_expired_form'); }
        $invite = $this->application->manage($page['fleet'], $page['rift'], (int) $actor['id'], trim((string) ($post['addtogroup'] ?? '')));
        if ($invite) {
          try { ($this->notify)($actor, $invite); }
          catch (Throwable $error) { error_log('Zero Galaxy committed ACS invitation notification: ' . $error->getMessage()); }
        }
        $page = $this->application->overview((int) $actor['id'], $fleetId);
      }
      return AcsPresenter::render($module, $template, $page, (int) $actor['id'], $fleetId, $this->now,
        $this->tokens, $this->messages, $this->root);
    } catch (InvalidArgumentException $error) {
      $message = $error instanceof AcsFailure ? $this->messages[$error->getMessage()] : $error->getMessage();
      \SnTemplate::messageBox(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), $this->messages['zero_galaxy_acs_title']);
    } catch (Throwable $error) {
      error_log('Zero Galaxy ACS: ' . $error->getMessage());
      \SnTemplate::messageBox($this->messages['zero_galaxy_attack_failed'], $this->messages['zero_galaxy_acs_title']);
    }
    return $template;
  }
}
