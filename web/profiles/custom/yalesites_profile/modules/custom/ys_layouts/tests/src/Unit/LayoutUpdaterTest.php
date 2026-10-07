<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Layout\LayoutInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\layout_builder\Field\LayoutSectionItemList;
use Drupal\layout_builder\Section;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\node\NodeTypeInterface;
use Drupal\ys_layouts\Service\LayoutUpdater;
use Psr\Log\LoggerInterface;

/**
 * Tests the LayoutUpdater service.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Service\LayoutUpdater
 *
 * @group yalesites
 * @group ys_layouts
 */
class LayoutUpdaterTest extends UnitTestCase {

  /**
   * The config factory mock.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * The database connection mock.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The entity field manager mock.
   *
   * @var \Drupal\Core\Entity\EntityFieldManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityFieldManager;

  /**
   * The logger mock.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The messenger mock.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $messenger;

  /**
   * The LayoutUpdater service under test.
   *
   * @var \Drupal\ys_layouts\Service\LayoutUpdater
   */
  protected $layoutUpdater;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->database = $this->createMock(Connection::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityFieldManager = $this->createMock(EntityFieldManager::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->messenger = $this->createMock(MessengerInterface::class);

    $this->layoutUpdater = new LayoutUpdater(
      $this->configFactory,
      $this->database,
      $this->entityTypeManager,
      $this->entityFieldManager,
      $this->logger,
      $this->messenger
    );
    $this->layoutUpdater->setStringTranslation($this->getStringTranslationStub());

    // Section::getLayoutSettings() builds the layout plugin; echo back the
    // settings the section was created with.
    $layoutManager = $this->createMock(LayoutPluginManagerInterface::class);
    $layoutManager->method('createInstance')->willReturnCallback(function ($id, array $settings = []) {
      $layout = $this->createMock(LayoutInterface::class);
      $layout->method('getConfiguration')->willReturn($settings);
      return $layout;
    });
    $container = new ContainerBuilder();
    $container->set('plugin.manager.core.layout', $layoutManager);
    \Drupal::setContainer($container);
  }

  /**
   * A bundle's node type entities pass through from the node_type storage.
   *
   * @covers ::getContentTypes
   */
  public function testGetContentTypesReturnsAllNodeTypes(): void {
    $pageType = $this->createMock(NodeTypeInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('loadMultiple')
      ->willReturn(['page' => $pageType]);
    $this->entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('node_type')
      ->willReturn($storage);

    $result = $this->layoutUpdater->getContentTypes();

    $this->assertSame(['page' => $pageType], $result);
  }

  /**
   * Locked sections are extracted with their layout ID and label.
   *
   * @covers ::getLockConfigs
   */
  public function testGetLockConfigsExtractsLocksPerSection(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('third_party_settings.layout_builder')
      ->willReturn([
        'sections' => [
          [
            'layout_id' => 'ys_layout_banner',
            'layout_settings' => ['label' => 'Banner'],
            'third_party_settings' => [
              'layout_builder_lock' => ['lock' => [5 => 5, 6 => 6]],
            ],
          ],
        ],
      ]);
    $this->configFactory->expects($this->once())
      ->method('get')
      ->with('core.entity_view_display.node.page.default')
      ->willReturn($config);

    $result = $this->layoutUpdater->getLockConfigs('page');

    $this->assertSame([
      ['layout_id' => 'ys_layout_banner', 'label' => 'Banner', 'lock' => [5 => 5, 6 => 6]],
    ], $result);
  }

  /**
   * A section without a lock setting contributes nothing to the result.
   *
   * @covers ::getLockConfigs
   */
  public function testGetLockConfigsIgnoresSectionsWithoutLocks(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn([
      'sections' => [
        ['layout_id' => 'ys_layout_banner'],
      ],
    ]);
    $this->configFactory->method('get')->willReturn($config);

    $result = $this->layoutUpdater->getLockConfigs('page');

    $this->assertSame([], $result);
  }

  /**
   * A display with no layout builder sections returns an empty array.
   *
   * @covers ::getLockConfigs
   */
  public function testGetLockConfigsReturnsEmptyWithNoSections(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);
    $this->configFactory->method('get')->willReturn($config);

    $result = $this->layoutUpdater->getLockConfigs('page');

    $this->assertSame([], $result);
  }

  /**
   * Node IDs come from an entity query filtered by bundle.
   *
   * @covers ::getAllNodeIds
   */
  public function testGetAllNodeIdsQueriesByBundle(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->expects($this->once())->method('condition')->with('type', 'event')->willReturnSelf();
    $query->expects($this->once())->method('execute')->willReturn([1, 2, 3]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $this->entityTypeManager->method('getStorage')->with('node')->willReturn($storage);

    $result = $this->layoutUpdater->getAllNodeIds('event');

    $this->assertSame([1, 2, 3], $result);
  }

  /**
   * A section matching a default lock has its third-party lock updated.
   *
   * @covers ::updateLocks
   */
  public function testUpdateLocksAppliesLockToMatchingSection(): void {
    $section = new Section('ys_layout_banner');

    $layout = $this->createMock(LayoutSectionItemList::class);
    $layout->method('isEmpty')->willReturn(FALSE);
    $layout->method('getSections')->willReturn([$section]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('get')->with('layout_builder__layout')->willReturn($layout);
    $node->expects($this->once())->method('save');

    $nodeStorage = $this->createMock(RevisionableStorageInterface::class);
    $nodeStorage->method('load')->with(1)->willReturn($node);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([1]);
    $nodeStorage->method('getQuery')->willReturn($query);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn([
      'sections' => [
        [
          'layout_id' => 'ys_layout_banner',
          'third_party_settings' => [
            'layout_builder_lock' => ['lock' => [5 => 5]],
          ],
        ],
      ],
    ]);
    $this->configFactory->method('get')->willReturn($config);
    $this->entityTypeManager->method('getStorage')->with('node')->willReturn($nodeStorage);

    $this->layoutUpdater->updateLocks('page');

    $this->assertSame([5 => 5], $section->getThirdPartySetting('layout_builder_lock', 'lock'));
  }

  /**
   * Two sections sharing a layout ID each receive their own default locks.
   *
   * Posts have a "Title and Metadata" and a "Content Section", both
   * layout_onecol. Matching on layout ID alone would let one overwrite the
   * other.
   *
   * @covers ::updateLocks
   * @covers ::getLockConfigs
   */
  public function testUpdateLocksMatchesSectionsSharingLayoutIdByLabel(): void {
    $title = new Section('layout_onecol', ['label' => 'Title and Metadata']);
    $content = new Section('layout_onecol', ['label' => 'Content Section']);
    $custom = new Section('layout_onecol', ['label' => 'Added by editor']);
    $twoCol = new Section('ys_layout_two_column_50_50', ['label' => 'Two']);
    $twoCol->setThirdPartySetting('layout_builder_lock', 'lock', [1 => 1]);

    $layout = $this->createMock(LayoutSectionItemList::class);
    $layout->method('isEmpty')->willReturn(FALSE);
    $layout->method('getSections')->willReturn([$title, $content, $custom, $twoCol]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('get')->with('layout_builder__layout')->willReturn($layout);
    $node->expects($this->once())->method('save');

    $nodeStorage = $this->createMock(RevisionableStorageInterface::class);
    $nodeStorage->method('load')->with(1)->willReturn($node);
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([1]);
    $nodeStorage->method('getQuery')->willReturn($query);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn([
      'sections' => [
        [
          'layout_id' => 'layout_onecol',
          'layout_settings' => ['label' => 'Title and Metadata'],
          'third_party_settings' => [
            'layout_builder_lock' => ['lock' => [1 => 1, 7 => 7, 8 => 8]],
          ],
        ],
        [
          'layout_id' => 'layout_onecol',
          'layout_settings' => ['label' => 'Content Section'],
          'third_party_settings' => [
            'layout_builder_lock' => ['lock' => [6 => 6]],
          ],
        ],
      ],
    ]);
    $this->configFactory->method('get')->willReturn($config);
    $this->entityTypeManager->method('getStorage')->with('node')->willReturn($nodeStorage);

    $this->layoutUpdater->updateLocks('post');

    $this->assertSame([1 => 1, 7 => 7, 8 => 8], $title->getThirdPartySetting('layout_builder_lock', 'lock'));
    $this->assertSame([6 => 6], $content->getThirdPartySetting('layout_builder_lock', 'lock'));
    // Ambiguous layout ID with no label match: untouched.
    $this->assertNull($custom->getThirdPartySetting('layout_builder_lock', 'lock'));
    // Layout ID absent from defaults: untouched.
    $this->assertSame([1 => 1], $twoCol->getThirdPartySetting('layout_builder_lock', 'lock'));
  }

  /**
   * Only default sections with a label match receive locks.
   *
   * Resources have three layout_onecol defaults but only the title is locked.
   * A layout ID match alone must not lock the other onecol sections.
   *
   * @covers ::updateLocks
   */
  public function testUpdateLocksRequiresLabelMatchWhenNodeSectionHasLabel(): void {
    $title = new Section('layout_onecol', ['label' => 'TItle and Metadata']);
    $content = new Section('layout_onecol', ['label' => 'Content Section']);
    $custom = new Section('layout_onecol', ['label' => 'Added by editor']);

    $nodeStorage = $this->mockNodeStorage([1], [
      [
        'layout_id' => 'layout_onecol',
        'layout_settings' => ['label' => 'TItle and Metadata'],
        'third_party_settings' => [
          'layout_builder_lock' => ['lock' => [1 => 1, 8 => 8]],
        ],
      ],
    ]);
    $nodeStorage->method('load')->with(1)->willReturn($this->mockNode([$title, $content, $custom], 10));

    $this->layoutUpdater->updateLocks('resource');

    $this->assertSame([1 => 1, 8 => 8], $title->getThirdPartySetting('layout_builder_lock', 'lock'));
    $this->assertNull($content->getThirdPartySetting('layout_builder_lock', 'lock'));
    $this->assertNull($custom->getThirdPartySetting('layout_builder_lock', 'lock'));
  }

  /**
   * An unlabelled section falls back to a sole default with its layout ID.
   *
   * @covers ::updateLocks
   */
  public function testUpdateLocksFallsBackToLayoutIdForUnlabelledSection(): void {
    $banner = new Section('ys_layout_banner');

    $nodeStorage = $this->mockNodeStorage([1], [
      [
        'layout_id' => 'ys_layout_banner',
        'layout_settings' => ['label' => 'Banner'],
        'third_party_settings' => [
          'layout_builder_lock' => ['lock' => [5 => 5]],
        ],
      ],
    ]);
    $nodeStorage->method('load')->with(1)->willReturn($this->mockNode([$banner], 10));

    $this->layoutUpdater->updateLocks('page');

    $this->assertSame([5 => 5], $banner->getThirdPartySetting('layout_builder_lock', 'lock'));
  }

  /**
   * A pending draft is updated in place alongside the default revision.
   *
   * Layout Builder edits the latest revision, so a draft left with the old
   * locks would still block editors and restore them when published.
   *
   * @covers ::updateLocks
   */
  public function testUpdateLocksUpdatesPendingLatestRevisionInPlace(): void {
    $defaultSection = new Section('ys_layout_banner');
    $draftSection = new Section('ys_layout_banner');
    $draftSection->setThirdPartySetting('layout_builder_lock', 'lock', [5 => 5, 6 => 6]);

    $default = $this->mockNode([$defaultSection], 10);
    $default->expects($this->never())->method('setNewRevision');
    $default->expects($this->once())->method('setSyncing')->with(TRUE);
    $draft = $this->mockNode([$draftSection], 12, TRUE);
    $draft->expects($this->once())->method('setNewRevision')->with(FALSE);
    $draft->expects($this->once())->method('setSyncing')->with(TRUE);

    $nodeStorage = $this->mockNodeStorage([1], [
      [
        'layout_id' => 'ys_layout_banner',
        'third_party_settings' => [
          'layout_builder_lock' => ['lock' => [5 => 5]],
        ],
      ],
    ]);
    $nodeStorage->method('load')->with(1)->willReturn($default);
    $nodeStorage->method('getLatestRevisionId')->with(1)->willReturn(12);
    $nodeStorage->method('loadRevision')->with(12)
      ->willReturn($draft);

    $this->layoutUpdater->updateLocks('page');

    $this->assertSame([5 => 5], $defaultSection->getThirdPartySetting('layout_builder_lock', 'lock'));
    $this->assertSame([5 => 5], $draftSection->getThirdPartySetting('layout_builder_lock', 'lock'));
  }

  /**
   * Builds a node storage mock serving the given node IDs and lock defaults.
   *
   * @param int[] $nids
   *   Node IDs the entity query returns.
   * @param array $defaultSections
   *   Layout builder sections of the bundle's default display.
   *
   * @return \Drupal\Core\Entity\RevisionableStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The node storage mock; callers stub load() and revision methods.
   */
  protected function mockNodeStorage(array $nids, array $defaultSections) {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn($nids);

    $nodeStorage = $this->createMock(RevisionableStorageInterface::class);
    $nodeStorage->method('getQuery')->willReturn($query);
    $this->entityTypeManager->method('getStorage')->with('node')->willReturn($nodeStorage);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(['sections' => $defaultSections]);
    $this->configFactory->method('get')->willReturn($config);

    return $nodeStorage;
  }

  /**
   * Builds a node revision mock with the given sections, expecting one save.
   *
   * @param \Drupal\layout_builder\Section[] $sections
   *   The node's layout sections.
   * @param int $revisionId
   *   The revision ID.
   * @param bool $mockSet
   *   Whether to mock __set() so property writes can be asserted.
   *
   * @return \Drupal\node\NodeInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The node mock.
   */
  protected function mockNode(array $sections, int $revisionId, bool $mockSet = FALSE) {
    $layout = $this->createMock(LayoutSectionItemList::class);
    $layout->method('isEmpty')->willReturn(FALSE);
    $layout->method('getSections')->willReturn($sections);

    $node = $mockSet
      ? $this->getMockBuilder(Node::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['get', 'getRevisionId', 'save', 'setNewRevision', 'setSyncing', '__set'])
        ->getMock()
      : $this->createMock(NodeInterface::class);
    $node->method('get')->with('layout_builder__layout')->willReturn($layout);
    $node->method('getRevisionId')->willReturn($revisionId);
    $node->expects($this->once())->method('save');
    return $node;
  }

  /**
   * A node with no layout builder sections is skipped without saving.
   *
   * @covers ::updateLocks
   */
  public function testUpdateLocksSkipsNodeWithEmptyLayout(): void {
    $layout = $this->createMock(LayoutSectionItemList::class);
    $layout->method('isEmpty')->willReturn(TRUE);

    $node = $this->createMock(NodeInterface::class);
    $node->method('get')->willReturn($layout);
    $node->expects($this->never())->method('save');

    $nodeStorage = $this->createMock(RevisionableStorageInterface::class);
    $nodeStorage->method('load')->willReturn($node);
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([1]);
    $nodeStorage->method('getQuery')->willReturn($query);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn([]);
    $this->configFactory->method('get')->willReturn($config);
    $this->entityTypeManager->method('getStorage')->willReturn($nodeStorage);

    $this->layoutUpdater->updateLocks('page');
  }

  /**
   * A missing node ID is skipped rather than raising an error.
   *
   * @covers ::updateLocks
   */
  public function testUpdateLocksSkipsMissingNode(): void {
    $nodeStorage = $this->createMock(RevisionableStorageInterface::class);
    $nodeStorage->method('load')->willReturn(NULL);
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([99]);
    $nodeStorage->method('getQuery')->willReturn($query);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn([]);
    $this->configFactory->method('get')->willReturn($config);
    $this->entityTypeManager->method('getStorage')->willReturn($nodeStorage);

    $this->layoutUpdater->updateLocks('page');
    // No exception means the missing node was skipped cleanly.
    $this->addToAssertionCount(1);
  }

  /**
   * A save failure is caught and logged rather than propagated.
   *
   * @covers ::updateLocks
   */
  public function testUpdateLocksLogsErrorOnSaveFailure(): void {
    $section = new Section('ys_layout_banner');

    $layout = $this->createMock(LayoutSectionItemList::class);
    $layout->method('isEmpty')->willReturn(FALSE);
    $layout->method('getSections')->willReturn([$section]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('get')->willReturn($layout);
    $node->method('save')->willThrowException(new EntityStorageException('DB down'));

    $nodeStorage = $this->createMock(RevisionableStorageInterface::class);
    $nodeStorage->method('load')->willReturn($node);
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([7]);
    $nodeStorage->method('getQuery')->willReturn($query);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn([
      'sections' => [
        [
          'layout_id' => 'ys_layout_banner',
          'third_party_settings' => [
            'layout_builder_lock' => ['lock' => [5 => 5]],
          ],
        ],
      ],
    ]);
    $this->configFactory->method('get')->willReturn($config);
    $this->entityTypeManager->method('getStorage')->willReturn($nodeStorage);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        'Error updating locks for node with ID @nid: @message',
        ['@nid' => 7, '@message' => 'DB down']
      );

    $this->layoutUpdater->updateLocks('page');
  }

  /**
   * Updating all locks delegates to updateLocks() once per content type.
   *
   * @covers ::updateAllLocks
   */
  public function testUpdateAllLocksCallsUpdateLocksForEveryBundle(): void {
    $pageType = $this->createMock(NodeTypeInterface::class);
    $pageType->method('id')->willReturn('page');
    $eventType = $this->createMock(NodeTypeInterface::class);
    $eventType->method('id')->willReturn('event');

    $updater = $this->getMockBuilder(LayoutUpdater::class)
      ->setConstructorArgs([
        $this->configFactory,
        $this->database,
        $this->entityTypeManager,
        $this->entityFieldManager,
        $this->logger,
        $this->messenger,
      ])
      ->onlyMethods(['getContentTypes', 'updateLocks'])
      ->getMock();
    $updater->method('getContentTypes')->willReturn([$pageType, $eventType]);

    $called = [];
    $updater->method('updateLocks')->willReturnCallback(function ($bundle) use (&$called) {
      $called[] = $bundle;
    });

    $updater->updateAllLocks();

    $this->assertSame(['page', 'event'], $called);
  }

  /**
   * A missing key_value_expire table means nothing is stored to check.
   *
   * @covers ::getTempStoreNids
   */
  public function testGetTempStoreNidsReturnsNullWhenTableMissing(): void {
    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->with('key_value_expire')->willReturn(FALSE);
    $this->database->method('schema')->willReturn($schema);

    $result = $this->layoutUpdater->getTempStoreNids();

    $this->assertNull($result);
  }

  /**
   * With no temp store table, node loading falls back to loading all nodes.
   *
   * This documents the current pass-through behavior of loadMultiple(NULL),
   * which is Drupal's convention for "load every entity of this type".
   *
   * @covers ::getTempStoreNodes
   */
  public function testGetTempStoreNodesLoadsAllNodesWhenTableMissing(): void {
    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->willReturn(FALSE);
    $this->database->method('schema')->willReturn($schema);

    $node = $this->createMock(NodeInterface::class);
    $nodeStorage = $this->createMock(RevisionableStorageInterface::class);
    $nodeStorage->expects($this->once())
      ->method('loadMultiple')
      ->with(NULL)
      ->willReturn(['3' => $node]);
    $this->entityTypeManager->method('getStorage')->with('node')->willReturn($nodeStorage);

    $result = $this->layoutUpdater->getTempStoreNodes();

    $this->assertSame(['3' => $node], $result);
  }

  /**
   * Custom block types are returned as a select-friendly option list.
   *
   * @covers ::getBlockTypes
   */
  public function testGetBlockTypesReturnsOptionListWithPlaceholder(): void {
    $blockType = $this->createMock(BlockContentType::class);
    $blockType->method('id')->willReturn('callout');
    $blockType->method('label')->willReturn('Callout');

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn(['callout' => $blockType]);
    $this->entityTypeManager->method('getStorage')->with('block_content_type')->willReturn($storage);

    $result = $this->layoutUpdater->getBlockTypes();

    $this->assertSame(['' => 'Select', 'callout' => 'Callout'], $result);
  }

  /**
   * Only user-created text-based fields are offered for updating.
   *
   * @covers ::getTextBlockFields
   */
  public function testGetTextBlockFieldsFiltersToUserTextFields(): void {
    $textField = $this->createMock(FieldDefinitionInterface::class);
    $textField->method('getType')->willReturn('text_long');
    $textField->method('getLabel')->willReturn('Body');

    $numberField = $this->createMock(FieldDefinitionInterface::class);
    $numberField->method('getType')->willReturn('integer');

    $this->entityFieldManager->method('getFieldDefinitions')
      ->with('block_content', 'callout')
      ->willReturn([
        'field_body' => $textField,
        'field_count' => $numberField,
        'uuid' => $textField,
      ]);

    $result = $this->layoutUpdater->getTextBlockFields('callout');

    $this->assertSame(['field_body' => 'Body'], $result);
  }

  /**
   * Missing block type or field name arguments produce a user-facing error.
   *
   * @covers ::updateTextFormats
   */
  public function testUpdateTextFormatsWithMissingArgumentsAddsError(): void {
    $this->messenger->expects($this->once())
      ->method('addError')
      ->with('No block type or field name was specified. Block updates were not performed.');
    $this->entityFieldManager->expects($this->never())->method('getFieldDefinitions');

    $this->layoutUpdater->updateTextFormats('', '');
  }

  /**
   * Existing blocks of the given type have their text field format updated.
   *
   * @covers ::updateTextFormats
   */
  public function testUpdateTextFormatsUpdatesMatchingBlocks(): void {
    $fieldDefinition = $this->createMock(FieldDefinitionInterface::class);
    $fieldDefinition->method('getSetting')->with('allowed_formats')->willReturn(['basic_html']);
    $this->entityFieldManager->method('getFieldDefinitions')
      ->with('block_content', 'callout')
      ->willReturn(['field_body' => $fieldDefinition]);

    $fieldItemList = $this->createMock(FieldItemListInterface::class);
    $fieldItemList->method('__get')->with('value')->willReturn('Old value');

    $block = $this->getMockBuilder(BlockContent::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['get', 'set', 'save'])
      ->getMock();
    $block->method('get')->with('field_body')->willReturn($fieldItemList);
    $block->expects($this->once())
      ->method('set')
      ->with('field_body', ['value' => 'Old value', 'format' => 'basic_html']);
    $block->expects($this->once())->method('save');

    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('execute')->willReturn([42]);

    $blockStorage = $this->createMock(EntityStorageInterface::class);
    $blockStorage->method('getQuery')->willReturn($query);
    $blockStorage->method('load')->with(42)->willReturn($block);
    $this->entityTypeManager->method('getStorage')->with('block_content')->willReturn($blockStorage);

    $this->messenger->expects($this->once())->method('addStatus');

    $this->layoutUpdater->updateTextFormats('callout', 'field_body');
  }

  /**
   * No matching blocks results in a "nothing to update" status message.
   *
   * @covers ::updateTextFormats
   */
  public function testUpdateTextFormatsWithNoBlocksAddsNoteStatus(): void {
    $fieldDefinition = $this->createMock(FieldDefinitionInterface::class);
    $fieldDefinition->method('getSetting')->willReturn(['basic_html']);
    $this->entityFieldManager->method('getFieldDefinitions')->willReturn(['field_body' => $fieldDefinition]);

    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('execute')->willReturn([]);

    $blockStorage = $this->createMock(EntityStorageInterface::class);
    $blockStorage->method('getQuery')->willReturn($query);
    $this->entityTypeManager->method('getStorage')->willReturn($blockStorage);

    $this->messenger->expects($this->once())
      ->method('addStatus')
      ->with('Note: No blocks found to update.');

    $this->layoutUpdater->updateTextFormats('callout', 'field_body');
  }

}
