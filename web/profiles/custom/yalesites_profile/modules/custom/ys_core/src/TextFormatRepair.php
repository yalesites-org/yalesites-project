<?php

namespace Drupal\ys_core;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;

/**
 * Repairs stored text formats a field's allowed_formats does not permit.
 *
 * A formatted-text field stores a format name alongside its value. When the
 * field instance restricts allowed_formats, core's
 * \Drupal\filter\Element\TextFormat::processFormat() intersects the current
 * user's usable formats with that list and then checks the stored format
 * against the result:
 *
 * @code
 * $formats = filter_formats($user);
 * if (isset($element['#allowed_formats'])) {
 *   $formats = array_intersect_key($formats, array_flip($element['#allowed_formats']));
 * }
 * $user_has_access = isset($formats[$element['#format']]);
 * @endcode
 *
 * So a value stored with any format outside the field's contract disables the
 * widget outright — "This field has been disabled because you do not have
 * sufficient permissions to edit it." — for every user lacking 'administer
 * filters', regardless of whether they could use that format elsewhere on the
 * site. Core's remedy is for an administrator to reassign the format, but no
 * YaleSites role holds 'administer filters' (it permits creating arbitrary
 * formats, a stored-XSS vector), so the reassignment has to happen here.
 *
 * The repair writes the format column directly rather than loading and saving
 * entities. That is deliberate: content_moderation's presave handler rewrites
 * an entity's publication status whenever the stored status disagrees with its
 * moderation state, and that branch is NOT guarded by isSyncing() —
 * @see \Drupal\content_moderation\Entity\Handler\ModerationHandler::onPresave().
 * Re-saving every Resource revision would therefore risk silently unpublishing
 * live pages wherever such a divergence exists, which is exactly the kind of
 * inconsistency an import that bypassed the platform's own importer can leave
 * behind. Writing one column touches nothing else: no new revisions, no change
 * of default revision, no moderation state change, no 'changed' timestamp.
 *
 * Only the format name is corrected; the stored value is never touched, so the
 * repair is reversible by restoring the previous format name.
 *
 * @see yalesites-org/YaleSites-Internal#1646
 */
class TextFormatRepair {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected $cacheTagsInvalidator;

  /**
   * Constructs a new TextFormatRepair.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cache_tags_invalidator
   *   The cache tags invalidator.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    Connection $database,
    CacheTagsInvalidatorInterface $cache_tags_invalidator,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->database = $database;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
  }

  /**
   * Returns the text formats a field instance permits.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle name.
   * @param string $field_name
   *   The field machine name.
   *
   * @return array
   *   The permitted format names, or an empty array when the field is absent
   *   or places no restriction on the format.
   */
  public function getAllowedFormats($entity_type_id, $bundle, $field_name) {
    $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
    if (!isset($definitions[$field_name])) {
      return [];
    }

    $allowed = $definitions[$field_name]->getSetting('allowed_formats');

    return is_array($allowed) ? array_values($allowed) : [];
  }

  /**
   * Returns the format a stored value should be repaired to.
   *
   * @param string|null $stored_format
   *   The format currently stored on the value.
   * @param array $allowed_formats
   *   The formats the field permits, as returned by ::getAllowedFormats().
   *
   * @return string|null
   *   The format to store instead, or NULL when the value needs no repair.
   */
  public function getRepairFormat($stored_format, array $allowed_formats) {
    // An unrestricted field accepts whatever is stored.
    if ($allowed_formats === []) {
      return NULL;
    }

    // An empty value carries no format to correct. Assigning one here would
    // invent data rather than repair it.
    if ($stored_format === NULL || $stored_format === '') {
      return NULL;
    }

    // Already within the contract. Note this accepts ANY permitted format, not
    // just the first one, so a deliberate second choice is preserved.
    if (in_array($stored_format, $allowed_formats, TRUE)) {
      return NULL;
    }

    // Out of contract. The field permits exactly one format in every case this
    // targets; where it permits several, the first is the only defensible
    // default, matching how core picks a default via filter_default_format().
    return reset($allowed_formats);
  }

