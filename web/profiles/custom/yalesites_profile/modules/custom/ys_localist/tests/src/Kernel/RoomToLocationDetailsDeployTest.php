<?php

namespace Drupal\Tests\ys_localist\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Tests the event location changes from YaleSites-Internal#750.
 *
 * Covers the deploy hook that moves Room into Location details on events
 * Localist does not manage.
 *
 * @group ys_localist
 * @group yalesites
 */
class RoomToLocationDetailsDeployTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'filter',
    'text',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);

    NodeType::create(['type' => 'event', 'name' => 'Event'])->save();
    $fields = [
      'field_localist_id' => 'string',
      'field_event_room' => 'string',
      'field_event_location_details' => 'text_long',
    ];
    foreach ($fields as $name => $type) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'event',
        'label' => $name,
      ])->save();
    }

    // Procedural code only; enabling ys_localist would pull in the whole
    // migrate stack for nothing.
    require_once __DIR__ . '/../../../ys_localist.deploy.php';
  }

  /**
   * Creates and saves an event.
   */
  protected function createEvent(array $values): NodeInterface {
    $node = Node::create($values + ['type' => 'event', 'title' => 'Event']);
    $node->save();
    return $node;
  }

  /**
   * Runs the deploy hook to completion the way drush deploy:hook does.
   */
  protected function runDeployHook(array $sandbox = ['#finished' => 0]): void {
    $passes = 0;
    while ($sandbox['#finished'] < 1) {
      ys_localist_deploy_10001($sandbox);
      $this->assertLessThan(100, ++$passes, 'Deploy hook did not finish.');
    }
  }

  /**
   * Room moves into Location details on non-Localist events only.
   */
  public function testDeployHookMovesRoomOnNonLocalistEvents(): void {
    $manual = $this->createEvent(['field_event_room' => 'Room 101 <b>&</b> "Annex"']);
    $localist = $this->createEvent([
      'field_event_room' => 'Room 202',
      'field_localist_id' => '12345',
    ]);
    $empty = $this->createEvent([]);
    $revisions = [];
    foreach ([$manual, $localist, $empty] as $node) {
      $revisions[$node->id()] = $node->getRevisionId();
    }

    $this->runDeployHook();

    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache();

    $manual = $storage->load($manual->id());
    $this->assertSame(
      '<p>Room 101 &lt;b&gt;&amp;&lt;/b&gt; &quot;Annex&quot;</p>',
      $manual->get('field_event_location_details')->value,
    );
    $this->assertSame('restricted_html', $manual->get('field_event_location_details')->format);
    $this->assertTrue($manual->get('field_event_room')->isEmpty());

    $localist = $storage->load($localist->id());
    $this->assertSame('Room 202', $localist->get('field_event_room')->value);
    $this->assertTrue($localist->get('field_event_location_details')->isEmpty());

    $empty = $storage->load($empty->id());
    $this->assertTrue($empty->get('field_event_location_details')->isEmpty());

    foreach ([$manual, $localist, $empty] as $node) {
      $this->assertSame($revisions[$node->id()], $node->getRevisionId(), 'No new revision created.');
    }
  }

  /**
   * A pending draft with Room is moved too, so publishing it loses nothing.
   */
  public function testDeployHookMovesRoomOnPendingDraft(): void {
    $node = $this->createEvent(['field_event_room' => 'Published room']);
    $defaultId = $node->getRevisionId();
    $node->setNewRevision(TRUE);
    $node->isDefaultRevision(FALSE);
    $node->set('field_event_room', 'Draft room');
    $node->save();
    $draftId = $node->getRevisionId();
    $this->assertNotEquals($defaultId, $draftId);

    $this->runDeployHook();

    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache();
    foreach ([$defaultId => 'Published room', $draftId => 'Draft room'] as $revisionId => $room) {
      $revision = $storage->loadRevision($revisionId);
      $this->assertTrue($revision->get('field_event_room')->isEmpty());
      $this->assertSame("<p>$room</p>", $revision->get('field_event_location_details')->value);
    }
    $this->assertEquals($draftId, $storage->getLatestRevisionId($node->id()), 'No new revision created.');
  }

  /**
   * The hook batches, and a second run is a no-op.
   */
  public function testDeployHookBatchesAndIsRerunnable(): void {
    $ids = [];
    for ($i = 0; $i < 55; $i++) {
      $ids[] = $this->createEvent(['field_event_room' => "Room $i"])->id();
    }

    $sandbox = [];
    ys_localist_deploy_10001($sandbox);
    $this->assertLessThan(1, $sandbox['#finished'], 'First pass did not batch.');
    $this->runDeployHook($sandbox);

    $nodes = Node::loadMultiple($ids);
    foreach ($nodes as $node) {
      $this->assertTrue($node->get('field_event_room')->isEmpty());
    }
    $this->assertSame('<p>Room 54</p>', end($nodes)->get('field_event_location_details')->value);

    // Nothing left to move on a second deploy.
    $this->runDeployHook();
    $this->assertSame('<p>Room 54</p>', Node::load(end($ids))->get('field_event_location_details')->value);
  }

}
