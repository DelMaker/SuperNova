<?php

namespace ZeroGalaxy;

use InvalidArgumentException;

class AttackRequest {
  public $step;
  public $riftId;
  public $sourceId;
  public $groupId;
  public $issuedAt;
  public $speed;
  public $captainId;
  public $ships;
  private $post;

  public static function fromHttp(array $get, array $post, string $method, int $sourceId, int $now): self {
    $request = new self();
    $request->step = self::integer($get['step'] ?? 0);
    $submitted = $method === 'POST';
    if ($request->step < 0 || $request->step > 3 || ($request->step > 0) !== $submitted) {
      throw new InvalidArgumentException('zero_galaxy_attack_bad_request');
    }
    $input = $submitted ? $post : $get;
    $request->post = $submitted ? $post : null;
    $request->riftId = self::integer($input['rift_id'] ?? null);
    $request->groupId = self::integer($input['acs_group'] ?? 0);
    $request->sourceId = $submitted ? self::integer($post['source_id'] ?? null) : $sourceId;
    $request->issuedAt = $submitted ? self::integer($post['token_at'] ?? null) : $now;
    $request->speed = $request->step > 1 ? self::integer($post['speed'] ?? null) : 10;
    $request->captainId = $request->step === 3 ? self::integer($post['captain_id'] ?? 0) : 0;
    if ($request->groupId < 0) { throw new InvalidArgumentException('zero_galaxy_attack_bad_request'); }
    if ($request->speed < 1 || $request->speed > 10) { throw new InvalidArgumentException('fl_cheat_speed'); }
    $request->ships = $request->step === 0 ? [] : ($request->step === 1
      ? AttackPolicy::ships($post['ships'] ?? null) : AttackPolicy::decode($post['usedfleet'] ?? null));
    return $request;
  }

  public function authorize(int $userId, int $now, string $secret): void {
    if ($this->post === null) { return; }
    if (!AttackPolicy::validToken($this->post['token'] ?? null, $this->issuedAt, $userId,
      $this->sourceId, $this->riftId, $now, $secret)
      || ($this->groupId && !(new AcsTokens($secret))->validJoin($this->post['acs_token'] ?? null,
        $userId, $this->sourceId, $this->riftId, $this->issuedAt, $this->groupId, $now))) {
      throw new InvalidArgumentException('zero_galaxy_attack_expired_form');
    }
  }

  public function validateTarget(array $rift): void {
    if ($this->post === null) { return; }
    $fixed = ['galaxy' => 0, 'system' => (int) $rift['system'], 'planet' => (int) $rift['position'],
      'planet_type' => PT_PLANET, 'target_mission' => $this->groupId ? MT_AKS : MT_ATTACK,
      'fleet_group' => $this->groupId, 'resource0' => 0, 'resource1' => 0, 'resource2' => 0, 'missiontime' => 0];
    foreach ($fixed as $key => $value) {
      if (isset($this->post[$key]) && self::integer($this->post[$key]) !== $value) {
        throw new InvalidArgumentException('zero_galaxy_attack_bad_request');
      }
    }
  }

  private static function integer($value): int {
    if (!is_scalar($value) || is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
      throw new InvalidArgumentException('zero_galaxy_attack_bad_request');
    }
    return (int) $value;
  }
}
