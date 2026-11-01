<?php

namespace ZeroGalaxy;

class AcsTokens {
  private $key;

  public function __construct(string $key) { $this->key = $key; }

  public function join(int $userId, int $sourceId, int $riftId, int $issuedAt, int $groupId): string {
    return hash_hmac('sha256', 'zero-galaxy-acs-join:v1:' . $groupId . ':'
      . AttackPolicy::token($userId, $sourceId, $riftId, $issuedAt, $this->key), $this->key);
  }

  public function validJoin($token, int $userId, int $sourceId, int $riftId, int $issuedAt,
    int $groupId, int $now): bool {
    return $this->fresh($token, $issuedAt, $now)
      && hash_equals($this->join($userId, $sourceId, $riftId, $issuedAt, $groupId), $token);
  }

  public function manage(int $userId, int $fleetId, int $issuedAt): string {
    return hash_hmac('sha256', "zero-galaxy-acs-manage:v1:{$userId}:{$fleetId}:{$issuedAt}", $this->key);
  }

  public function validManage($token, int $userId, int $fleetId, int $issuedAt, int $now): bool {
    return $this->fresh($token, $issuedAt, $now) && hash_equals($this->manage($userId, $fleetId, $issuedAt), $token);
  }

  private function fresh($token, int $issuedAt, int $now): bool {
    return is_string($token) && $issuedAt >= $now - AttackPolicy::TOKEN_TTL && $issuedAt <= $now;
  }
}
