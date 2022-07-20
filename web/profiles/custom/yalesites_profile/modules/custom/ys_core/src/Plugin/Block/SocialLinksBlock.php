<?php

namespace Drupal\ys_core\Plugin\Block;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Block\BlockBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Provides a 'Hello' Block.
 *
 * @Block(
 *   id = "social_links_block",
 *   admin_label = @Translation("Social Links Block"),
 *   category = @Translation("YaleSites Core"),
 * )
 */
class SocialLinksBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   *
   * @return static
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory')
    );
  }

  /**
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param Drupal\Core\Config\ConfigFactoryInterface $configFactory
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $configFactory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    $config = $this->configFactory->get('ys_core.settings');
    $socialFacebook = $config->get("ys_core.social_facebook_link");
    $socialInstagram = $config->get("ys_core.social_instagram_link");
    $socialTwitter = $config->get("ys_core.social_twitter_link");
    $socialYouTube = $config->get("ys_core.social_youtube_link");
    $socialWeibo = $config->get("ys_core.social_weibo_link");

    return [
      '#theme' => 'social_links_block',
      '#social_links' => [
        'facebook' => $socialFacebook,
        'instagram' => $socialInstagram,
        'twitter' => $socialTwitter,
        'youtube' => $socialYouTube,
        'weibo' => $socialWeibo,
      ],
    ];
  }

}
