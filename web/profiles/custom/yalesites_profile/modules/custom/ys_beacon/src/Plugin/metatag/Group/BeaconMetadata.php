<?php

namespace Drupal\ys_beacon\Plugin\metatag\Group;

use Drupal\metatag\Plugin\metatag\Group\GroupBase;

/**
 * The Beacon AI metadata group.
 *
 * @MetatagGroup(
 *   id = "ys_beacon",
 *   label = @Translation("AI Metadata"),
 *   description = @Translation("AI specific metatags"),
 *   weight = -9
 * )
 */
class BeaconMetadata extends GroupBase {
}
