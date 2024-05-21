<?php

namespace Drupal\ys_layouts\Service;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
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
