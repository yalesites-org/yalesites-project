<?php

namespace Drupal\Tests\ys_feeds_demo\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_feeds_demo\Plugin\Action\ArchiveModeratedNode;

/**
 * Unit tests for ArchiveModeratedNode.
 *
 * This action exists because core's unpublish action does not work on
 * moderated content: it sets the status field, which content moderation then
 * recomputes from the unchanged moderation state, quietly republishing the
 * node while Feeds records success. These tests pin the two branches that
 * behaviour depends on.
 *
 * @coversDefaultClass \Drupal\ys_feeds_demo\Plugin\Action\ArchiveModeratedNode
 * @group ys_feeds_demo
 * @group yalesites
 */
class ArchiveModeratedNodeTest extends UnitTestCase {

  /**
   * The action under test.
   *
   * @var \Drupal\ys_feeds_demo\Plugin\Action\ArchiveModeratedNode
   */
  protected $action;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->action = new ArchiveModeratedNode([], 'ys_feeds_demo_archive_moderated_node', []);
  }

  /**
   * A moderated node moves to the archived state rather than being unpublished.
   *
   * @covers ::execute
   */
  public function testModeratedNodeIsArchived(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('moderation_state')->willReturn(TRUE);
    $node->expects($this->once())
      ->method('set')
      ->with('moderation_state', 'archived');
    $node->expects($this->never())->method('setUnpublished');
    $node->expects($this->once())->method('save');

    $this->action->execute($node);
  }

  /**
   * A node outside any workflow falls back to the status field.
   *
   * @covers ::execute
   */
  public function testUnmoderatedNodeIsUnpublished(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('moderation_state')->willReturn(FALSE);
    $node->expects($this->never())->method('set');
    $node->expects($this->once())->method('setUnpublished');
    $node->expects($this->once())->method('save');

    $this->action->execute($node);
  }

  /**
   * Nothing is saved when there is no entity to act on.
   *
   * @covers ::execute
   */
  public function testNullEntityIsIgnored(): void {
    $this->expectNotToPerformAssertions();
    $this->action->execute(NULL);
  }

}
