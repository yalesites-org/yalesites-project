<?php

namespace Drupal\ys_askyale\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Block for interfacing with the Yale AI chat.
 *
 * @Block(
 *   id = "ys_askyale_chat_block",
 *   admin_label = @Translation("askYale Chat"),
 *   category = @Translation("askYale Chat"),
 * )
 */
class AskYaleChatBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
      '#theme' => 'ys_askyale_chat_block',
      '#attached' => [
        'library' => [
          'ys_askyale/react_app',
        ],
      ],
    ];
  }

}
