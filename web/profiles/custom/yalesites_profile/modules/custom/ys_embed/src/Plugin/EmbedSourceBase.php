<?php

namespace Drupal\ys_embed\Plugin;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for EmbedSource plugins.
 *
 * This plugin is used as a media source for embeded content. It is useful for
 * social media and streaming meadia providers as well as content shared in
 * an iframe. The plugin stores the 'input' (raw embed code added by the user),
 * validates the code, and renders the code through a inline Drupal template.
 *
 * New plugins are discovered through annotations. Several are included in this
 * modules in the ys_embed\Plugin\EmbedSource namespace.
 */
abstract class EmbedSourceBase extends PluginBase implements EmbedSourceInterface, ContainerFactoryPluginInterface {

  /**
   * The media settings config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * A regex to match an embed code to the source plugin.
   *
   * The regex should used named groups that are stored in the 'params' field
   * in the database and used to populated the template.
   *
   * @var string
   */
  protected static $pattern;

  /**
   * The name of the Drupal template for this code.
   *
   * @var string
   */
  protected static $template;

  /**
   * Instructions for finding the embed code in on third party website.
   *
   * @var string
   */
  protected static $instructions;

  /**
   * An example of the embed code.
   *
   * @var string
   */
  protected static $example;

  /**
   * An array of attributes to add to the template.
   *
   * To support previous implementations, embed_type is set to 'form'.
   *
   * @var array
   */
  protected static $displayAttributes = [
    'embed_type' => 'form',
  ];

  /**
   * Creates a plugin instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ConfigFactoryInterface $config_factory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->config = $config_factory->get('media.settings');
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
  public function getDefaultThumbnailUri(): string {
    $thumbnail = $this->getPluginDefinition()['thumbnail'];
    return $this->config->get('icon_base_uri') . '/' . $thumbnail;
  }

  /**
   * {@inheritdoc}
   */
  public static function isValid(?string $input): bool {
    return !empty(preg_match(static::$pattern, $input, $matches));
  }

  /**
   * {@inheritdoc}
   */
  public function getParams(string $input): array {
    preg_match(static::$pattern, $input, $matches);
    return $matches ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public static function getInstructions(): string {
    return static::$instructions;
  }

  /**
   * {@inheritdoc}
   */
  public static function getExample(): string {
    return static::$example;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $params): array {
    $isIframe = $this->isIframe();
    $displayAttributes = static::$displayAttributes ?? [];
    if (!isset($displayAttributes['isIframe'])) {
      $displayAttributes['isIframe'] = $isIframe;
    }

    return [
      '#theme' => 'embed_wrapper',
      '#embedType' => $this->getPluginId(),
      '#title' => $params['title'],
      '#url' => $this->getUrl($params),
      '#displayAttributes' => $displayAttributes,
      '#embedSource' => [
        '#type' => 'inline_template',
        '#template' => static::$template,
        '#context' => $params,
      ],
    ];
  }

  /**
   * Retrieves a URL using the params array.
   *
   * @param array $params
   *   An array of params.
   *
   * @return string
   *   The URL.
   */
  public function getUrl(array $params): string {
    return $params['url'] ?? '';
  }

  /**
   * Determines if the template is an iframe.
   *
   * @return bool
   *   TRUE if the template is an iframe, FALSE otherwise.
   */
  protected function isIframe(): bool {
    return strpos(static::$template, 'iframe') !== FALSE ? TRUE : FALSE;
  }

}
