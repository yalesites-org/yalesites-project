<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\ContentFeedBuilder;

/**
 * Tests which render cache contexts the feed carries onto a built page.
 *
 * This encodes a security rule rather than a tidiness preference: the feed's
 * cache key has to be a superset of everything that varies the response body,
 * or a caller can vary the body with an argument outside the key and poison
 * the entry served to the next caller.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\ContentFeedBuilder
 */
class ContentFeedBuilderCacheContextsTest extends UnitTestCase {

  /**
   * Contexts the forced anonymous account switch makes constant are dropped.
   *
   * @covers ::collectableCacheContexts
   */
  public function testUserAndSessionContextsAreDropped(): void {
    $kept = ContentFeedBuilder::collectableCacheContexts([
      'user',
      'user.permissions',
      'user.node_grants:view',
      'user.roles:authenticated',
      'session',
      'session.exists',
    ]);

    $this->assertSame([], $kept);
  }

  /**
   * Contexts that genuinely vary the rendered body are kept.
   *
   * The load-bearing case is url / url.query_args: a node whose layout embeds
   * a Content List block renders a view with exposed filters, and an exposed
   * filter reads the current request's query string.
   *
   * @covers ::collectableCacheContexts
   */
  public function testVaryingContextsAreKept(): void {
    $contexts = [
      'url',
      'url.query_args',
      'url.query_args:page',
      'url.site',
      'languages:language_interface',
      'theme',
      'route',
    ];

    $this->assertSame($contexts, ContentFeedBuilder::collectableCacheContexts($contexts));
  }

  /**
   * A context merely spelled like one of the dropped ones is kept.
   *
   * Cache contexts nest with '.' and take parameters with ':', so the rule
   * matches that hierarchy rather than raw characters - otherwise a contrib
   * context such as one named for a "usergroup" would be silently dropped from
   * the cache key, which is the direction that causes poisoning.
   *
   * @covers ::collectableCacheContexts
   */
  public function testLookalikeContextsAreNotDropped(): void {
    $contexts = ['usergroup', 'user_something', 'sessions_active'];

    $this->assertSame($contexts, ContentFeedBuilder::collectableCacheContexts($contexts));
  }

  /**
   * A mixed list keeps the varying contexts and returns a packed list.
   *
   * @covers ::collectableCacheContexts
   */
  public function testMixedListIsFilteredAndReindexed(): void {
    $kept = ContentFeedBuilder::collectableCacheContexts([
      'user.permissions',
      'url.query_args',
      'session',
      'theme',
    ]);

    $this->assertSame(['url.query_args', 'theme'], $kept);
  }

}
