<?php

namespace Drupal\ys_templated_content;

use Drupal\Component\Plugin\PluginBase;

/**
 * @file
 * Contains Drupal\ys_templated_content\Modifiers\TemplateModiferBase.
 */

/**
 * Modifies a content import for a unique insertion.
 */
class TemplateModifierBase extends PluginBase implements TemplateModifierInterface {
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
   * The path alias repository.
   *
   * @var \Drupal\path_alias\AliasRepositoryInterface
   */
  protected $pathAliasRepository;

  /**
   * The UUID service.
   *
   * @var \Drupal\Core\Uuid\UuidInterface
   */
  protected $uuidService;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    $this->configuration = $configuration;
    $this->pluginId = $plugin_id;
    $this->pluginDefinition = $plugin_definition;
    $this->uuidService = $configuration['container']->get('uuid');
    $this->pathAliasRepository = $configuration['container']->get('path_alias.repository');
  }

  /**
   * {@inheritdoc}
   */
  public function getExtension(): string {
    return $this->pluginDefinition['extension'];
  }

  /**
   * Process the content array.
   *
   * @param array $content_array
   *   The content array.
   */
  public function process($content_array) {
    return $content_array;
  }

}

