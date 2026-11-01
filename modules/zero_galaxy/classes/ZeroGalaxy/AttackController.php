<?php

namespace ZeroGalaxy;

use InvalidArgumentException;
use Throwable;

class AttackController {
  private $storage;
  private $settings;
  private $wizard;

  public function __construct(Storage $storage, array $settings, NativeFleetWizard $wizard) {
    $this->storage = $storage;
    $this->settings = $settings;
    $this->wizard = $wizard;
  }

  public function handle(array $get, array $post, string $method): void {
    $context = $this->wizard->requestContext();
    try {
      $request = AttackRequest::fromHttp($get, $post, $method, $context['sourceId'], $context['now']);
      $request->authorize($context['userId'], $context['now'], $context['secret']);
      $rift = $this->storage->findById($request->riftId, $context['now']);
      if (!$rift || AttackPolicy::limit($rift) === null || empty($rift['planet_id'])
        || !in_array(Lifecycle::phase($rift, $context['now']), ['active', 'unstable'], true)) {
        throw new InvalidArgumentException('zero_galaxy_attack_missing');
      }
      $request->validateTarget($rift);
      $acs = $request->groupId
        ? AcsService::load($request->groupId, $context['userId'], $rift, $this->settings) : null;
      if ($acs && count($acs['fleets']) >= AcsPolicy::MAX_FLEETS) { throw new InvalidArgumentException('zero_galaxy_acs_full'); }
      $page = $this->wizard->prepare($request, $rift, $acs);
      $result = $request->step === 3 ? AttackSender::send($this->storage, $this->settings, $rift,
        $request->ships, $request->speed, $request->captainId, $request->sourceId, $request->groupId) : null;
      $this->wizard->display($request, $rift, $acs, $page, $result, $this->settings);
    } catch (InvalidArgumentException $error) {
      $this->wizard->error($error->getMessage());
    } catch (Throwable $error) {
      error_log('Zero Galaxy attack page: ' . $error->getMessage());
      $this->wizard->error('zero_galaxy_attack_failed');
    }
  }
}
