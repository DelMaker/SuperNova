<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/support/autoload.php';
require __DIR__ . '/support/assertions.php';
define('FLEET_STATUS_FLYING', 0);
define('MT_ATTACK', 1);
define('MT_AKS', 2);
define('PT_PLANET', 1);
function sn_get_groups($group) { return [202 => 202]; }

use ZeroGalaxy\AcsApplicationService;
use ZeroGalaxy\AcsController;
use ZeroGalaxy\AcsFailure;
use ZeroGalaxy\AcsPolicy;
use ZeroGalaxy\AcsStore;
use ZeroGalaxy\AcsTokens;

class MemoryAcsStore implements AcsStore {
  public $groups = [], $fleetRows = [], $users = [], $rift, $events = [];
  public function group(int $id, bool $lock = false): ?array { return $this->groups[$id] ?? null; }
  public function fleet(int $id, bool $lock = false): ?array { return $this->fleetRows[$id] ?? null; }
  public function fleets(int $groupId, bool $lock = false): array {
    return array_values(array_filter($this->fleetRows, function ($fleet) use ($groupId) { return $fleet['fleet_group'] === $groupId; }));
  }
  public function leaderGroup(array $preview, int $fleetId): ?array {
    if ($preview['fleet_group']) { return $this->group($preview['fleet_group']); }
    foreach ($this->groups as $group) { if ($group['flotten'] === $fleetId) { return $group; } }
    return null;
  }
  public function invitedAt(array $rift, int $userId, int $now): array { return array_values($this->groups); }
  public function invitedCoordinates(int $userId, int $now): array { return [['system'=>10,'planet'=>5]]; }
  public function user(int $id): ?array { return $this->users[$id] ?? null; }
  public function userByName(string $name): ?array {
    foreach ($this->users as $user) { if ($user['username'] === $name) { return $user; } }
    return null;
  }
  public function transaction(callable $operation) {
    $before = [$this->groups, $this->fleetRows]; $this->events[] = 'begin';
    try { $result = $operation(); $this->events[] = 'commit'; return $result; }
    catch (Throwable $error) { [$this->groups, $this->fleetRows] = $before; $this->events[] = 'rollback'; throw $error; }
  }
  public function lockRift(int $riftId): ?array { $this->events[] = 'rift'; return $this->rift; }
  public function lockFleetContext(array $owners, array $planets, int $fleetId): void { $this->events[] = 'native-records'; }
  public function createGroup(int $userId, array $fleet): int {
    $id = count($this->groups) + 1;
    $this->groups[$id] = ['id'=>$id,'flotten'=>$fleet['fleet_id'],'galaxy'=>0,'system'=>10,'planet'=>5,
      'planet_type'=>1,'ankunft'=>$fleet['fleet_start_time'],'eingeladen'=>(string)$userId,'name'=>'Group'];
    $this->fleetRows[$fleet['fleet_id']]['fleet_group'] = $id;
    $this->fleetRows[$fleet['fleet_id']]['fleet_mission'] = MT_AKS;
    return $id;
  }
  public function invite(int $userId, int $fleetId): void {
    $id = $this->fleetRows[$fleetId]['fleet_group']; $this->groups[$id]['eingeladen'] .= ',' . $userId;
  }
}
class AcsPageFailure extends RuntimeException {}
class SnTemplate { public static function messageBox($message, $title) { throw new AcsPageFailure($message); } }
class AcsPageTemplate {
  public $vars = [], $blocks = [];
  public function assign_vars(array $vars) { $this->vars = array_replace($this->vars, $vars); }
  public function assign_var($key, $value) { $this->vars[$key] = $value; }
  public function assign_block_vars($key, $row) { $this->blocks[$key][] = $row; }
}
class AcsPageModule { public function addModuleTemplate($name, $template) { return $template ?? new AcsPageTemplate(); } }
$store = new MemoryAcsStore();
$store->rift = ['rift_id'=>1,'system'=>10,'position'=>5,'planet_id'=>90,'npc_owner_id'=>20,
  'born_at'=>900,'opens_at'=>950,'unstable_at'=>1500,'closes_at'=>1600,'disappears_at'=>1700,
  'config_snapshot'=>json_encode(['profile'=>['version'=>1,'fleet_limit'=>100]])];
$leader = ['fleet_id'=>1,'fleet_owner'=>7,'fleet_group'=>0,'fleet_mission'=>MT_ATTACK,'fleet_mess'=>0,
  'fleet_end_galaxy'=>0,'fleet_end_system'=>10,'fleet_end_planet'=>5,'fleet_end_type'=>1,
  'fleet_start_time'=>1200,'fleet_start_planet_id'=>80,'fleet_array'=>'202,4'];
$store->fleetRows[1] = $leader;
foreach ([7=>'Leader',8=>'Guest',9=>'NPC',10=>'Third',11=>'Fourth',12=>'Fifth',13=>'Sixth'] as $id=>$name) {
  $store->users[$id] = ['id'=>$id,'username'=>$name,'user_bot'=>$id===9?200:0];
}
$cost = static function (array $fleets, int $userId): int {
  return AcsPolicy::playerCost($fleets, $userId, [], static function ($id, $count) { return 10*$count; });
};
$app = new AcsApplicationService($store, ['acs_enabled'=>true], 1000, $cost,
  static function () use ($store) { return $store->rift; });
