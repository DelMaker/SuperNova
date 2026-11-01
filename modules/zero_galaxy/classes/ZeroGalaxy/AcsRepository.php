<?php

namespace ZeroGalaxy;

use DBAL\db_mysql;
use Fleet\DbFleetStatic;
use Throwable;

class AcsRepository implements AcsStore {
  private $db;
  private $lockRecords;

  public function __construct($db, callable $lockRecords) {
    $this->db = $db;
    $this->lockRecords = $lockRecords;
  }

  private function query(string $sql, bool $single = false) {
    return $this->db->doquery($sql, $single);
  }

  private function rows(string $sql): array {
    $rows = []; $result = $this->query($sql);
    while ($row = $this->db->db_fetch($result)) { $rows[] = $row; }
    return $rows;
  }

  public function group(int $id, bool $lock = false): ?array {
    return $this->query("SELECT * FROM {{aks}} WHERE id={$id}" . ($lock ? ' FOR UPDATE' : ''), true) ?: null;
  }

  public function fleet(int $id, bool $lock = false): ?array {
    return $this->query("SELECT * FROM {{fleets}} WHERE fleet_id={$id}" . ($lock ? ' FOR UPDATE' : ''), true) ?: null;
  }

  public function fleets(int $groupId, bool $lock = false): array {
    return $this->rows("SELECT * FROM {{fleets}} WHERE fleet_group={$groupId} ORDER BY fleet_id"
      . ($lock ? ' FOR UPDATE' : ''));
  }

  public function leaderGroup(array $preview, int $fleetId): ?array {
    $selector = !empty($preview['fleet_group']) ? 'id=' . (int) $preview['fleet_group'] : "flotten={$fleetId}";
    return $this->query("SELECT * FROM {{aks}} WHERE {$selector} FOR UPDATE", true) ?: null;
  }

  public function invitedAt(array $rift, int $userId, int $now): array {
    return $this->rows('SELECT * FROM {{aks}} WHERE galaxy=0 AND planet_type=' . PT_PLANET
      . ' AND system=' . (int) $rift['system'] . ' AND planet=' . (int) $rift['position']
      . " AND ankunft>{$now} AND FIND_IN_SET('{$userId}',eingeladen) ORDER BY ankunft,id");
  }

  public function invitedCoordinates(int $userId, int $now): array {
    return $this->rows("SELECT DISTINCT system,planet FROM {{aks}} WHERE galaxy=0 AND ankunft>{$now}"
      . " AND FIND_IN_SET('{$userId}',eingeladen)");
  }

  public function user(int $id): ?array { return db_user_by_id($id) ?: null; }
  public function userByName(string $name): ?array { return db_user_by_username($name, true) ?: null; }

  public function transaction(callable $operation) {
    if (db_mysql::$db_in_transaction || $this->db->transactionCheck()) { throw new AcsFailure('zero_galaxy_acs_missing'); }
    db_mysql::db_transaction_start();
    try {
      $result = $operation();
      db_mysql::db_transaction_commit();
      return $result;
    } catch (Throwable $error) {
      db_mysql::db_transaction_rollback();
      throw $error;
    }
  }

  public function lockRift(int $riftId): ?array {
    $this->query('SELECT id FROM {{zero_galaxy_state}} WHERE id=1 FOR UPDATE', true);
    return $this->query("SELECT * FROM {{zero_galaxy_rifts}} WHERE rift_id={$riftId} FOR UPDATE", true) ?: null;
  }

  public function lockFleetContext(array $owners, array $planets, int $fleetId): void {
    ($this->lockRecords)(['users' => array_values(array_unique(array_filter($owners))),
      'planets' => $planets, 'fleets' => [$fleetId]]);
  }

  public function createGroup(int $userId, array $fleet): int {
    $fleetId = (int) $fleet['fleet_id'];
    DbFleetStatic::dbAcsInsert($userId, $fleetId, $fleet);
    $group = DbFleetStatic::dbAcsGetByFleet($fleetId);
    if (!$group) { throw new AcsFailure('zero_galaxy_acs_missing'); }
    $groupId = (int) $group['id'];
    DbFleetStatic::fleet_update_set($fleetId, ['fleet_group' => $groupId, 'fleet_mission' => MT_AKS]);
    return $groupId;
  }

  public function invite(int $userId, int $fleetId): void {
    DbFleetStatic::dbAcsAddUserByFleetId($userId, $fleetId);
  }
}
