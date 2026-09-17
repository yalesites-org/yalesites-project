<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\ys_core\Search\UpdateKernelDeferredIndexing;

/**
 * Tests that ys_core takes over Search API's direct-indexing service.
 *
 * The guard in UpdateKernelDeferredIndexing is only reached if the container
 * hands out our class for search_api.post_request_indexing, and it only ever
 * fires if the kernel autowired into it is the real `kernel` service. Both are
 * invisible to a unit test, and both fail silently rather than loudly: the
 * HttpKernelInterface type hint also matches `http_kernel`, which is never an
 * UpdateKernel, so a mis-resolved argument would leave the deferral dead and
 * the bug back with nothing to show for it.
 *
 * @group ys_core
 * @group yalesites
 */
class SearchApiUpdateKernelIndexingOverrideTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'search_api', 'ys_core'];

  /**
   * The container serves our subclass in place of Search API's.
   */
  public function testPostRequestIndexingIsOverridden(): void {
    $this->assertInstanceOf(
      UpdateKernelDeferredIndexing::class,
      $this->container->get('search_api.post_request_indexing')
    );
  }

  /**
   * The autowired kernel is the one whose class the guard tests.
   */
  public function testKernelArgumentResolvesToTheKernelService(): void {
    $service = $this->container->get('search_api.post_request_indexing');
    $property = new \ReflectionProperty($service, 'kernel');
    $property->setAccessible(TRUE);

    $this->assertSame(
      $this->container->get('kernel'),
      $property->getValue($service)
    );
  }

}