$reject = function (callable $operation, string $key) use ($check) {
  try { $operation(); }
  catch (AcsFailure $error) { $check($error->getMessage()===$key, 'Expected domain rejection: '.$key); return; }
  throw new RuntimeException('Operation should reject: '.$key);
};
$check(!class_exists('SN', false), 'The ACS application must run without global game bootstrap or SN.');
$check($app->overview(7,1)['fleet']===$leader, 'Read-only overview exposes a manageable fleet.');
$check($app->manage($leader,$store->rift,7,'Guest')===$store->users[8], 'A successful invitation returns its recipient after commit.');
$check($store->events===['begin','rift','native-records','commit'], 'Lifecycle records precede native locks and commit.');
$check($store->groups[1]['eingeladen']==='7,8' && $store->fleetRows[1]['fleet_mission']===MT_AKS,
  'Creation and invitation use native-compatible group and fleet fields.');
$check($app->load(1,8,$store->rift)['fleets']==array_values($store->fleetRows), 'An invited player can inspect the same group.');
$page = $app->overview(7,1);
$check(array_column($page['members'],'username')===['Leader','Guest'], 'A group overview contains presentation-neutral member records.');
$check($app->invitedGroups($store->rift,8)[0]['rift_id']===1, 'Invitation data carries target identity without an HTTP URL.');
$reject(function () use ($app,$store) { $app->manage($store->fleetRows[1],$store->rift,7,'Guest'); }, 'zero_galaxy_acs_already_invited');
$reject(function () use ($app,$store) { $app->manage($store->fleetRows[1],$store->rift,7,'NPC'); }, 'zero_galaxy_acs_player_missing');
$reject(function () use ($app,$store) { $app->load(1,10,$store->rift); }, 'zero_galaxy_acs_missing');
$old = $store->groups[1]['eingeladen']; $store->groups[1]['eingeladen']='7,8,10,11,12';
$reject(function () use ($app,$store) { $app->manage($store->fleetRows[1],$store->rift,7,'Sixth'); }, 'zero_galaxy_acs_full');
$store->groups[1]['eingeladen']=$old;
$store->fleetRows[2]=array_replace($store->fleetRows[1],['fleet_id'=>2,'fleet_array'=>'202,7']);
$reject(function () use ($app,$store) { $app->manage($store->fleetRows[1],$store->rift,7,'Third'); }, 'zero_galaxy_acs_missing');
unset($store->fleetRows[2]);
$store->groups=[]; $store->fleetRows[1]=$leader; $store->fleetRows[1]['fleet_array']='202,11';
$reject(function () use ($app,$store) { $app->manage($store->fleetRows[1],$store->rift,7,''); }, 'zero_galaxy_acs_missing');
$check(!$store->groups && $store->fleetRows[1]['fleet_group']===0, 'An oversized leader rolls back newly created groups.');
$store->fleetRows[1]=$leader;
$tokens = new AcsTokens('pure-acs-test-secret-0123456789');
$token=$tokens->manage(7,1,1000);
$check($tokens->validManage($token,7,1,1000,1000) && !$tokens->validManage($token,8,1,1000,1000), 'Management tokens bind the actor.');
$check(!$tokens->validManage($token,7,1,1000,4601), 'Management tokens retain the existing expiry window.');
$messages=array_fill_keys(['zero_galaxy_acs_title','zero_galaxy_attack_failed','zero_galaxy_attack_expired_form'],'message');
$messages['zero_galaxy_attack_expired_form']='expired';
$notified=0;
$controller=new AcsController($app,$tokens,$messages,1000,'/',static function () use (&$notified) { $notified++; throw new RuntimeException('Notification failure after commit'); });
$module=new AcsPageModule();
try {
  $controller->handle($module,null,$store->users[7],[],['fleetid'=>1,'token_at'=>1000,'acs_token'=>'bad'],'POST');
  throw new RuntimeException('Invalid HTTP signatures must be rejected.');
}
catch (AcsPageFailure $error) { $check($error->getMessage()==='expired' && !$store->groups, 'Invalid HTTP signatures cannot create a group.'); }
$log=ini_set('error_log','/dev/null');
try { $view=$controller->handle($module,null,$store->users[7],[],['fleetid'=>1,'token_at'=>1000,'acs_token'=>$token,'addtogroup'=>'Guest'],'POST'); }
finally { ini_set('error_log',$log); }
$check($notified===1 && $store->groups[1]['eingeladen']==='7,8', 'Notification failure cannot erase committed invitations.');
$check($view->vars['ZERO_ACS_CAN_MANAGE'] && $view->vars['ZERO_ACS_JOIN_URL']==='/index.php?page=zero_galaxy_attack&rift_id=1&acs_group=1',
  'The presenter keeps the native route contract and leader controls.');
echo "ACS application: {$assertions} assertions passed; in-memory ports, no SN, HTTP server or database.\n";
