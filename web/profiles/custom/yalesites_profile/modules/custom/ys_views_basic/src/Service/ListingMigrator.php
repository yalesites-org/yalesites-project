<?php

namespace Drupal\ys_views_basic\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\ys_views_basic\ViewsBasicManager;
use Psr\Log\LoggerInterface;

/**
 * Shared helpers for the listing block migrations.
 *
 * The deploy hooks in ys_views_basic.deploy.php and the post-update in
 * ys_views_basic.post_update.php re-bundle legacy listing blocks in place and
 * rewrite their Layout Builder placements. This service holds the work those
 * hooks share: patching the bundle column on the dedicated field tables,
 * copying resource params across revisions, rewriting inline block plugin
 * ids in the stored layouts (in chunks, so the hooks can batch), counting
 * placements, and discarding the Layout Builder drafts a rewrite made stale.
 *
 * Table names always come from the entity table mapping: dedicated tables
 * whose names exceed the database limit are stored under hashed names.
 */
class ListingMigrator {

  /**
   * How many blocks, or layout revisions, a migration handles per pass.
   */
  const BATCH_SIZE = 50;

  /**
   * The key-value collection behind Layout Builder's override tempstore.
   *
   * This is the collection name SharedTempStoreFactory::get() builds for the
   * 'layout_builder.section_storage.overrides' tempstore.
   */
  const OVERRIDES_TEMPSTORE_COLLECTION = 'tempstore.shared.layout_builder.section_storage.overrides';

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The expirable key-value factory.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface
   */
  protected KeyValueExpirableFactoryInterface $keyValueExpirable;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The existing dedicated block_content field tables, resolved once.
   *
   * @var string[]|null
   */
  protected ?array $blockFieldTables = NULL;

  /**
   * The resource params copy plan, resolved once.
   *
   * @var array|null
   */
  protected ?array $resourceCopyPlan = NULL;

  /**
   * The existing Layout Builder layout tables, resolved once.
   *
   * @var array|null
   */
  protected ?array $layoutTables = NULL;

  /**
   * Constructs a ListingMigrator object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $key_value_expirable
   *   The expirable key-value factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    KeyValueExpirableFactoryInterface $key_value_expirable,
    LoggerInterface $logger,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->keyValueExpirable = $key_value_expirable;
    $this->logger = $logger;
  }

  /**
   * Patches the bundle column on every block_content field table for a block.
   *
   * A bundle swap + save updates the base tables but leaves dedicated
   * field-table rows stamped with the old bundle, on every revision the save
   * did not rewrite. Field values still load by entity id, but the bundle
   * column is patched so that deleting the old bundle's field instances does
   * not purge rows an older block revision still renders.
   *
   * @param int|string $entity_id
   *   The block content entity id.
   * @param string $target
   *   The new bundle id.
   */
  public function patchBlockFieldBundles(int|string $entity_id, string $target): void {
    foreach ($this->blockFieldTables() as $table) {
      $this->database->update($table)
        ->fields(['bundle' => $target])
        ->condition('entity_id', $entity_id)
        ->execute();
    }
  }

  /**
   * Returns the dedicated block_content field tables that exist.
   *
   * Resolved once through the table mapping, so hashed table names are found
   * and no schema query runs per migrated block.
   *
   * @return string[]
   *   The data and revision table names.
   */
  protected function blockFieldTables(): array {
    if ($this->blockFieldTables !== NULL) {
      return $this->blockFieldTables;
    }
    $this->blockFieldTables = [];
    $storage = $this->entityTypeManager->getStorage('block_content');
    if (!$storage instanceof SqlEntityStorageInterface) {
      return $this->blockFieldTables;
    }
    /** @var \Drupal\Core\Entity\Sql\DefaultTableMapping $mapping */
    $mapping = $storage->getTableMapping();
    $schema = $this->database->schema();
    foreach ($this->entityFieldManager->getFieldStorageDefinitions('block_content') as $definition) {
      if (!$mapping->requiresDedicatedTableStorage($definition)) {
        continue;
      }
      $tables = [$mapping->getDedicatedDataTableName($definition)];
      if ($storage->getEntityType()->isRevisionable() && $definition->isRevisionable()) {
        $tables[] = $mapping->getDedicatedRevisionTableName($definition);
      }
      foreach ($tables as $table) {
        if ($schema->tableExists($table)) {
          $this->blockFieldTables[] = $table;
        }
      }
    }
    return $this->blockFieldTables;
  }

