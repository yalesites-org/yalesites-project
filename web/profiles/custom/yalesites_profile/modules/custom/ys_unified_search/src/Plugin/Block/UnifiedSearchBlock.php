<?php

namespace Drupal\ys_unified_search\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Unified Search' block.
 *
 * @Block(
 *   id = "unified_search_block",
 *   admin_label = @Translation("Unified Search"),
 *   category = @Translation("Featured Content"),
 *   provider = "ys_unified_search"
 * )
 */
class UnifiedSearchBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new UnifiedSearchBlock instance.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
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
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->configFactory->get('ys_unified_search.settings');
    $search_options = $config->get('search_options') ?? [];

    if (empty($search_options)) {
      return [
        '#markup' => $this->t('No search options configured. Please configure search options at <a href=":url">Unified Search Settings</a>.', [
          ':url' => '/admin/config/search/unified-search',
        ]),
      ];
    }

    // Filter out invalid options
    $valid_options = array_filter($search_options, function($option) {
      return !empty($option['label']) && !empty($option['url']);
    });

    if (empty($valid_options)) {
      return [
        '#markup' => $this->t('No valid search options configured. Please configure search options at <a href=":url">Unified Search Settings</a>.', [
          ':url' => '/admin/config/search/unified-search',
        ]),
      ];
    }

    // Debug output
    \Drupal::messenger()->addMessage('Search options: ' . print_r($search_options, TRUE));
    \Drupal::messenger()->addMessage('Valid options: ' . print_r($valid_options, TRUE));

    return [
      '#theme' => 'block__unified_search_block',
      '#settings' => [
        'search_options' => array_values($valid_options),
      ],
      '#attached' => [
        'library' => [
          'ys_unified_search/unified-search',
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
      '#configuration' => $this->configuration,
      '#plugin_id' => $this->getPluginId(),
      '#base_plugin_id' => $this->getBaseId(),
      '#derivative_plugin_id' => NULL,
      '#attributes' => [
        'class' => ['block', 'block-unified-search'],
      ],
      '#title_prefix' => [],
      '#title_suffix' => [],
    ];
  }

} 