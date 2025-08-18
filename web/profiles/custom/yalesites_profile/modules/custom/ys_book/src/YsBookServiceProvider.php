<?php

namespace Drupal\ys_book;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Service provider for YS Book module.
 *
 * This service provider ensures that YsExpandBookManager is used instead
 * of the default BookManager or any other competing book manager services
 * from contrib modules like custom_book_block.
 */
class YsBookServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    // Let custom_book_block module handle the service definition, but replace
    // the class to use our YsExpandBookManager which extends their
    // ExpandBookManager.
    if ($container->hasDefinition('book.manager')) {
      $definition = $container->getDefinition('book.manager');
      $definition->setClass('Drupal\ys_book\YsExpandBookManager');
      // Disable lazy loading to preserve inheritance for instanceof checks.
      $definition->setLazy(FALSE);
    }
  }

}
