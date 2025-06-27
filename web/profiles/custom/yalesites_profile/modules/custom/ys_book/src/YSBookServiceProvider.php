<?php

namespace Drupal\ys_book;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Defines a book manager which extends the core BookManager class.
 */
class YSBookServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $definition = $container->getDefinition('book.manager');
    $definition->setClass(YSExpandBookManager::class)
      ->addArgument(new Reference('current_route_match'));
  }

}
