<?php

namespace Drupal\Tests\ys_markdown\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\ys_beacon\Service\BeaconIndexability;

/**
 * Test stand-in for ys_beacon.indexability.
 *
 * The real service needs metatag, and enabling ys_beacon pulls in the whole
 * AI stack. This keeps the gates that matter to the route (published, viewable
 * by anonymous, and the page's own ai_disable_indexing metatag value).
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
    $metatags = $entity->hasField('field_metatags') ? Json::decode((string) $entity->get('field_metatags')->value) : [];
    return $entity->isPublished()
      && $entity->access('view', new AnonymousUserSession())
      && ($metatags['ai_disable_indexing'] ?? '') !== 'disabled';
  }

}
