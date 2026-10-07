<?php

namespace Drupal\Tests\ys_markdown\Kernel;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\ys_beacon\Service\BeaconIndexability;

/**
 * Test stand-in for ys_beacon.indexability.
 *
 * The real service needs metatag, and enabling ys_beacon pulls in the whole
 * AI stack. This keeps the two gates that matter to the route (published, and
 * viewable by anonymous); the metatag opt-out is covered in ys_beacon.
 */
class PublishedAnonymousIndexability extends BeaconIndexability {

  /**
   * {@inheritdoc}
   */
  public function __construct() {
  }

  /**
   * {@inheritdoc}
   */
  public function isIndexable(EntityInterface $entity): bool {
    return $entity->isPublished() && $entity->access('view', new AnonymousUserSession());
  }

}
