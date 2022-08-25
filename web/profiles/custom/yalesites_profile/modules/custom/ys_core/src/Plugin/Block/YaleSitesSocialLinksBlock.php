<?php

namespace Drupal\ys_core\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\ys_core\SocialLinksManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds a social links block with links from YS Core footer settings.
 *
 * @Block(
 *   id = "social_links_block",
 *   admin_label = @Translation("Social Links Block"),
 *   category = @Translation("YaleSites Core"),
 * )
 */
class YaleSitesSocialLinksBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Social Links Manager.
   *
   * @var \Drupal\ys_core\SocialLinksManager
   */
  protected $socialLinks;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ys_core.social_links_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, SocialLinksManager $social_links_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->socialLinks = $social_links_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#theme' => 'ys_social_links',
      '#icons' => $this->socialLinks->buildRenderableLinks(),
    ];
  }

}
