<?php

namespace Drupal\ys_ai;

use Drupal\ys_beacon\BeaconAuthorization;

/**
 * Answers whether Beacon has superseded the legacy YaleSites AI surfaces.
 *
 * Sites are being moved from ai_engine onto ys_beacon. While both modules are
 * installed a site would otherwise show two AI configuration trees and two
 * system instructions editors, one of them retired. The legacy surfaces are
 * therefore hidden as soon as Beacon is available to the site's admins, which
 * is exactly when a platform admin has authorized Beacon for the site: until
 * then Beacon hides all of its own surfaces, so precisely one tree is ever
 * visible.
 *
 * Deliberately not gated on ai_engine_chat.settings:azure_base_url. The
 * assisted cutover's "Turn off legacy AI Engine" step never clears that value,
 * so a site whose legacy chatbot is fully switched off still looks configured.
 *
 * ys_beacon is an optional dependency: the authorization service is injected
 * with an optional container reference, which resolves to NULL on a site where
 * ys_beacon is not installed. ys_ai then behaves exactly as it always has.
 *
 * @see \Drupal\ys_beacon\BeaconAuthorization
 */
class BeaconSupersession {

  /**
   * Constructs a BeaconSupersession object.
   *
   * @param \Drupal\ys_beacon\BeaconAuthorization|null $beaconAuthorization
   *   The Beacon authorization service, or NULL without ys_beacon.
   */
  public function __construct(
    protected ?BeaconAuthorization $beaconAuthorization = NULL,
  ) {
  }

  /**
   * Whether Beacon supersedes the legacy YaleSites AI surfaces here.
   *
   * @return bool
   *   TRUE when the legacy menu, forms and cards should be hidden.
   */
  public function isSuperseded(): bool {
    return $this->beaconAuthorization?->isAuthorized() ?? FALSE;
  }

  /**
   * The cache tags every answer from this service depends on.
   *
   * @return string[]
   *   Cache tags to add to anything gated on supersession, so that flipping
   *   authorization takes effect without a manual cache rebuild.
   */
  public function getCacheTags(): array {
    // Spelled out rather than derived from BeaconAuthorization::CONFIG_NAME so
    // that reading it never loads a ys_beacon class.
    return ['config:ys_beacon.settings'];
  }

}
