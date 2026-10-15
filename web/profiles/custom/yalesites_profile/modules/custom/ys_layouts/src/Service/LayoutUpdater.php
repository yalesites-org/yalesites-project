<?php

namespace Drupal\ys_layouts\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\block_content\Entity\BlockContent;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Helper tool for updating layout configurations across existing nodes.
 *
 * YaleSites utilizes Layout Builder as a tool for composing content, allowing
 * each node to have a unique set of configuration overrides. However, making
 * changes to the default content type display does not necessarily propagate
 * those changes to existing content.
 *
 * This tool iterates through all existing nodes to manually apply any new
 * locks or configurations to the overridden layout. It can be extended in the
 * future to accommodate other types of updates, such as adding or removing
 * default sections or blocks.
 *
 * ::enableSeventyThirtyDividers() is batched (it takes a $sandbox and slices
 * DIVIDER_BATCH_SIZE nodes per pass). updateLocks() is not yet.
 *
 * @todo Consider using the Batch API to execute updateLocks in smaller chunks.
 */
class LayoutUpdater {

  use StringTranslationTrait;

  /**
   * The layout id of the Two column (70/30) section.
   *
   * Matches ys_layouts.layouts.yml.
   */
  const SEVENTY_THIRTY_LAYOUT_ID = 'ys_layout_two_column';

  /**
   * Nodes converted per batch pass by ::enableSeventyThirtyDividers().
   *
   * Each node costs up to two revision loads and two saves, so the slice is
   * sized for a comfortable request rather than for throughput -- a deploy
   * that takes a few more passes is cheaper than one that times out half way
   * through and leaves the platform with some sections converted and some not.
   *
   * A constant rather than config, matching the sibling service
   * OrphanedInlineBlockCleaner::BATCH_SIZE (also 50): ys_layouts ships no
   * settings object, so there is no form this could be read from. If one is
   * ever added, this is a value to move into it.
   */
  const DIVIDER_BATCH_SIZE = 50;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManager
   */
  protected $entityFieldManager;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Drupal messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The expirable key/value factory, to list cached layouts.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface
   */
  protected $keyValueExpirable;

  /**
   * The shared tempstore factory, to write cached layouts.
   *
   * @var \Drupal\Core\TempStore\SharedTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * Constructs a new LayoutUpdater object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityFieldManager $entity_field_manager
   *   The entity field manager service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $key_value_expirable
   *   The expirable key/value factory.
   * @param \Drupal\Core\TempStore\SharedTempStoreFactory $temp_store_factory
   *   The shared tempstore factory.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManager $entity_field_manager,
    LoggerInterface $logger,
    MessengerInterface $messenger,
    KeyValueExpirableFactoryInterface $key_value_expirable,
    SharedTempStoreFactory $temp_store_factory,
  ) {
    $this->configFactory = $config_factory;
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->logger = $logger;
    $this->messenger = $messenger;
    $this->keyValueExpirable = $key_value_expirable;
    $this->tempStoreFactory = $temp_store_factory;
  }

  /**
   * Get a list of all content types.
   *
   * @return array|null
   *   An array of all content types or NULL.
   */
  public function getContentTypes() {
    return $this->entityTypeManager->getStorage('node_type')->loadMultiple();
  }

  /**
   * Get lock values for each section of a content type as defined in config.
   *
   * YaleSites uses layout builder on the default display of all content types.
   * The layout_builder_lock module is used to improve the authoring experience.
   * Get the third party lock settings for each locked section of a content
   * type. Sections are not keyed by layout ID because a content type can have
   * several sections with the same layout (posts have two layout_onecol). The
   * returned array is a list of:
   * @code
   * [
   *   'layout_id' => 'ys_layout_banner',
   *   'label' => 'Banner',
   *   'lock' => [5 => 5, 6 => 6, 8 => 8],
   * ],
   * @endcode
   *
   * @param string $nodeBundleId
   *   The machine name of the content type (node bundle).
   *
   * @return array
   *   A list of layout ID, section label, and lock values per locked section.
   */
  public function getLockConfigs($nodeBundleId) {
    $name = "core.entity_view_display.node.{$nodeBundleId}.default";
    $config = $this->configFactory->get($name);
    $lb = $config->get('third_party_settings.layout_builder');
    $locks = [];

    // Iterate over each layout builder section to get the locks for each one.
    if (!empty($lb['sections']) && is_array($lb['sections'])) {
      foreach ($lb['sections'] as $section) {
        if (!empty($section['third_party_settings']['layout_builder_lock']['lock']) && is_array($section['third_party_settings']['layout_builder_lock']['lock'])) {
          $locks[] = [
            'layout_id' => $section['layout_id'],
            'label' => $section['layout_settings']['label'] ?? NULL,
            'lock' => $section['third_party_settings']['layout_builder_lock']['lock'],
          ];
        }
      }
    }
    return $locks;
  }

