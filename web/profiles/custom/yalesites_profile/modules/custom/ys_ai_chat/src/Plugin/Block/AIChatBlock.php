<?php

namespace Drupal\ys_ai_chat\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Block for interfacing with the Yale AI chat.
 *
 * @Block(
 *   id = "ys_ai_chat_block",
 *   admin_label = @Translation("Yale AI Chat"),
 *   category = @Translation("Yale AI Chat"),
 * )
 */
class AIChatBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    return [
      '#theme' => 'ys_ai_chat_block',
      '#attached' => [
        'library' => [
          'ys_ai_chat/react_app',
        ],
      ],
    ];
  }

}
