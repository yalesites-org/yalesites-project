<?php

namespace Drupal\ys_layouts\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\block_content\Entity\BlockContent;
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
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManager $entity_field_manager,
    LoggerInterface $logger,
    MessengerInterface $messenger,
  ) {
    $this->configFactory = $config_factory;
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->logger = $logger;
    $this->messenger = $messenger;
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
   * Get the third party lock settings for each section of a content type. The
   * returned array will take the form:
   * @code
   * 'ys_layout_banner' => [
   *   5 => 5,
   *   6 => 6,
   *   8 => 8,
   * ],
   * 'ys_layout_page_meta' => [
   *   2 => 2,
   *   3 => 3,
   *   4 => 4,
   * ],
   * @endcode
   *
   * @param string $nodeBundleId
   *   The machine name of the content type (node bundle).
   *
   * @return array
   *   All third party lock values organized by section ID.
   */
  public function getLockConfigs($nodeBundleId) {
    $name = "core.entity_view_display.node.{$nodeBundleId}.default";
    $config = $this->configFactory->get($name);
    $lb = $config->get('third_party_settings.layout_builder');
    $locks = [];

    // Iterate over each layout builder section to get the locks for each one.
    if (!empty($lb['sections']) && is_array($lb['sections'])) {
      foreach ($lb['sections'] as $section) {
        $layout_id = $section['layout_id'];
        if (!empty($section['third_party_settings']['layout_builder_lock']['lock']) && is_array($section['third_party_settings']['layout_builder_lock']['lock'])) {
          $locks[$layout_id] = $section['third_party_settings']['layout_builder_lock']['lock'];
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
    foreach ($this->getAllNodeIds($nodeBundleId) as $nid) {

      // Load the node or exit early if the node does not exist.
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      if (!$node instanceof NodeInterface) {
        continue;
      }

      // Load the layout builder sections or exit early if none are set.
      /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
      $layout = $node->get('layout_builder__layout');
      if ($layout->isEmpty()) {
        continue;
      }

      foreach ($layout->getSections() as $section) {
        // Authors can create their own sections. Check if this section is one
        // of the default sections defined for this content type.
        if (!array_key_exists($section->getLayoutId(), $defaultLocks)) {
          continue;
        }
        // Set third-party settings for layout_builder_lock to match the config.
        $locks = $defaultLocks[$section->getLayoutId()];
        $section->setThirdPartySetting('layout_builder_lock', 'lock', $locks);
      }
      try {
        $node->save();
      }
      catch (EntityStorageException $e) {
        $this->logger->error(
          'Error updating locks for node with ID @nid: @message',
          ['@nid' => $nid, '@message' => $e->getMessage()]
        );
      }
    }
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
  public function enableSeventyThirtyDividers(): int {
    $updated = 0;
    /** @var \Drupal\node\NodeStorageInterface $nodeStorage */
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    foreach ($this->getSeventyThirtyNodeIds() as $nid) {
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

      // This runs over every candidate node in one deploy request, so let the
      // entity static cache go rather than accumulating loaded nodes -- the
      // scale risk the class docblock's Batch API @todo is about.
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
