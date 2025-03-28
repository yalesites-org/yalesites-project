<?php

namespace Drupal\ys_embed\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'libcal_embed' formatter.
 *
 * @FieldFormatter(
 *   id = "libcal_embed",
 *   label = @Translation("LibCal Embed"),
 *   field_types = {
 *     "embed"
 *   }
 * )
 */
class LibCalEmbedFormatter extends FormatterBase {

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a new LibCalEmbedFormatter.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $logger_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      $embed_code = $item->get('input')->getValue();
      $this->loggerFactory->get('ys_embed')->notice('LibCalEmbedFormatter: Processing embed code: @code.', ['@code' => $embed_code]);

      // Check for weekly grid embed.
      if (strpos($embed_code, 'hours_grid.js') !== FALSE || strpos($embed_code, 'LibCalWeeklyGrid') !== FALSE) {
        $this->loggerFactory->get('ys_embed')->notice('LibCalEmbedFormatter: Detected weekly grid embed.');
        $elements[$delta] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => [
            'class' => ['embed-libcal-weekly'],
            'data-embed-code' => $embed_code,
            'data-processed' => 'true',
          ],
        ];
      }
      // Check for daily hours embed.
      elseif (strpos($embed_code, 'hours_today.js') !== FALSE || strpos($embed_code, 'LibCalTodayHours') !== FALSE) {
        $this->loggerFactory->get('ys_embed')->notice('LibCalEmbedFormatter: Detected daily hours embed.');
        $elements[$delta] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => [
            'class' => ['embed-libcal'],
            'data-embed-code' => $embed_code,
            'data-processed' => 'true',
          ],
        ];
      }
      else {
        $this->loggerFactory->get('ys_embed')->notice('LibCalEmbedFormatter: Unknown embed type.');
        $elements[$delta] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => [
            'class' => ['embed-libcal-unknown'],
            'data-embed-code' => $embed_code,
            'data-processed' => 'true',
          ],
        ];
      }
    }

    return $elements;
  }

}
