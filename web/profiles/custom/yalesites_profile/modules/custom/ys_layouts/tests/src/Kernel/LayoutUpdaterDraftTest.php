<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\Core\Entity\Plugin\Validation\Constraint\EntityChangedConstraint;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\ys_layouts\Service\LayoutUpdater;

/**
 * Tests LayoutUpdater::updateLocks() on a node with a pending draft.
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
   * Saving the default revision stamps its changed time with the request time.
   * If the draft keeps its older changed time, the entity-changed check
   * rejects every later save of the draft from Layout Builder or the edit form.
   *
   * @covers ::updateLocks
   */
  public function testUpdateLocksKeepsPendingDraftSavable(): void {
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
    $revisionCount = count($storage->revisionIds($node));

    $updater = new LayoutUpdater(
      $this->container->get('config.factory'),
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('entity_field.manager'),
      $this->container->get('logger.factory')->get('ys_layouts'),
      $this->container->get('messenger'),
    );
    $updater->updateLocks('post');

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

}
