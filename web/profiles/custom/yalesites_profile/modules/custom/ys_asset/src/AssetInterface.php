<?php

namespace Drupal\ys_asset;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Provides an interface defining a Asset entity.
 *
 * @ingroup ys_asset
 */
interface AssetInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

}
