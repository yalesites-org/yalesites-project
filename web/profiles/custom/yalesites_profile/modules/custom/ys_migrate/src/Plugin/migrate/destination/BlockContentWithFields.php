<?php

namespace Drupal\ys_migrate\Plugin\migrate\destination;

use Drupal\block_content\Plugin\migrate\destination\EntityBlockContent;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\ys_migrate\Plugin\migrate\process\ProcessBlockFields;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Destination plugin for block content that processes fields dynamically.
 *
 * @MigrateDestination(
 *   id = "block_content_with_fields"
 * )
 */
class BlockContentWithFields extends EntityBlockContent {

  /**
   * The field processor.
   *
   * @var \Drupal\ys_migrate\Plugin\migrate\process\ProcessBlockFields
   */
  protected $fieldProcessor;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, LoggerChannelFactoryInterface $logger_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $entity_type_manager, $entity_field_manager);
    
    // Create the field processor
    $this->fieldProcessor = new ProcessBlockFields(
      [],
      'process_block_fields',
      [],
      $entity_field_manager,
      $entity_type_manager,
      $logger_factory
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    \Drupal::logger('ys_migrate')->info('BlockContentWithFields import called for: @info', ['@info' => $row->getSourceProperty('info')]);
    
    // Process fields before importing
    $fields = $row->getSourceProperty('fields');
    if ($fields && is_array($fields)) {
      try {
        // Create a dummy executable for the field processor
        $executable = new \Drupal\migrate_tools\MigrateExecutable($this->migration);
        
        $processed_fields = $this->fieldProcessor->transform(
          $fields,
          $executable,
          $row,
          'fields'
        );
        
        // Set the processed fields on the row
        if (is_array($processed_fields)) {
          foreach ($processed_fields as $field_name => $field_value) {
            if ($field_value !== null) {
              $row->setDestinationProperty($field_name, $field_value);
            }
          }
        }
      } catch (\Exception $e) {
        \Drupal::logger('ys_migrate')->error('Field processing error: @error', ['@error' => $e->getMessage()]);
        \Drupal::logger('ys_migrate')->debug('Row data: @data', ['@data' => print_r($row->getSource(), TRUE)]);
      }
    }

    return parent::import($row, $old_destination_id_values);
  }

}