  /**
   * Gets the node IDs of nodes with a specific content type.
   *
   * @param string $nodeBundleId
   *   The machine name of the content type (node bundle).
   *
   * @return int[]
   *   An array of node IDs.
   */
  public function getAllNodeIds($nodeBundleId) {
    return $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $nodeBundleId)
      ->execute();
  }

  /**
   * Updates existing nodes to apply default layout locks for a content type.
   *
   * This method iterates through all nodes of a content type to updates thier
   * layout sections to apply default layout locks defined for that type.
   *
   * @param string $nodeBundleId
   *   The machine name of the content type (node bundle).
   */
  public function updateLocks($nodeBundleId) {
    $defaultLocks = $this->getLockConfigs($nodeBundleId);
    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    foreach ($this->getAllNodeIds($nodeBundleId) as $nid) {

      // Load the node or exit early if the node does not exist.
      $node = $storage->load($nid);
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $revisions = [$node];

      // Layout Builder edits the latest revision, so a pending draft needs the
      // same locks. Update it in place rather than creating a new revision.
      // Content moderation would otherwise force one; see setSyncing() below.
      $latestId = $storage->getLatestRevisionId($nid);
      if ($latestId && $latestId != $node->getRevisionId()) {
        $latest = $storage->loadRevision($latestId);
        if ($latest instanceof NodeInterface) {
          $latest->setNewRevision(FALSE);
          $revisions[] = $latest;
        }
      }

      foreach ($revisions as $revision) {
        // Load the layout builder sections. Skip if none are set.
        /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
        $layout = $revision->get('layout_builder__layout');
        if ($layout->isEmpty()) {
          continue;
        }
        $this->applyDefaultLocks($defaultLocks, $layout->getSections());

        // Saving stamps the request time as the changed time, so note each
        // translation's changed time to put back afterwards.
        $changed = [];
        foreach (array_keys($revision->getTranslationLanguages()) as $langcode) {
          $changed[$langcode] = $revision->getTranslation($langcode)->getChangedTime();
        }

        // Syncing stops content moderation from creating a new revision and
        // changing which revision is default. Without it, saving the default
        // revision would also bury a pending draft under a newer revision.
        $revision->setSyncing(TRUE);
        try {
          $revision->save();
          $this->restoreChangedTimes($revision, $changed);
        }
        catch (EntityStorageException $e) {
          $this->logger->error(
            'Error updating locks for node with ID @nid: @message',
            ['@nid' => $nid, '@message' => $e->getMessage()]
          );
        }
      }
      $storage->resetCache([$nid]);
    }

    $this->updateCachedLayouts($nodeBundleId, $defaultLocks);
  }

  /**
   * Applies the default locks to the sections that match a default section.
   *
   * @param array $defaultLocks
   *   The result of getLockConfigs().
   * @param \Drupal\layout_builder\Section[] $sections
   *   The sections to update.
   */
  protected function applyDefaultLocks(array $defaultLocks, array $sections): void {
    foreach ($sections as $section) {
      // Authors can create their own sections. Only update sections that
      // match one of the default sections defined for this content type.
      $locks = $this->findDefaultLocks($defaultLocks, $section);
      if ($locks !== NULL) {
        $section->setThirdPartySetting('layout_builder_lock', 'lock', $locks);
      }
    }
  }

  /**
   * Puts a saved revision's changed times back to what they were.
   *
   * ChangedItem::preSave() has no way to keep the stored value when other
   * fields change, so this writes it back to the node tables directly.
   *
   * @param \Drupal\node\NodeInterface $revision
   *   The saved revision.
   * @param int[] $changed
   *   Changed times keyed by langcode, from before the save.
   */
  protected function restoreChangedTimes(NodeInterface $revision, array $changed): void {
    $tables = ['node_field_revision' => ['vid', $revision->getRevisionId()]];
    if ($revision->isDefaultRevision()) {
      $tables['node_field_data'] = ['nid', $revision->id()];
    }
    foreach ($changed as $langcode => $time) {
      foreach ($tables as $table => [$column, $id]) {
        $this->database->update($table)
          ->fields(['changed' => $time])
          ->condition($column, $id)
          ->condition('langcode', $langcode)
          ->execute();
      }
    }
  }

  /**
   * Applies the default locks to layouts cached by Layout Builder.
   *
   * Opening a node's Layout tab caches its layout for days. Saving that copy
   * later would restore the old locks, so update it in place. Deleting it would
   * throw away an editor's unsaved work.
   *
   * @param string $nodeBundleId
   *   The machine name of the content type (node bundle).
   * @param array $defaultLocks
   *   The result of getLockConfigs().
   */
  protected function updateCachedLayouts(string $nodeBundleId, array $defaultLocks): void {
    $collection = 'layout_builder.section_storage.overrides';
    $items = $this->keyValueExpirable->get("tempstore.shared.$collection")->getAll();
    foreach ($items as $key => $item) {
      $sectionStorage = $item->data['section_storage'] ?? NULL;
      if (!str_starts_with($key, 'node.') || !$sectionStorage instanceof SectionStorageInterface) {
        continue;
      }
      if ($sectionStorage->getContextValue('entity')->bundle() !== $nodeBundleId) {
        continue;
      }
      $this->applyDefaultLocks($defaultLocks, $sectionStorage->getSections());
      // Write as the original owner so the editor keeps their lock on it.
      $this->tempStoreFactory->get($collection, $item->owner)->set($key, $item->data);
    }
  }

  /**
   * Finds the default locks for a node section.
   *
   * Matches on layout ID and section label. A section with no label falls back
   * to layout ID only, and only when exactly one default shares that layout.
   *
   * @param array $defaultLocks
   *   The result of getLockConfigs().
   * @param \Drupal\layout_builder\Section $section
   *   The node's section.
   *
   * @return array|null
   *   The lock values, or NULL if the section is not a default section.
   */
  protected function findDefaultLocks(array $defaultLocks, Section $section): ?array {
    $candidates = array_filter($defaultLocks, fn($default) => $default['layout_id'] === $section->getLayoutId());
    if (!$candidates) {
      return NULL;
    }
    $label = $section->getLayoutSettings()['label'] ?? NULL;
    if (empty($label)) {
      return count($candidates) === 1 ? reset($candidates)['lock'] : NULL;
    }
    foreach ($candidates as $default) {
      if ($default['label'] === $label) {
        return $default['lock'];
      }
    }
    return NULL;
  }

  /**
   * Executes the updateLocks method for all content types.
   */
  public function updateAllLocks() {
    foreach ($this->getContentTypes() as $bundle) {
      $this->updateLocks($bundle->id());
    }
  }

  /**
   * Opts every existing Two column (70/30) section into its divider.
   *
   * The 70/30 separator used to be drawn unconditionally in the component
   * library, so the Divider checkbox did nothing on that layout while 50/50
   * and 33/33/33 respected it. Now that the separator is gated on the toggle
   * (yalesites-project#1514), a stored `divider` of 0 -- which is what every
   * 70/30 section on the platform has, since the control was inert -- would
   * silently remove a line the section renders today.
   *
   * Turning it on preserves current rendering, and from here on the toggle
   * behaves like it does on the other multi-column layouts: off by default on
   * a new section, per YSLayoutOptions::defaultConfiguration().
   *
   * Only sections already carrying a truthy `divider` are skipped, so this is
   * idempotent and re-running it saves nothing. Sections whose layout has
   * never been overridden are not stored on the node at all -- those come
   * from the content type's default display in config, which carries its own
   * `divider` value.
   *
   * **The pending draft is migrated as well as the published revision.** The
   * content types that offer this layout are under content moderation, so a
   * node can have a published default revision plus an unpublished draft
   * holding its own copy of the sections. Migrating only the default revision
   * would look correct until the editor published that draft, at which point
   * the separator would vanish -- the exact regression this exists to prevent.
   * Only the default and the latest revision are touched: publishing promotes
   * the latest one, and older revisions are history that nothing renders, so
   * rewriting them would multiply the deploy's writes for no visible effect.
   * A non-default revision is written in place, leaving the
   * default-revision pointer and the moderation state alone.
   *
   * One window this does NOT cover: a layout an editor has open in Layout
   * Builder but has not saved lives in the shared tempstore (see
   * getTempStoreNids() / getTempStoreNodes()) with the old value, and saving
   * it after the deploy writes that stale copy back. Deliberately left alone
   * -- rewriting someone's unsaved work mid-edit is more surprising than the
   * narrow risk, and re-ticking the box fixes it.
   *
   * @return int
   *   The number of revisions saved.
   */
  public function enableSeventyThirtyDividers(?array &$sandbox = NULL): int {
    // Unbatched call -- no $sandbox handed in. Converts the whole set in one
    // pass, which is what the tests and any manual drush invocation want.
    if ($sandbox === NULL) {
      return $this->convertSeventyThirtyRevisions($this->getSeventyThirtyNodeIds());
    }

    // First pass: enumerate the work once and keep it in the sandbox. The
    // query is a LIKE over every stored layout section, so it is not something
    // to re-run on each pass.
    if (!isset($sandbox['ids'])) {
      $sandbox['ids'] = $this->getSeventyThirtyNodeIds();
      $sandbox['total'] = count($sandbox['ids']);
      $sandbox['updated'] = 0;
    }

    $slice = array_splice($sandbox['ids'], 0, self::DIVIDER_BATCH_SIZE);
    $sandbox['updated'] += $this->convertSeventyThirtyRevisions($slice);

    // Drush's deploy:hook reads '#finished'; 1 ends the batch. Guard the
    // divide so an empty candidate set finishes rather than dividing by zero.
    $sandbox['#finished'] = $sandbox['total'] > 0
      ? 1 - (count($sandbox['ids']) / $sandbox['total'])
      : 1;

    return $sandbox['updated'];
  }

  /**
   * Opts the given nodes' 70/30 sections into their divider.
   *
   * Split out of ::enableSeventyThirtyDividers() so the batched and unbatched
   * paths share one implementation rather than drifting apart.
   *
   * @param int[] $nids
   *   Node ids to convert.
   *
   * @return int
   *   The number of revisions saved.
   */
  protected function convertSeventyThirtyRevisions(array $nids): int {
    $updated = 0;
    /** @var \Drupal\node\NodeStorageInterface $nodeStorage */
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    foreach ($nids as $nid) {
      $node = $nodeStorage->load($nid);
      if (!$node instanceof NodeInterface) {
        continue;
      }

      // The published revision, plus the pending draft when there is one.
      $vids = [(int) $node->getRevisionId()];
      $latest = (int) $nodeStorage->getLatestRevisionId($nid);
      if ($latest && !in_array($latest, $vids, TRUE)) {
        $vids[] = $latest;
      }

      foreach ($vids as $vid) {
        $revision = $vid === (int) $node->getRevisionId()
          ? $node
          : $nodeStorage->loadRevision($vid);
        if ($revision instanceof NodeInterface && $this->addDividerToSections($revision)) {
          $updated++;
        }
      }

      // Release each node from the entity static cache as we go. The batch
      // above bounds how many nodes one request touches; this bounds how much
      // memory those touches accumulate within the request.
      $nodeStorage->resetCache([$nid]);
    }

    return $updated;
  }

  /**
   * Opts one revision's 70/30 sections into the divider and saves it.
   *
   * @param \Drupal\node\NodeInterface $revision
   *   The node revision to update.
   *
   * @return bool
   *   TRUE when the revision needed the change and was saved.
   */
  protected function addDividerToSections(NodeInterface $revision): bool {
    /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
    $layout = $revision->get('layout_builder__layout');
    $changed = FALSE;

    foreach ($layout->getSections() as $section) {
      if ($section->getLayoutId() !== self::SEVENTY_THIRTY_LAYOUT_ID) {
        continue;
      }
      $settings = $section->getLayoutSettings();
      // Already opted in, so re-running this is a no-op.
      if (!empty($settings['divider'])) {
        continue;
      }
      $settings['divider'] = 1;
      $section->setLayoutSettings($settings);
      $changed = TRUE;
    }

    if (!$changed) {
      return FALSE;
    }

    try {
      // Update this revision in place: without setNewRevision(FALSE) the save
      // spawns a revision, and the loaded revision already carries its own
      // default-revision flag, so a draft stays a draft. setSyncing() keeps
      // content_moderation from reading this as an editor's state change.
      $revision->setNewRevision(FALSE);
      $revision->setSyncing(TRUE);
      $revision->save();

      return TRUE;
    }
    catch (EntityStorageException $e) {
      $this->logger->error(
        'Error enabling the 70/30 divider on node revision @vid: @message',
        ['@vid' => $revision->getRevisionId(), '@message' => $e->getMessage()]
      );

      return FALSE;
    }
  }

  /**
   * Gets the IDs of nodes whose stored layout holds a 70/30 section.
   *
   * A cheap pre-filter so enableSeventyThirtyDividers() does not load every
   * node on the site to discover that most of them have nothing to change.
   * Queries the layout field's own storage the way getTempStoreNids() queries
   * the tempstore table, rather than through the entity API, because the
   * layout section is a serialized blob that an entity query cannot filter on.
   *
   * Reads the REVISION table rather than the default-revision one, so a node
   * whose 70/30 section exists only in an unpublished draft is still a
   * candidate.
   *
   * The LIKE is a deliberate superset: it matches the layout id anywhere in
   * the serialized sections, so `ys_layout_two_column_50_50` matches as well
   * and the caller still checks each section's real layout id. A false
   * positive costs one wasted load; a false negative would silently skip
   * content, which this cannot produce.
   *
   * @return int[]
   *   Node IDs, or an empty array when no node has an overridden layout.
   */
  protected function getSeventyThirtyNodeIds(): array {
    $table = 'node_revision__layout_builder__layout';

    // Absent until at least one node's layout is overridden.
    if (!$this->database->schema()->tableExists($table)) {
      return [];
    }

    $ids = $this->database->select($table, 'l')
      ->distinct()
      ->fields('l', ['entity_id'])
      ->condition(
        'l.layout_builder__layout_section',
        '%' . $this->database->escapeLike(self::SEVENTY_THIRTY_LAYOUT_ID) . '%',
        'LIKE'
      )
      ->execute()
      ->fetchCol();

    // fetchCol() returns strings; cast so the documented type is the real one,
    // matching getTempStoreNids().
    return array_map('intval', $ids);
  }

  /**
   * Gets a list of nodes with sections stored in the temporary storage.
   *
   * This method retrieves node IDs from the temporary storage table. These are
   * layout builder nodes stored in an autosaved state.
   *
   * @return array|null
   *   An array of node IDs if found, or NULL.
   */
  public function getTempStoreNids() {
    if (!$this->database->schema()->tableExists('key_value_expire')) {
      return;
    }
    $collectionId = 'tempstore.shared.layout_builder.section_storage.overrides';
    $query = $this->database->select('key_value_expire', 'kve');
    $query->fields('kve', ['name'])
      ->condition('kve.collection', $collectionId)
      ->condition('kve.name', 'node.%', 'LIKE');
    // Pluck out the node id from the name field, eg: "node.102.default.en".
    $query->addExpression("SUBSTRING_INDEX(SUBSTRING_INDEX(kve.name, '.', 2), '.', -1)", 'nid');
    // Fetch just the nid column.
    $results = $query->distinct()->execute()->fetchCol(1);
    // Cast node ids to ints as the query returns strings.
    return array_map('intval', $results);
  }

  /**
   * Gets a list of all nodes with sections stored in the temporary storage.
   *
   * This method retrieves and loads nodes from the temporary storage table.
   * These are layout builder nodes with sections stored in an autosaved state.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   An array of nodes.
   */
  public function getTempStoreNodes() {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $nids = $this->getTempStoreNids();
    return $nodeStorage->loadMultiple($nids);
  }

  /**
   * Gets all custom block types to allow for updating placed blocks later.
   *
   * @return array
   *   An array to use as options for a select list in the settings form.
   */
  public function getBlockTypes() {
    $blockTypes = $this->entityTypeManager->getStorage('block_content_type')->loadMultiple();
    $customBlockTypes = ['' => 'Select'];
    foreach ($blockTypes as $blockType) {
      $customBlockTypes[$blockType->id()] = $blockType->label();
    }

    return $customBlockTypes;
  }

  /**
   * Gets all fields on a specified block type that are text-based.
   *
   * @return array
   *   An array to use as options for a select list in the settings form.
   */
  public function getTextBlockFields($blockType) {
    $fieldDefinitions = $this->entityFieldManager->getFieldDefinitions('block_content', $blockType);
    $blockFields = [];
    foreach ($fieldDefinitions as $fieldName => $fieldDefinition) {
      // Only select the user-created fields.
      if (str_starts_with($fieldName, 'field_')) {
        // Only select text-based fields.
        if (str_starts_with($fieldDefinition->getType(), 'text')) {
          $blockFields[$fieldName] = $fieldDefinition->getLabel();
        }
      }
    }

    return $blockFields;
  }

  /**
   * Executes text format updates for all existing layout builder blocks.
   *
   * @param string $blockType
   *   The machine name of a custom block type.
   * @param string $fieldName
   *   The machine name of a field on the custom block.
   */
  public function updateTextFormats($blockType, $fieldName) {
    if (!$blockType || !$fieldName) {
      $this->messenger->addError('No block type or field name was specified. Block updates were not performed.');
      return;
    }
    // Used to set a message at the end.
    $updated = FALSE;

    // Get full field definition for specified field.
    $fieldDefinition = $this->entityFieldManager->getFieldDefinitions('block_content', $blockType)[$fieldName];

    // Get all text formats for field. We will choose 1st one as default below.
    $defaultTextFormat = $fieldDefinition->getSetting('allowed_formats');

    $blockIds = $this->getBlockIds($blockType);
    $numExistingBlocks = count($blockIds);
    foreach ($blockIds as $blockId) {
      /** @var Drupal\block_content\Entity\BlockContent $block */
      $block = $this->entityTypeManager->getStorage('block_content')->load($blockId);
      if ($block instanceof BlockContent) {
        // Update the text field to use the new text format.
        $block->set($fieldName, [
          'value' => $block->get($fieldName)->value,
          // Choose the 1st text format as the default can only be one of them.
          'format' => $defaultTextFormat[0],
        ]);
        $block->save();
        $updated = TRUE;
      }
    }
    if ($updated) {
      $this->messenger->addStatus(
        $this->t('Successfully updated the field "%field" to use the %format text format on %numBlocks blocks of type "%block".',
        [
          '%numBlocks' => $numExistingBlocks,
          '%block' => $blockType,
          '%field' => $fieldName,
          '%format' => $defaultTextFormat[0],
        ]
      ));
    }
    else {
      $this->messenger->addStatus(
        $this->t('Note: No blocks found to update.',
      ));
    }
  }

  /**
   * Returns an array of Block IDs of currently existing blocks of a block type.
   */
  protected function getBlockIds($blockType) {
    // Change existing content spotlight landscape blocks.
    $query = $this->entityTypeManager->getStorage('block_content')->getQuery()
      ->condition('type', $blockType)
      ->accessCheck(TRUE);
    $blockIds = $query->execute();

    return $blockIds;
  }

}
