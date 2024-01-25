<?php

namespace Drupal\ys_askyale\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactory;
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
   * Drupal config.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $config;

  /**
   * Constructs an askYale block object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactory $config_factory
   *   Config Factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ConfigFactory $config_factory,
    ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->config = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
    ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $configQuestions = $this->config->get('ys_askyale.settings')->get('initial_questions');
    $questions = [];
    foreach ($configQuestions as $question) {
      $questions[] = $question['question'];
    }

    return [
      '#theme' => 'ys_askyale_chat_block',
      '#azure_root_url' => $this->config->get('ys_askyale.settings')->get('azure_base_url'),
      '#initial_questions' => json_encode($questions),
      '#attached' => [
        'library' => [
          'ys_askyale/react_app',
        ],
      ],
    ];
  }

}
