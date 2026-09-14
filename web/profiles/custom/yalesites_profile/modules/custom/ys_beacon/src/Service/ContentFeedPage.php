<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * One built page of the content feed, with the cacheability of what it fed.
 *
 * The feed renders every item it returns, so only the builder knows which
 * entities and which rendered output the JSON depends on. Returning the payload
 * and that cacheability together lets the controller emit a response the page
 * and edge caches can actually store and correctly invalidate, following the
 * same data-plus-bubbleable-metadata shape core uses for GeneratedUrl and
 * GeneratedLink.
 */
final class ContentFeedPage implements CacheableDependencyInterface {

  use CacheableDependencyTrait;

  /**
   * Constructs a ContentFeedPage object.
   *
   * @param array $payload
   *   The feed payload: data items and pagination, as documented on
   *   \Drupal\ys_beacon\Service\ContentFeedBuilder::build().
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   The cacheability of everything the payload was built from.
   */
  public function __construct(
    public readonly array $payload,
    CacheableMetadata $cacheability,
  ) {
    $this->setCacheability($cacheability);
  }

}
