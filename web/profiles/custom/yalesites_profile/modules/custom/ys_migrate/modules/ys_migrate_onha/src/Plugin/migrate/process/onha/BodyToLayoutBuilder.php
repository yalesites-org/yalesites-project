<?php

namespace Drupal\ys_migrate_onha\Plugin\migrate\process\onha;

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
 * Process plugin to map Drupal 7 body fields to Layout Builder sections.
 *
 * This plugin converts body fields from Drupal 7 nodes into block content
 * entities and incorporates them into Layout Builder sections in Drupal 9/10.
 *
 * @MigrateProcessPlugin(
 *   id = "onha_body_to_layout_builder"
 * )
 */
class BodyToLayoutBuilder extends ProcessPluginBase implements ContainerFactoryPluginInterface {

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
   * Constructs a BodyToLayoutBuilder plugin instance.
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
   * Transforms the source value into a Layout Builder section.
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $components = [];

    // Validate the Drupal 7 source node ID.
    if (!is_numeric($value) || $value <= 0) {
      $this->logger->error('Invalid node ID: @id', ['@id' => $value]);
      return [];
    }
    $nodeId = (int) $value;

    // Get the previously migrated program body block_content based on node id.
    $bodyId = $this->getBodyBlockId($nodeId);
    if (empty($bodyId)) {
      $this->logger->notice('Could not load block @id', ['@id' => $bodyId]);
      return [];
    }

    // Create a section component with the block content.
    $block = $this->entityTypeManager->getStorage('block_content')->load($bodyId);
    $components[] = new SectionComponent($this->uuid->generate(), 'content', [
      'id' => 'inline_block:text',
      'label' => $block->label(),
      'provider' => 'layout_builder',
      'label_display' => FALSE,
      'view_mode' => 'full',
      'block_revision_id' => $block->getRevisionId(),
      'context_mapping' => [],
    ]);

    // Return a one-column Layout Builder section containing the component.
    return new Section('layout_onecol', [], $components);
  }

  /**
   * Retrieves the body block ID for a given Drupal 7 node ID.
   */
  protected function getBodyBlockId(int $nid) {
    $query = $this->database->select('migrate_map_ys_onha_program_body', 'm')
      ->fields('m', ['destid1'])
      ->condition('sourceid1', $nid)
      ->range(0, 1);
    $result = $query->execute()->fetchField();
    return $result ?? NULL;
  }

}