  /**
   * Copies a block's resource params into field_view_params, every revision.
   *
   * Reads field_view_resource_params from its data and revision tables and
   * writes the normalised params into field_view_params' matching rows. Table
   * and column names come from the table mapping: the resource field's
   * revision table name is long enough that Drupal stores it under a hashed
   * name.
   *
   * @param int|string $entity_id
   *   The block content entity id.
   * @param string $target
   *   The block's new bundle id.
   */
  public function copyResourceParams(int|string $entity_id, string $target): void {
    $plan = $this->resourceCopyPlan();
    foreach ($plan['tables'] as [$source_table, $destination_table, $keys]) {
      $rows = $this->database->select($source_table, 's')
        ->fields('s')
        ->condition('entity_id', $entity_id)
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);
      foreach ($rows as $row) {
        $raw = (string) $row[$plan['source_column']];
        if ($raw === '') {
          continue;
        }
        // The params column is declared 'serialize', so it holds a serialized
        // JSON string.
        $json = unserialize($raw, ['allowed_classes' => FALSE]);
        if ($json === FALSE && $raw !== serialize(FALSE)) {
          $this->logger->warning('Listing migration: could not unserialize params in @table entity @e revision @r.', [
            '@table' => $source_table,
            '@e' => $row['entity_id'],
            '@r' => $row['revision_id'],
          ]);
          continue;
        }
        $decoded = is_string($json) ? json_decode($json, TRUE) : NULL;
        if (!is_array($decoded)) {
          continue;
        }
        $this->database->merge($destination_table)
          ->keys(array_intersect_key($row, array_flip($keys)))
          ->fields([
            'bundle' => $target,
            'revision_id' => $row['revision_id'],
            $plan['destination_column'] => serialize(json_encode(ViewsBasicManager::normalizeResourceParams($decoded))),
          ])
          ->execute();
      }
    }
  }

  /**
   * Resolves the source and destination tables for copyResourceParams().
   *
   * @return array
   *   An array with 'tables' (a list of [source table, destination table,
   *   primary key columns]) and, when both fields exist, 'source_column' and
   *   'destination_column'.
   */
  protected function resourceCopyPlan(): array {
    if ($this->resourceCopyPlan !== NULL) {
      return $this->resourceCopyPlan;
    }
    $this->resourceCopyPlan = ['tables' => []];
    $storage_definitions = $this->entityFieldManager->getFieldStorageDefinitions('block_content');
    $source = $storage_definitions['field_view_resource_params'] ?? NULL;
    $destination = $storage_definitions['field_view_params'] ?? NULL;
    $storage = $this->entityTypeManager->getStorage('block_content');
    if (!$source || !$destination || !$storage instanceof SqlEntityStorageInterface) {
      return $this->resourceCopyPlan;
    }
    /** @var \Drupal\Core\Entity\Sql\DefaultTableMapping $mapping */
    $mapping = $storage->getTableMapping();
    $this->resourceCopyPlan['source_column'] = $mapping->getFieldColumnName($source, 'params');
    $this->resourceCopyPlan['destination_column'] = $mapping->getFieldColumnName($destination, 'params');
    $candidates = [
      [
        $mapping->getDedicatedDataTableName($source),
        $mapping->getDedicatedDataTableName($destination),
        ['entity_id', 'deleted', 'delta', 'langcode'],
      ],
      [
        $mapping->getDedicatedRevisionTableName($source),
        $mapping->getDedicatedRevisionTableName($destination),
        ['entity_id', 'revision_id', 'deleted', 'delta', 'langcode'],
      ],
    ];
    $schema = $this->database->schema();
    foreach ($candidates as $candidate) {
      if ($schema->tableExists($candidate[0]) && $schema->tableExists($candidate[1])) {
        $this->resourceCopyPlan['tables'][] = $candidate;
      }
    }
    return $this->resourceCopyPlan;
  }

  /**
   * Returns the Layout Builder layout tables (data and revision) that exist.
   *
   * @return array
   *   A list of arrays keyed 'table', 'column' (the serialized section
   *   column) and 'entity_type_id'.
   */
  protected function layoutTables(): array {
    if ($this->layoutTables !== NULL) {
      return $this->layoutTables;
    }
    $this->layoutTables = [];
    $schema = $this->database->schema();
    foreach ($this->entityFieldManager->getFieldMap() as $entity_type_id => $fields) {
      if (!isset($fields['layout_builder__layout'])) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $definition = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id)['layout_builder__layout'] ?? NULL;
      if (!$definition || !$storage instanceof SqlEntityStorageInterface) {
        continue;
      }
      /** @var \Drupal\Core\Entity\Sql\DefaultTableMapping $mapping */
      $mapping = $storage->getTableMapping();
      $column = $mapping->getFieldColumnName($definition, 'section');
      $tables = [$mapping->getDedicatedDataTableName($definition)];
      if ($storage->getEntityType()->isRevisionable() && $definition->isRevisionable()) {
        $tables[] = $mapping->getDedicatedRevisionTableName($definition);
      }
      foreach ($tables as $table) {
        if ($schema->tableExists($table)) {
          $this->layoutTables[] = [
            'table' => $table,
            'column' => $column,
            'entity_type_id' => $entity_type_id,
          ];
        }
      }
    }
    return $this->layoutTables;
  }

  /**
   * Counts layout rows whose serialized section contains a needle.
   *
   * @param string $needle
   *   The substring to look for in the serialized section blob.
   *
   * @return int
   *   The number of matching rows.
   */
  public function countPlacements(string $needle): int {
    $count = 0;
    foreach ($this->layoutTables() as $info) {
      $count += (int) $this->database->select($info['table'], 't')
        ->condition('t.' . $info['column'], '%' . $this->database->escapeLike($needle) . '%', 'LIKE')
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    return $count;
  }

  /**
   * Rewrites legacy inline-block plugin ids to their migrated bundle, a chunk.
   *
   * Walks every layout_builder__layout row (data and revision tables), and
   * for each component whose plugin id is one of the given legacy ids
   * (inline_block:<legacy>), loads the referenced block revision and, if that
   * block has been re-bundled to a listing bundle, rewrites the id to
   * inline_block:<bundle>. Reading the block's current bundle (rather than a
   * per-run map) makes this idempotent and covers all revisions, so
   * publishing an old draft cannot resurrect a legacy plugin id pointing at a
   * re-bundled block (ADR DR-9/DR-10).
   *
   * Each call handles up to BATCH_SIZE layout revisions and records its
   * progress in $state, so a hook can call it once per $sandbox pass until it
   * returns TRUE. Pass an empty array on the first call.
   *
   * @param array $legacy_bundles
   *   Legacy block content bundle ids whose inline_block:<bundle> placements
   *   should be rewritten (e.g. ['view'] or ['post_list', 'event_list']).
   * @param array $state
   *   The progress state, carried between calls. Once the walk is complete it
   *   holds 'rewritten' (the number of layout rows rewritten) and 'entities'
   *   (the "entity_type_id.entity_id" keys of the entities whose layouts
   *   were rewritten).
   *
   * @return bool
   *   TRUE once every layout table has been walked.
   */
  public function rewritePlacements(array $legacy_bundles, array &$state): bool {
    if (!isset($state['table'])) {
      $state += [
        'table' => 0,
        'cursor' => 0,
        'rewritten' => 0,
        'entities' => [],
      ];
    }
    $tables = $this->layoutTables();
    $legacy_ids = array_map(fn($bundle) => 'inline_block:' . $bundle, $legacy_bundles);

    while ($state['table'] < count($tables)) {
      $info = $tables[$state['table']];
      $revision_ids = $this->legacyRowQuery($info, $legacy_ids)
        ->distinct()
        ->fields('t', ['revision_id'])
        ->condition('t.revision_id', $state['cursor'], '>')
        ->orderBy('t.revision_id')
        ->range(0, static::BATCH_SIZE)
        ->execute()
        ->fetchCol();
      if (!$revision_ids) {
        $state['table']++;
        $state['cursor'] = 0;
        continue;
      }
      $rows = $this->legacyRowQuery($info, $legacy_ids)
        ->fields('t')
        ->condition('t.revision_id', $revision_ids, 'IN')
        ->execute();
      foreach ($rows as $row) {
        if ($this->rewriteRow($info, $row, $legacy_ids)) {
          $state['rewritten']++;
          $state['entities'][$info['entity_type_id'] . '.' . $row->entity_id] = TRUE;
        }
      }
      $state['cursor'] = max($revision_ids);
      // Block revisions loaded above are not kept in the entity memory cache,
      // so memory stays bounded per pass without a resetCache() here.
      return FALSE;
    }

    $state['entities'] = array_keys($state['entities']);
    if ($state['rewritten']) {
      $this->logger->notice('Listing migration: rewrote @n layout rows referencing @ids.', [
        '@n' => $state['rewritten'],
        '@ids' => implode(', ', $legacy_ids),
      ]);
      // The rewrite bypassed the entity API, and drush deploy clears no cache
      // after the deploy hooks run, so an entity loaded before this ran would
      // keep serving its old plugin ids. resetCache() with no ids invalidates
      // each storage's persistent <type>_values cache tag.
      foreach (array_unique(array_column($tables, 'entity_type_id')) as $entity_type_id) {
        $this->entityTypeManager->getStorage($entity_type_id)->resetCache();
      }
    }
    return TRUE;
  }

  /**
   * Builds a query for layout rows mentioning any legacy plugin id.
   *
   * @param array $info
   *   A layout table entry from layoutTables().
   * @param string[] $legacy_ids
   *   The legacy inline_block:<bundle> plugin ids.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The select query, with no fields added.
   */
  protected function legacyRowQuery(array $info, array $legacy_ids) {
    $query = $this->database->select($info['table'], 't');
    $group = $query->orConditionGroup();
    foreach ($legacy_ids as $legacy_id) {
      $group->condition('t.' . $info['column'], '%' . $this->database->escapeLike($legacy_id . '"') . '%', 'LIKE');
    }
    return $query->condition($group);
  }

  /**
   * Rewrites the legacy placements in one layout row.
   *
   * @param array $info
   *   A layout table entry from layoutTables().
   * @param object $row
   *   The layout row, with every column.
   * @param string[] $legacy_ids
   *   The legacy inline_block:<bundle> plugin ids.
   *
   * @return bool
   *   TRUE if the row was rewritten.
   */
  protected function rewriteRow(array $info, object $row, array $legacy_ids): bool {
    $table = $info['table'];
    $raw = (string) $row->{$info['column']};
    $section = unserialize($raw, [
      'allowed_classes' => [Section::class, SectionComponent::class],
    ]);
    if (!$section instanceof Section) {
      if ($section !== FALSE || $raw !== serialize(FALSE)) {
        $this->logger->warning('Listing migration: could not unserialize the layout section in @table entity @e revision @r.', [
          '@table' => $table,
          '@e' => $row->entity_id,
          '@r' => $row->revision_id,
        ]);
      }
      return FALSE;
    }

    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $changed = FALSE;
    foreach ($section->getComponents() as $component) {
      $config = $component->get('configuration');
      if (!in_array($config['id'] ?? NULL, $legacy_ids, TRUE)) {
        continue;
      }
      $rid = $config['block_revision_id'] ?? NULL;
      $block = $rid ? $block_storage->loadRevision($rid) : NULL;
      if ($block && isset(ViewsBasicManager::LISTING_BUNDLES[$block->bundle()])) {
        $config['id'] = 'inline_block:' . $block->bundle();
        $component->setConfiguration($config);
        $changed = TRUE;
      }
      else {
        // A legacy placement whose block was not migrated (e.g. an
        // orphaned/deleted block revision). Flag it for manual follow-up
        // rather than silently leaving it.
        $this->logger->warning('Listing migration: unrewritten @id placement in @table entity @e revision @r references block revision @rid.', [
          '@id' => $config['id'] ?? 'NULL',
          '@table' => $table,
          '@e' => $row->entity_id,
          '@r' => $row->revision_id,
          '@rid' => $rid ?? 'NULL',
        ]);
      }
    }
    if (!$changed) {
      return FALSE;
    }
    // Match the exact row read, including langcode/deleted, so the update is
    // unambiguous even if layouts ever become translatable.
    $this->database->update($table)
      ->fields([$info['column'] => serialize($section)])
      ->condition('entity_id', $row->entity_id)
      ->condition('revision_id', $row->revision_id)
      ->condition('delta', $row->delta)
      ->condition('langcode', $row->langcode)
      ->condition('deleted', $row->deleted)
      ->execute();
    return TRUE;
  }

  /**
   * Discards the pending Layout Builder override edits of the given entities.
   *
   * A rewritten layout leaves any draft of that entity pointing at plugin ids
   * that no longer match, so those drafts are thrown away. Drafts of other
   * entities are kept. Override tempstore keys are
   * "<entity_type_id>.<entity_id>" optionally followed by ".<view_mode>" and
   * ".<langcode>" (OverridesSectionStorage::getTempstoreKey()).
   *
   * @param string[] $entity_keys
   *   "entity_type_id.entity_id" keys, as returned by rewritePlacements().
   *
   * @return int
   *   The number of tempstore entries deleted.
   */
  public function clearLayoutTempstore(array $entity_keys): int {
    if (!$entity_keys) {
      return 0;
    }
    $wanted = array_flip($entity_keys);
    $store = $this->keyValueExpirable->get(static::OVERRIDES_TEMPSTORE_COLLECTION);
    $delete = [];
    foreach (array_keys($store->getAll()) as $key) {
      $parts = explode('.', (string) $key);
      if (count($parts) >= 2 && isset($wanted[$parts[0] . '.' . $parts[1]])) {
        $delete[] = $key;
      }
    }
    if ($delete) {
      $store->deleteMultiple($delete);
    }
    return count($delete);
  }

  /**
   * Discards every pending Layout Builder override edit on the site.
   *
   * Only deploy_10000 uses this; the later migrations clear just the
   * entities they rewrote (clearLayoutTempstore()).
   */
  public function clearAllLayoutTempstore(): void {
    $this->keyValueExpirable->get(static::OVERRIDES_TEMPSTORE_COLLECTION)->deleteAll();
  }

}
