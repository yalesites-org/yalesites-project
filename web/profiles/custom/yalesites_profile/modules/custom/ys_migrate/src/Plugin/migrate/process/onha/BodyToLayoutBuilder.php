<?php

namespace Drupal\ys_migrate\Plugin\migrate\process\onha;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
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
   * Constructs a BodyToLayoutBuilder plugin instance.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    UuidInterface $uuid,
    LoggerChannelFactoryInterface $logger_factory,
    Connection $database,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->uuid = $uuid;
    $this->logger = $logger_factory->get('migrate');
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('uuid'),
      $container->get('logger.factory'),
      $container->get('database')
    );
  }

  /**
   * Transforms the source value into a Layout Builder section.
   *
   * Converts the Drupal 7 node body field into a Layout Builder section
   * with a block content entity. The block is then used as part of the
   * target node's layout in Drupal 9/10.
   *
   * @param mixed $value
   *   The source node ID from the Drupal 7 migration.
   * @param \Drupal\migrate\MigrateExecutableInterface $migrate_executable
   *   The migrate executable instance.
   * @param \Drupal\migrate\Row $row
   *   The current row being processed.
   * @param string $destination_property
   *   The destination property being mapped.
   *
   * @return \Drupal\layout_builder\Section
   *   The constructed Layout Builder section.
   */
  public function transform(
    $value,
    MigrateExecutableInterface $migrate_executable,
    Row $row,
    $destination_property,
  ) {
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
    $block = BlockContent::load($bodyId);
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
   *
   * Performs a lookup in the migration map table to find the corresponding
   * block_content entity ID for the body field associated with the node.
   *
   * @param int $nid
   *   The source node ID from Drupal 7.
   *
   * @return int|null
   *   The block_content entity ID, or NULL if no match is found.
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
