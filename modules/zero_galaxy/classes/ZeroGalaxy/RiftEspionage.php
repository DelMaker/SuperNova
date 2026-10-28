<?php

namespace ZeroGalaxy;

use Fleet\MissionEspionage;
use Fleet\MissionEspionageReport;

class RiftEspionage extends MissionEspionage {
  /**
   * Only information concealment is weakened. The native report retains full
   * espionage levels, counter-espionage formulas, messages and probe losses.
   */
  public static function informationDiff(MissionEspionageReport $report): array {
    $empire = $report->attackerSpyLevel - $report->targetSpyLevel / 2;

    return [
      'empire' => $empire,
      'planet' => $empire + sqrt($report->getProbesNumber()) - 1 - $report->getAntiSpyDiff() / 2,
    ];
  }

  protected function doSpying() {
    $this->missionReport = new MissionEspionageReport($this);
    $information = self::informationDiff($this->missionReport);
    if ($information['planet'] >= 2) {
      $this->scanGroup('fleet');
    }
    if ($information['planet'] >= 3) {
      $this->scanGroup('defense');
    }
    if ($information['planet'] >= 5) {
      $this->scanGroup('structures');
    }
    if ($information['empire'] >= 0) {
      $this->scanGroup('tech');
    }

    $this->missionReport->isSpyDetected();
  }
}
