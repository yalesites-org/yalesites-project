<?php

namespace Drupal\ys_templated_content;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Entity\EntityInterface;

/**
 * Base class for template importers.
 */
class TemplateImporterBase extends PluginBase implements TemplateImporterInterface {

  /**
   * The plugin id.
   *
   * @var string
   */
  protected $pluginId;

  /**
   * The plugin definition.
   *
   * @var mixed
   */
  protected $pluginDefinition;

  /**
   * The configuration.
   *
   * @var array
   */
  protected $configuration;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    $this->configuration = $configuration;
    $this->pluginId = $plugin_id;
    $this->pluginDefinition = $plugin_definition;
  }

  /**
   * {@inheritdoc}
   */
  public function import(string $filename): EntityInterface | NULL {
    throw new \Exception('Not implemented');
  }

  /**
   * {@inheritdoc}
   */
  public function getExtension(): string {
    return $this->pluginDefinition['extension'];
  }

}
