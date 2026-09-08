<?php

namespace Drupal\ys_beacon;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Single source of truth for whether Beacon is authorized on this site.
 *
 * A platform admin authorizes Beacon per site from the Platform Admin Settings
 * page; until then every Beacon surface stays hidden and inert. All Beacon
 * gates (the integration card, the settings and instructions access checks, the
 * content feed, and the indexing hooks) consult this service so the
 * authorization rule lives in exactly one place.
 *
 * The flag is stored in ys_beacon.settings (config_ignored, so it is per-site
 * and survives config import). The config override that forces the chat off for
 * unauthorized sites reads the flag from raw storage rather than through this
 * service, to avoid re-entering the config factory while overrides resolve.
 */
class BeaconAuthorization {

  /**
   * The config object holding the authorization flag.
   */
  public const CONFIG_NAME = 'ys_beacon.settings';

  /**
   * The boolean flag key authorizing Beacon for the site.
   */
  public const FLAG = 'platform_authorized';

  /**
   * Constructs a BeaconAuthorization object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Whether a platform admin has authorized Beacon for this site.
   *
   * @return bool
   *   TRUE when Beacon is authorized for the site.
   */
  public function isAuthorized(): bool {
    return (bool) $this->configFactory->get(self::CONFIG_NAME)->get(self::FLAG);
  }

}
