<?php

namespace Drupal\ys_migrate\Plugin\migrate\process;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Process plugin to create Layout Builder sections from configuration.
 *
 * This plugin allows configuring multiple blocks within sections via YAML,
 * supporting all block types and section layouts.
 *
 * @MigrateProcessPlugin(
 *   id = "configurable_layout_builder"
 * )
 */
class ConfigurableLayoutBuilder extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The UUID service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuid;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a ConfigurableLayoutBuilder plugin instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    UuidInterface $uuid,
    LoggerChannelFactoryInterface $logger_factory,
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->uuid = $uuid;
    $this->logger = $logger_factory->get('migrate');
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('uuid'),
      $container->get('logger.factory'),
      $container->get('database'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $sections_config = $this->configuration['sections'] ?? [];
    $sections = [];

    // If no sections are configured, create a default section
    if (empty($sections_config)) {
      return $this->createDefaultSection($value, $row);
    }

    foreach ($sections_config as $section_config) {
      $section = $this->createSection($section_config, $row);
      if ($section) {
        $sections[] = $section;
      }
    }

    return $sections;
  }

  /**
   * Create a default section (for backward compatibility).
   */
  protected function createDefaultSection($value, Row $row) {
    if (!is_numeric($value) || $value <= 0) {
      $this->logger->error('Invalid node ID: @id', ['@id' => $value]);
      return [];
    }

    $nodeId = (int) $value;
    $bodyId = $this->getBlockId($nodeId, 'ys_onha_program_body');
    
    if (empty($bodyId)) {
      $this->logger->notice('Could not load block @id', ['@id' => $bodyId]);
      return [];
    }

    $components = [];
    $block = $this->entityTypeManager->getStorage('block_content')->load($bodyId);
    if ($block) {
      $components[] = $this->createSectionComponent($block, 'text', 'content');
    }

    return new Section('layout_onecol', [], $components);
  }

  /**
   * Create a section from configuration.
   */
  protected function createSection(array $section_config, Row $row) {
    $layout = $section_config['layout'] ?? 'layout_onecol';
    $layout_settings = $section_config['layout_settings'] ?? [];
    $regions = $section_config['regions'] ?? ['content' => $section_config['blocks'] ?? []];
    
    $components = [];

    foreach ($regions as $region => $blocks) {
      foreach ($blocks as $block_config) {
        $component = $this->createSectionComponentFromConfig($block_config, $region, $row);
        if ($component) {
          $components[] = $component;
        }
      }
    }

    return new Section($layout, $layout_settings, $components);
  }

  /**
   * Create a section component from block configuration.
   */
  protected function createSectionComponentFromConfig(array $block_config, string $region, Row $row) {
    $block_type = $block_config['type'];
    $block_source = $block_config['source'] ?? 'migration';

    $block = NULL;

    switch ($block_source) {
      case 'migration':
        // Get block from migration map
        $migration_id = $block_config['migration_id'] ?? null;
        $source_id = $block_config['source_id'] ?? $row->getSourceProperty('id');
        
        if ($migration_id && $source_id) {
          $block_id = $this->getBlockId($source_id, $migration_id);
          if ($block_id) {
            $block = $this->entityTypeManager->getStorage('block_content')->load($block_id);
          }
        }
        break;

      case 'existing':
        // Use existing block by ID
        $block_id = $block_config['block_id'];
        if ($block_id) {
          $block = $this->entityTypeManager->getStorage('block_content')->load($block_id);
        }
        break;

      case 'create':
        // Create block inline (this would need additional logic)
        $this->logger->warning('Inline block creation not yet implemented');
        break;
    }

    if (!$block) {
      $this->logger->warning('Could not load block for configuration: @config', [
        '@config' => json_encode($block_config),
      ]);
      return NULL;
    }

    return $this->createSectionComponent($block, $block_type, $region, $block_config);
  }

  /**
   * Create a section component from a block entity.
   */
  protected function createSectionComponent($block, string $block_type, string $region, array $config = []) {
    $component_config = [
      'id' => 'inline_block:' . $block_type,
      'label' => $block->label(),
      'provider' => 'layout_builder',
      'label_display' => $config['label_display'] ?? FALSE,
      'view_mode' => $config['view_mode'] ?? 'full',
      'block_revision_id' => $block->getRevisionId(),
      'context_mapping' => $config['context_mapping'] ?? [],
    ];

    // Add any additional component configuration
    if (isset($config['component_settings'])) {
      $component_config = array_merge($component_config, $config['component_settings']);
    }

    return new SectionComponent(
      $this->uuid->generate(),
      $region,
      $component_config
    );
  }

  /**
   * Retrieves a block ID from migration map table.
   */
  protected function getBlockId($source_id, string $migration_id) {
    $query = $this->database->select('migrate_map_' . $migration_id, 'm')
      ->fields('m', ['destid1'])
      ->condition('sourceid1', $source_id)
      ->range(0, 1);
    
    $result = $query->execute()->fetchField();
    return $result ?? NULL;
  }

}