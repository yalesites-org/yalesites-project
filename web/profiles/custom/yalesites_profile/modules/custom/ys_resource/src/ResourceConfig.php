<?php

namespace Drupal\ys_resource;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Config class for managing campus group configurations.
 *
 * This service provides a way to access and manage the configuration
 * settings for the "ys_resource" module.
 */
class ResourceConfig implements ContainerInjectionInterface {

  use StringTranslationTrait;
  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs the CampusGroupService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory->getEditable('ys_resource.resource_config');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
    );
  }

  /**
   * Gets the configuration for ys_campus_group.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The configuration object.
   */
  public function getConfig() {
    return $this->configFactory;
  }

  /**
   * Gets the custom_vocabulary_label configuration for ys_resource.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The configuration object.
   */
  public function getCustomVocabularyLabel() {
    return $this->configFactory->get('custom_vocabulary_label') ? $this->configFactory->get('custom_vocabulary_label') : $this->t('Type');
  }

}
