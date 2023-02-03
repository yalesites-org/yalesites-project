<?php

namespace Drupal\ys_node_access\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Path\CurrentPathStack;

/**
 * Adds a CAS login block that will show on 403 pages.
 *
 * @Block(
 *   id = "ys_node_access_cas_login_block",
 *   admin_label = @Translation("YaleSites CAS Login Block"),
 *   category = @Translation("YaleSites Node Access"),
 * )
 */
class CASLoginBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current path.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPath;

  /**
   * Constructs a new YaleSitesBreadcrumbBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Path\CurrentPathStack $current_path
   *   The current path.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, CurrentPathStack $current_path) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->currentPath = $current_path;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('path.current'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $currentPath = $this->currentPath->getPath();
    return [
      '#theme' => 'ys_cas_login_block',
      '#destination_url' => $currentPath,
      '#cache' => ['contexts' => ['url.path']],
    ];
  }

}
