<?php

namespace Drupal\ys_migrate_core\Plugin\migrate\process;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Process plugin to handle block field values dynamically.
 *
 * This plugin processes field values for any block content type by
 * introspecting the field definitions and applying appropriate transformations.
 *
 * @MigrateProcessPlugin(
 *   id = "process_block_fields"
 * )
 */
class ProcessBlockFields extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a ProcessBlockFields plugin instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('migrate');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $block_type = $row->getSourceProperty('type');
    $fields = $row->getSourceProperty('fields');

    if (empty($block_type) || empty($fields)) {
      return $value;
    }

    // Get field definitions for this block type
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('block_content', $block_type);
    $processed_fields = [];

    foreach ($fields as $field_name => $field_value) {
      if (!isset($field_definitions[$field_name])) {
        $this->logger->warning('Field @field not found for block type @type', [
          '@field' => $field_name,
          '@type' => $block_type,
        ]);
        continue;
      }

      $field_definition = $field_definitions[$field_name];
      $field_type = $field_definition->getType();

      $processed_fields[$field_name] = $this->processFieldValue($field_value, $field_type, $field_definition);
    }

    return $processed_fields;
  }

  /**
   * Process a field value based on its type.
   */
  protected function processFieldValue($value, $field_type, $field_definition) {
    switch ($field_type) {
      case 'text':
      case 'string':
        return is_array($value) ? $value : ['value' => $value];

      case 'text_long':
      case 'text_with_summary':
        if (is_array($value)) {
          return $value;
        }
        return [
          'value' => $value,
          'format' => 'basic_html',
        ];

      case 'boolean':
        return (bool) $value;

      case 'list_string':
      case 'list_integer':
        return $value;

      case 'link':
        if (is_array($value)) {
          return $value;
        }
        return [
          'uri' => $value,
          'title' => '',
        ];

      case 'entity_reference':
        return $this->processEntityReference($value, $field_definition);

      case 'entity_reference_revisions':
        return $this->processEntityReferenceRevisions($value, $field_definition);

      default:
        return $value;
    }
  }

  /**
   * Process entity reference field values.
   */
  protected function processEntityReference($value, $field_definition) {
    $target_type = $field_definition->getSetting('target_type');
    
    if (is_numeric($value)) {
      return ['target_id' => $value];
    }

    if (is_array($value) && isset($value['target_id'])) {
      return $value;
    }

    // Handle media references by name or path
    if ($target_type === 'media' && is_string($value)) {
      return $this->processMediaReference($value);
    }

    return $value;
  }

  /**
   * Process entity reference revisions (paragraphs).
   */
  protected function processEntityReferenceRevisions($value, $field_definition) {
    if (empty($value)) {
      return [];
    }

    $target_bundles = $field_definition->getSetting('handler_settings')['target_bundles'] ?? [];
    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    $created_paragraphs = [];

    // Handle single paragraph or array of paragraphs
    $paragraphs = is_array($value) && isset($value[0]) ? $value : [$value];

    foreach ($paragraphs as $paragraph_data) {
      if (!is_array($paragraph_data) || !isset($paragraph_data['type'])) {
        $this->logger->warning('Invalid paragraph data: @data', ['@data' => json_encode($paragraph_data)]);
        continue;
      }

      $paragraph_type = $paragraph_data['type'];
      $paragraph_fields = $paragraph_data['fields'] ?? [];

      // Validate paragraph type is allowed
      if (!empty($target_bundles) && !in_array($paragraph_type, $target_bundles)) {
        $this->logger->warning('Paragraph type @type not allowed for field', ['@type' => $paragraph_type]);
        continue;
      }

      // Create the paragraph entity
      $paragraph = $this->createParagraph($paragraph_type, $paragraph_fields);
      if ($paragraph) {
        $created_paragraphs[] = [
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ];
      }
    }

    return $created_paragraphs;
  }

  /**
   * Create a paragraph entity with field values.
   */
  protected function createParagraph($bundle, array $fields) {
    try {
      $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
      
      // Get field definitions for this paragraph type
      $field_definitions = $this->entityFieldManager->getFieldDefinitions('paragraph', $bundle);
      
      // Start with basic paragraph data
      $paragraph_data = [
        'type' => $bundle,
      ];

      // Process each field
      foreach ($fields as $field_name => $field_value) {
        if (!isset($field_definitions[$field_name])) {
          $this->logger->warning('Field @field not found for paragraph type @type', [
            '@field' => $field_name,
            '@type' => $bundle,
          ]);
          continue;
        }

        $field_definition = $field_definitions[$field_name];
        $field_type = $field_definition->getType();

        // Process field recursively (paragraphs can contain other paragraphs)
        $paragraph_data[$field_name] = $this->processFieldValue($field_value, $field_type, $field_definition);
      }

      // Create and save the paragraph
      $paragraph = $paragraph_storage->create($paragraph_data);
      $paragraph->save();

      $this->logger->info('Created paragraph @type with ID @id', [
        '@type' => $bundle,
        '@id' => $paragraph->id(),
      ]);

      return $paragraph;

    } catch (\Exception $e) {
      $this->logger->error('Failed to create paragraph @type: @error', [
        '@type' => $bundle,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Process media reference by finding media entity.
   */
  protected function processMediaReference($value) {
    // Try to find media by name first
    $media_storage = $this->entityTypeManager->getStorage('media');
    $query = $media_storage->getQuery()
      ->condition('name', $value)
      ->accessCheck(FALSE)
      ->range(0, 1);
    
    $result = $query->execute();
    if (!empty($result)) {
      return ['target_id' => reset($result)];
    }

    $this->logger->warning('Media entity not found: @name', ['@name' => $value]);
    return NULL;
  }

}