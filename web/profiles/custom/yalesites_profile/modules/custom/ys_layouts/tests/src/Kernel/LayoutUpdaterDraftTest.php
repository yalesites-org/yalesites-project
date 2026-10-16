<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\Core\Entity\Plugin\Validation\Constraint\EntityChangedConstraint;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use Drupal\ys_layouts\Service\LayoutUpdater;

/**
 * Tests LayoutUpdater::updateLocks() on real nodes, drafts and cached layouts.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Service\LayoutUpdater
 *
 * @group yalesites
 * @group ys_layouts
 */
class LayoutUpdaterDraftTest extends YsKernelTestBase {

  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'block',
    'contextual',
    'layout_discovery',
    'layout_builder',
    'layout_builder_lock',
    'workflows',
    'content_moderation',
  ];

  /**
   * The locks configured on the bundle's default display.
   */
  const LOCKS = [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 7, 8 => 8];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'filter', 'node', 'content_moderation']);

    NodeType::create(['type' => 'post', 'name' => 'Post'])->save();
    $display = LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'post',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->enableLayoutBuilder()->setOverridable();
    $display->appendSection(new Section('layout_onecol', ['label' => 'Title'], [], [
      'layout_builder_lock' => ['lock' => self::LOCKS],
    ]));
    $display->save();

    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'post');
  }

  /**
   * A pending draft stays savable after the default revision gets new locks.
   *
   * If the default revision ended up newer than the draft, the entity-changed
   * check would reject every later save of the draft from Layout Builder or the
   * edit form.
   *
   * @covers ::updateLocks
   */
  public function testUpdateLocksKeepsPendingDraftSavable(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    [$node, $defaultId, $draftId] = $this->createNodeWithDraft();
    $revisionCount = count($storage->revisionIds($node));

    $this->createUpdater()->updateLocks('post');

    $storage->resetCache();
    $default = $storage->load($node->id());
    $this->assertEquals($defaultId, $default->getRevisionId());
    $this->assertEquals($draftId, $storage->getLatestRevisionId($node->id()));
    $this->assertCount($revisionCount, $storage->revisionIds($default));

    $draft = $storage->loadRevision($draftId);
    $this->assertFalse($draft->isDefaultRevision());
    foreach ([$default, $draft] as $revision) {
      $section = $revision->get('layout_builder__layout')->getSection(0);
      $this->assertEquals(self::LOCKS, $section->getThirdPartySetting('layout_builder_lock', 'lock'));
    }

    $changedViolations = array_filter(
      iterator_to_array($draft->validate()),
      fn($violation) => $violation->getConstraint() instanceof EntityChangedConstraint,
    );
    $this->assertEmpty($changedViolations, 'The draft must pass the entity-changed check.');
  }

  /**
   * The repair keeps every revision's changed time.
   *
   * Otherwise every repaired node jumps to the top of Content sorted by
   * Updated, dated to the deploy.
   *
   * @covers ::updateLocks
   */
  public function testUpdateLocksKeepsChangedTimes(): void {
    $now = $this->container->get('datetime.time')->getRequestTime();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    [$node, $defaultId, $draftId] = $this->createNodeWithDraft();

    $this->createUpdater()->updateLocks('post');

    $storage->resetCache();
    $this->assertEquals($now - 2000, $storage->load($node->id())->getChangedTime());
    $this->assertEquals($now - 2000, $storage->loadRevision($defaultId)->getChangedTime());
    $this->assertEquals($now - 1000, $storage->loadRevision($draftId)->getChangedTime());
  }

  /**
   * A layout cached in the tempstore gets the locks and keeps its owner.
   *
   * Opening the Layout tab caches the layout for days. Saving that copy later
   * would otherwise restore the old locks.
   *
   * @covers ::updateLocks
   */
  public function testUpdateLocksUpdatesCachedLayouts(): void {
    $node = Node::create([
      'type' => 'post',
      'title' => 'Post',
      'moderation_state' => 'published',
      'layout_builder__layout' => [
        ['section' => new Section('layout_onecol', ['label' => 'Title'])],
      ],
    ]);
    $node->save();

    $editor = User::create(['name' => 'editor']);
    $editor->save();
    $this->container->get('current_user')->setAccount($editor);

    // The editor opens the Layout tab and adds a section without saving.
    $sectionStorage = $this->container->get('plugin.manager.layout_builder.section_storage')->load('overrides', [
      'entity' => EntityContext::fromEntity($node),
      'view_mode' => new Context(new ContextDefinition('string'), 'default'),
    ]);
    $sectionStorage->appendSection(new Section('layout_twocol_section'));
    $this->container->get('layout_builder.tempstore_repository')->set($sectionStorage);

    $this->container->get('current_user')->setAccount(User::getAnonymousUser());
    $this->createUpdater()->updateLocks('post');

    // Read the store directly: the repository keeps a static copy.
    $tempstore = $this->container->get('tempstore.shared')->get('layout_builder.section_storage.overrides');
    $key = $sectionStorage->getTempstoreKey();
    $cached = $tempstore->get($key)['section_storage'];
    $this->assertCount(2, $cached->getSections());
    $this->assertEquals(self::LOCKS, $cached->getSection(0)->getThirdPartySetting('layout_builder_lock', 'lock'));
    $this->assertNull($cached->getSection(1)->getThirdPartySetting('layout_builder_lock', 'lock'));

    $lock = $tempstore->getMetadata($key);
    $this->assertEquals($editor->id(), $lock->getOwnerId());
  }

  /**
   * Creates a published post with a newer pending draft.
   *
   * @return array
   *   The node, the default revision ID, and the draft revision ID.
   */
  protected function createNodeWithDraft(): array {
    $now = $this->container->get('datetime.time')->getRequestTime();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');

    $node = Node::create([
      'type' => 'post',
      'title' => 'Post',
      'moderation_state' => 'published',
      'changed' => $now - 2000,
      'layout_builder__layout' => [
        ['section' => new Section('layout_onecol', ['label' => 'Title'])],
      ],
    ]);
    $node->save();
    $defaultId = $node->getRevisionId();

    $node->set('moderation_state', 'draft');
    $node->setChangedTime($now - 1000);
    $node->save();
    $draftId = $storage->getLatestRevisionId($node->id());
    $this->assertNotEquals($defaultId, $draftId);

    return [$node, $defaultId, $draftId];
  }

  /**
   * Builds the updater from the kernel's services.
   */
  protected function createUpdater(): LayoutUpdater {
    return new LayoutUpdater(
      $this->container->get('config.factory'),
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('entity_field.manager'),
      $this->container->get('logger.factory')->get('ys_layouts'),
      $this->container->get('messenger'),
      $this->container->get('keyvalue.expirable'),
      $this->container->get('tempstore.shared'),
    );
  }

}
