<?php

namespace Drupal\ys_ai_chatbot\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Controller\TitleResolver;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Block for interfacing with the Yale AI chatbot.
 *
 * @Block(
 *   id = "ys_ai_chatbot",
 *   admin_label = @Translation("Yale AI Chatbot"),
 *   category = @Translation("YaleSites AI Chatbot"),
 * )
 */
class AIChatbotBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
      '#theme' => 'ys_ai_chatbot_block',
      '#attached' => [
        'library' => [
          'ys_ai_chatbot/react_app',
        ],
      ],
    ];
  }

}