  /**
   * Corrects out-of-contract stored formats for one field on one bundle.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle name.
   * @param string $field_name
   *   The field machine name.
   *
   * @return int
   *   The number of rows corrected across the field's data and revision
   *   tables.
   */
  public function repairFieldStorage($entity_type_id, $bundle, $field_name) {
    $allowed_formats = $this->getAllowedFormats($entity_type_id, $bundle, $field_name);
    if ($allowed_formats === []) {
      return 0;
    }

    $tables = $this->getFieldTables($entity_type_id, $field_name);
    if ($tables === []) {
      return 0;
    }

    $format_column = $field_name . '_format';
    $entity_ids = [];
    $repaired = 0;

    foreach ($tables as $table) {
      // Grouping by the offending format keeps this set-based (a handful of
      // statements, not one per row) while still routing every decision
      // through getRepairFormat(), so the SQL cannot drift from the rule the
      // unit tests pin down.
      $stored_formats = $this->database->select($table, 't')
        ->distinct()
        ->fields('t', [$format_column])
        ->condition('bundle', $bundle)
        ->execute()
        ->fetchCol();

      foreach ($stored_formats as $stored_format) {
        $repair_format = $this->getRepairFormat($stored_format, $allowed_formats);
        if ($repair_format === NULL) {
          continue;
        }

        // Collect the affected entities before rewriting, so their render
        // caches can be invalidated: writing the column directly bypasses the
        // entity API, which would otherwise do this.
        $entity_ids += array_flip($this->database->select($table, 't')
          ->distinct()
          ->fields('t', ['entity_id'])
          ->condition('bundle', $bundle)
          ->condition($format_column, $stored_format)
          ->execute()
          ->fetchCol());

        $repaired += $this->database->update($table)
          ->fields([$format_column => $repair_format])
          ->condition('bundle', $bundle)
          ->condition($format_column, $stored_format)
          ->execute();
      }
    }

    $this->invalidateEntityCacheTags($entity_type_id, array_keys($entity_ids));

    return $repaired;
  }

  /**
   * Returns the dedicated data and revision tables backing a field.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $field_name
   *   The field machine name.
   *
   * @return array
   *   The table names, or an empty array when the field is not stored in
   *   dedicated tables (a shared-table field has no bundle column to scope by).
   */
  protected function getFieldTables($entity_type_id, $field_name) {
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    if (!$storage instanceof SqlEntityStorageInterface) {
      return [];
    }

    $storage_definitions = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id);
    if (!isset($storage_definitions[$field_name])) {
      return [];
    }

    // The dedicated-table methods below live on DefaultTableMapping, not on
    // TableMappingInterface, so an exotic custom mapping has to be declined
    // rather than assumed.
    $table_mapping = $storage->getTableMapping();
    if (!$table_mapping instanceof DefaultTableMapping) {
      return [];
    }

    $storage_definition = $storage_definitions[$field_name];
    if (!$table_mapping->requiresDedicatedTableStorage($storage_definition)) {
      return [];
    }

    $tables = [$table_mapping->getDedicatedDataTableName($storage_definition)];
    if ($this->entityTypeManager->getDefinition($entity_type_id)->isRevisionable()) {
      $tables[] = $table_mapping->getDedicatedRevisionTableName($storage_definition);
    }

    return $tables;
  }

  /**
   * Invalidates the cache tags of the entities whose rows were rewritten.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param array $entity_ids
   *   The affected entity IDs.
   */
  protected function invalidateEntityCacheTags($entity_type_id, array $entity_ids) {
    if ($entity_ids === []) {
      return;
    }

    $tags = [];
    foreach ($entity_ids as $entity_id) {
      $tags[] = $entity_type_id . ':' . $entity_id;
    }
    $this->cacheTagsInvalidator->invalidateTags($tags);
  }

}
