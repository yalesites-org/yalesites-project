<?php

namespace Drupal\ys_embed\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\ys_embed\Plugin\EmbedSourceManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation embed formatter.
 *
 * @FieldFormatter(
 *   id = "embed_formatter",
 *   label = @Translation("Embed Default Formatter"),
 *   field_types = {"embed"}
 * )
 */
class EmbedDefaultFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The embed source plugin manager service.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSourceManager
   */
  protected $embedManager;

  /**
   * Constructs an embed formatter object.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\ys_embed\Plugin\EmbedSourceManager $embed_manager
   *   The embed source plugin manager service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger channel factory.
   */
  public function __construct(
    string $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    string $label,
    string $view_mode,
    array $third_party_settings,
    EmbedSourceManager $embed_manager,
    LoggerChannelFactoryInterface $logger
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $label,
      $view_mode,
      $third_party_settings
    );
    $this->embedManager = $embed_manager;
    $this->logger = $logger->get('ys_embed');
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
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('plugin.manager.embed_source'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    foreach ($items as $delta => $item) {
      if (is_string($item->embed_source) && is_array($item->params)) {
        // Each EmbedSource defines its own template for rendering.
        $plugin = $this->embedManager->loadPluginById($item->embed_source);
        // Get params from media object. Add title to the list of params.
        $params = $item->params;
        $params['title'] = $item->title;
        $elements[$delta] = $plugin->build($params);
      }
      elseif (is_string($item->provider)) {
        $this->logger->error(sprintf(
          '%s::render cannot be called for %s with %s',
          get_class($this->embedManager),
          $item->embed_source,
          gettype($item->params)
        ));
      }
      else {
        $this->logger->error(sprintf(
          '%s::render cannot be called without an embed source provider',
          get_class($this->embedManager)
        ));
      }
    }
    return $elements;
  }

}
