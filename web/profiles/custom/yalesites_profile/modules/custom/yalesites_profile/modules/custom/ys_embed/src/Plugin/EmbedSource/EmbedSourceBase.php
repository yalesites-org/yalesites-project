<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
      '@result' => $matches ? 'true' : 'false',
    ]);

    return $matches;
  }

} 