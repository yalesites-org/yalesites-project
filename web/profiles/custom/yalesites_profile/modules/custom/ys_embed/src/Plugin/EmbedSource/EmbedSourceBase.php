<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * Base class for embed source plugins.
 */
abstract class EmbedSourceBase extends PluginBase implements EmbedSourceInterface, ContainerFactoryPluginInterface {

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The pattern to match the embed code.
   *
   * @var string
   */
  protected static $pattern = '/(?<embed_code>.*)/s';

  /**
   * The template to use for rendering.
   *
   * @var string
   */
  protected static $template = <<<EOT
  <div class="embed"></div>
  EOT;

  /**
   * The instructions for using this embed source.
   *
   * @var string
   */
  protected static $instructions = 'Place embed code here.';

  /**
   * An example of the embed code.
   *
   * @var string
   */
  protected static $example = '';

  /**
   * Constructs a new EmbedSourceBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin definition.
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
  public function matches($embed_code) {
    $logger = $this->loggerFactory->get('ys_embed');
    $logger->notice('Checking matches for @class with pattern: @pattern', [
      '@class' => get_class($this),
      '@pattern' => static::$pattern,
    ]);

    $matches = preg_match(static::$pattern, $embed_code, $match);

    $logger->notice('Match result for @class: @result', [
      '@class' => get_class($this),
      '@result' => $matches ? 'TRUE' : 'FALSE',
    ]);

    return $matches;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $params): array {
    return [
      '#markup' => '<div class="embed" data-embed-code="' . htmlspecialchars($params['embed_code'] ?? '') . '"></div>',
      '#attached' => [
        'library' => ['ys_embed/embed'],
        'html_head' => [
          [
            [
              '#tag' => 'link',
              '#attributes' => [
                'rel' => 'stylesheet',
                'href' => '/profiles/custom/yalesites_profile/modules/custom/ys_embed/css/embed.css',
              ],
            ],
            'ys_embed_embed_css',
          ],
          [
            [
              '#tag' => 'script',
              '#attributes' => [
                'src' => '/profiles/custom/yalesites_profile/modules/custom/ys_embed/js/embed.js',
              ],
            ],
            'ys_embed_embed_js',
          ],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isValid(string $embed_code): bool {
    return (bool) preg_match(static::$pattern, $embed_code);
  }

  /**
   * {@inheritdoc}
   */
  public function getEmbedCode(string $embed_code): string {
    preg_match(static::$pattern, $embed_code, $matches);
    return $matches['embed_code'] ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getTemplate(): string {
    return static::$template;
  }

  /**
   * {@inheritdoc}
   */
  public function getInstructions(): string {
    return static::$instructions;
  }

  /**
   * {@inheritdoc}
   */
  public function getExample(): string {
    return static::$example;
  }

}
