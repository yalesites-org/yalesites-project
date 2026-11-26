<?php

namespace Drupal\ys_core;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\ys_core\Search\UpdateKernelDeferredIndexing;

/**
 * Alters service definitions owned by other modules.
 */
class YsCoreServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    // Search API is a dependency of the search feature rather than of ys_core,
    // so it is not guaranteed to be installed.
    if (!$container->hasDefinition('search_api.post_request_indexing')) {
      return;
    }

    // See UpdateKernelDeferredIndexing for why direct indexing has to be
    // skipped under the update kernel. Search API's definition is autowired
    // and stays that way, so the subclass's constructor is resolved from its
    // own type hints rather than from a copy of contrib's argument list here.
    $container->getDefinition('search_api.post_request_indexing')
      ->setClass(UpdateKernelDeferredIndexing::class);
  }

}
