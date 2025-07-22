<?php

namespace Drupal\ys_book;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Service provider to override the book manager with our YSExpandBookManager.
 * 
 * This takes precedence over the contrib custom_book_block module's service
 * provider to ensure our CAS-enabled book navigation logic is used.
 */
class YsBookServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $definition = $container->getDefinition('book.manager');
    $definition->setClass(YSExpandBookManager::class)
      ->addArgument(new Reference('current_route_match'));
  }

}