<?php

namespace ZeroGalaxy;

use InvalidArgumentException;
use RuntimeException;
use SN;

class AttackPolicy {
  const OVER_LIMIT = 'Флот слишком большой для этого разлома';
  const TOKEN_TTL = 3600;

  public static function limit(array $rift): ?int {
    $profile = Profile::read($rift);
    $limit = $profile['fleet_limit'] ?? null;
    return is_int($limit) && $limit >= 0 ? $limit : null;
  }

  /** Match native fleet statistics: sum weighted costs, then floor once. */
  public static function cost(array $ships, callable $cost = null): int {
    $cost = $cost ?? function ($id, $count) { return eco_get_total_cost($id, $count)['total'] ?? 0; };
    $total = 0;
    foreach ($ships as $id => $count) {
      if (!is_numeric($count) || !is_finite((float) $count) || $count < 0
        || $count >= PHP_INT_MAX || floor((float) $count) != $count) {
        throw new InvalidArgumentException('Invalid fleet quantity');
      }
      if ($count == 0) { continue; }
      $price = $cost((int) $id, $count);
      if (!is_numeric($price) || !is_finite((float) $price) || $price <= 0) {
        throw new InvalidArgumentException('Invalid fleet unit cost');
      }
      $total += $price;
    }
    if (!is_finite((float) $total) || $total >= PHP_INT_MAX) {
      throw new InvalidArgumentException('Fleet cost exceeds the supported range');
    }
    return (int) floor($total);
  }

  public static function ships($input): array {
    if (!is_array($input) || count($input) > count(sn_get_groups('fleet'))) {
      throw new InvalidArgumentException('Invalid fleet composition');
    }
    $result = [];
    foreach ($input as $id => $count) {
      if ((!is_int($id) && (!is_string($id) || !preg_match('/^[1-9][0-9]*$/D', $id)))
        || !in_array((int) $id, sn_get_groups('fleet'), true) || !is_scalar($count) || is_bool($count)
        || !preg_match('/^[0-9]+$/D', (string) $count)
        || (float) $count >= PHP_INT_MAX) {
        throw new InvalidArgumentException('Invalid fleet composition');
      }
      if ((int) $count > 0) { $result[(int) $id] = (int) $count; }
    }
    if (!$result) { throw new InvalidArgumentException('Empty attacking fleet'); }
    return $result;
  }

  public static function decode($usedFleet): array {
    if (!is_string($usedFleet) || strlen($usedFleet) > 16000) {
      throw new InvalidArgumentException('Invalid encoded fleet');
    }
    $decoded = base64_decode(str_rot13($usedFleet), true);
    return self::ships($decoded === false ? null : json_decode($decoded, true));
  }

  public static function token(int $userId, int $sourceId, int $riftId, int $issuedAt, string $key = null): string {
    $key = $key ?? SN::$sn_secret_word;
    if (strlen($key) < 16) { throw new RuntimeException('Zero Galaxy attack signing key is unavailable'); }
    return hash_hmac('sha256', "zero-galaxy-attack:v1:{$userId}:{$sourceId}:{$riftId}:{$issuedAt}", $key);
  }

  public static function validToken($token, int $issuedAt, int $userId, int $sourceId, int $riftId,
    int $now, string $key = null): bool {
    return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)
      && $issuedAt >= $now - self::TOKEN_TTL && $issuedAt <= $now
      && hash_equals(self::token($userId, $sourceId, $riftId, $issuedAt, $key), $token);
  }
}
