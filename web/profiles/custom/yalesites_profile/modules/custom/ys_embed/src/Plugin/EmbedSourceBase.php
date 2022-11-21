<?php

namespace Drupal\ys_embed\Plugin;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for EmbedSource plugins.
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
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->config = $config_factory->get('media.settings');
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
    // @todo Consider moving this to templates.
    // @todo Complete title as a param.
    $params['title'] = 'Add me here';
    return [
      '#type' => 'inline_template',
      '#template' => static::$template,
      '#context' => $params,
    ];
  }

}
