<?php

namespace Drupal\ys_migrate_core\Plugin\migrate\process;

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
 * supporting all block types and section layouts. It can append blocks to
 * existing "Content Section" sections or create new sections as needed.
 *
 * Configuration options:
 * - target_section: Always targets "Content Section" for content blocks
 * - append_mode: When true, adds blocks to existing sections instead of replacing
 * - sections: Array of section configurations with layouts and blocks
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
    $target_section = $this->configuration['target_section'] ?? 'Content Section';
    $append_mode = $this->configuration['append_mode'] ?? false;
    
    // If append mode is enabled, get existing sections and append to target
    if ($append_mode && $target_section) {
      return $this->appendToContentSection($value, $sections_config, $target_section, $row);
    }

    // Default behavior: create new sections
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
        // Create block inline from provided data
        $block_data = $block_config['data'] ?? [];
        if ($block_data && $block_type) {
          $block = $this->createInlineBlock($block_type, $block_data, $row);
        }
        if (!$block) {
          $this->logger->warning('Failed to create inline block of type @type with data: @data', [
            '@type' => $block_type,
            '@data' => json_encode($block_data),
          ]);
        }
        break;

      case 'clone':
        // Clone an existing block by copying its data
        $source_block_id = $block_config['source_block_id'] ?? null;
        if ($source_block_id) {
          $block = $this->cloneExistingBlock($source_block_id, $block_type);
        }
        if (!$block) {
          $this->logger->warning('Failed to clone block @id of type @type', [
            '@id' => $source_block_id,
            '@type' => $block_type,
          ]);
        }
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
   * Append blocks to existing Content Section or create it if missing.
   *
   * This method implements the prescriptive approach of always targeting
   * the "Content Section" for migrated content blocks, ensuring consistency
   * and preventing modification of system sections like headers/footers.
   */
  protected function appendToContentSection($node_id, array $sections_config, string $target_section_label, Row $row) {
    // Load the target node to get existing layout
    $node = $this->entityTypeManager->getStorage('node')->load($node_id);
    if (!$node || !$node->hasField('layout_builder__layout')) {
      $this->logger->warning('Node @id does not have layout builder enabled', ['@id' => $node_id]);
      return [];
    }

    $existing_sections = $node->get('layout_builder__layout')->getSections();
    $content_section_found = false;
    $content_section_index = null;

    // Find existing Content Section
    foreach ($existing_sections as $index => $section) {
      $section_settings = $section->getLayoutSettings();
      if (isset($section_settings['label']) && $section_settings['label'] === $target_section_label) {
        $content_section_found = true;
        $content_section_index = $index;
        break;
      }
    }

    // Create new components from configuration
    $new_components = [];
    foreach ($sections_config as $section_config) {
      $regions = $section_config['regions'] ?? ['content' => $section_config['blocks'] ?? []];
      foreach ($regions as $region => $blocks) {
        foreach ($blocks as $block_config) {
          $component = $this->createSectionComponentFromConfig($block_config, $region, $row);
          if ($component) {
            $new_components[] = $component;
          }
        }
      }
    }

    if ($content_section_found && $content_section_index !== null) {
      // Append to existing Content Section
      $content_section = $existing_sections[$content_section_index];
      $existing_components = $content_section->getComponents();
      
      // Add new components to existing ones
      foreach ($new_components as $component) {
        $content_section->appendComponent($component);
      }
      
      $this->logger->info('Appended @count blocks to existing Content Section in node @id', [
        '@count' => count($new_components),
        '@id' => $node_id,
      ]);
      
      return $existing_sections;
    } else {
      // Create new Content Section and add to existing sections
      $content_section = new Section('layout_onecol', [
        'label' => $target_section_label,
      ], $new_components);
      
      $existing_sections[] = $content_section;
      
      $this->logger->info('Created new Content Section with @count blocks in node @id', [
        '@count' => count($new_components),
        '@id' => $node_id,
      ]);
      
      return $existing_sections;
    }
  }

  /**
   * Create an inline block entity from provided data.
   *
   * @param string $block_type
   *   The block content type.
   * @param array $block_data
   *   The block field data.
   * @param \Drupal\migrate\Row $row
   *   The migration row for token replacement.
   *
   * @return \Drupal\block_content\BlockContentInterface|null
   *   The created block entity or NULL on failure.
   */
   protected function createInlineBlock(string $block_type, array $block_data, Row $row) {
    try {
      // Process token replacements in block data
      $processed_data = $this->processTokens($block_data, $row);
      
      // Create the block entity
      $block_values = [
        'type' => $block_type,
        'info' => $processed_data['info'] ?? 'Migrated ' . $block_type . ' block',
        'reusable' => FALSE, // Inline blocks are not reusable
      ];
      
      // Add field data to block values
      foreach ($processed_data as $field_name => $field_value) {
        if (str_starts_with($field_name, 'field_')) {
          $block_values[$field_name] = $field_value;
        }
      }
      
      $block = $this->entityTypeManager->getStorage('block_content')->create($block_values);
      $block->save();
      
      $this->logger->info('Created inline block @id of type @type', [
        '@id' => $block->id(),
        '@type' => $block_type,
      ]);
      
      return $block;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create inline block: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Clone an existing block by copying its field data.
   *
   * @param int $source_block_id
   *   The ID of the block to clone.
   * @param string $expected_type
   *   The expected block type for validation.
   *
   * @return \Drupal\block_content\BlockContentInterface|null
   *   The cloned block entity or NULL on failure.
   */
  protected function cloneExistingBlock(int $source_block_id, string $expected_type) {
    try {
      // Load the source block
      $source_block = $this->entityTypeManager->getStorage('block_content')->load($source_block_id);
      if (!$source_block) {
        $this->logger->warning('Source block @id not found for cloning', [
          '@id' => $source_block_id,
        ]);
        return NULL;
      }

      // Check if the block is already reusable - if so, we can reuse it
      if ($source_block->get('reusable')->value) {
        $this->logger->info('Reusing reusable block @id instead of cloning', [
          '@id' => $source_block_id,
        ]);
        return $source_block;
      }

      // Validate block type matches expected type
      if ($source_block->bundle() !== $expected_type) {
        $this->logger->warning('Block @id type @actual does not match expected type @expected', [
          '@id' => $source_block_id,
          '@actual' => $source_block->bundle(),
          '@expected' => $expected_type,
        ]);
        return NULL;
      }

      // Create new block with copied field data
      $cloned_values = [
        'type' => $source_block->bundle(),
        'info' => '[CLONED] ' . $source_block->label(),
        'reusable' => FALSE, // Cloned blocks are inline (non-reusable)
      ];

      // Copy all field values from the source block
      $field_definitions = $source_block->getFieldDefinitions();
      foreach ($field_definitions as $field_name => $field_definition) {
        // Skip base fields and computed fields
        if (!$field_definition->isDisplayConfigurable('view') && !str_starts_with($field_name, 'field_')) {
          continue;
        }

        $field_value = $source_block->get($field_name);
        if (!$field_value->isEmpty()) {
          $cloned_values[$field_name] = $field_value->getValue();
        }
      }

      // Create the new block
      $cloned_block = $this->entityTypeManager->getStorage('block_content')->create($cloned_values);
      $cloned_block->save();

      $this->logger->info('Cloned block @source_id to create new block @new_id of type @type', [
        '@source_id' => $source_block_id,
        '@new_id' => $cloned_block->id(),
        '@type' => $expected_type,
      ]);

      return $cloned_block;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to clone block @id: @message', [
        '@id' => $source_block_id,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Process token replacements in data array.
   *
   * Replaces tokens like '@body' with values from the migration row.
   *
   * @param array $data
   *   The data array to process.
   * @param \Drupal\migrate\Row $row
   *   The migration row.
   *
   * @return array
   *   The processed data array.
   */
  protected function processTokens(array $data, Row $row) {
    $processed = [];
    
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        $processed[$key] = $this->processTokens($value, $row);
      }
      elseif (is_string($value) && str_starts_with($value, '@')) {
        // Replace token with value from row
        $token_key = substr($value, 1);
        $processed[$key] = $row->getSourceProperty($token_key) ?? $value;
      }
      else {
        $processed[$key] = $value;
      }
    }
    
    return $processed;
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