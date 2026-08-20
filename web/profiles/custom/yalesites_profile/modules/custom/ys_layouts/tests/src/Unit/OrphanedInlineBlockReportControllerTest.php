<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\block_content\BlockContentInterface;
use Drupal\ys_layouts\Controller\OrphanedInlineBlockReportController;
use Drupal\ys_layouts\Service\OrphanedInlineBlockCleanerInterface;

/**
 * Tests the orphaned inline block report screen.
 *
 * The screen is the platform admin's route onto a sweep that previously only
 * existed as a drush command. Its job is to show what would be deleted BEFORE
 * offering to delete it, so these tests pin that ordering: the delete action is
 * absent when there is nothing to delete, and the revision-only blocks the
 * cleaner refuses to collect are reported separately rather than folded into
 * the deletable list.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Controller\OrphanedInlineBlockReportController
 *
 * @group yalesites
 * @group ys_layouts
 */
class OrphanedInlineBlockReportControllerTest extends UnitTestCase {

  /**
   * The orphaned inline block cleaner mock.
   *
   * @var \Drupal\ys_layouts\Service\OrphanedInlineBlockCleanerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $cleaner;

  /**
   * The block content storage mock.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $blockStorage;

  /**
   * The controller under test.
   *
   * @var \Drupal\ys_layouts\Controller\OrphanedInlineBlockReportController
   */
  protected $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->cleaner = $this->createMock(OrphanedInlineBlockCleanerInterface::class);
    $this->blockStorage = $this->createMock(EntityStorageInterface::class);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnMap([
      ['block_content', $this->blockStorage],
    ]);

    $this->controller = new OrphanedInlineBlockReportController($this->cleaner, $entity_type_manager);
    $this->controller->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Builds a block content mock with the given bundle and label.
   *
   * @param string $bundle
   *   The block type.
   * @param string $label
   *   The block label.
   *
   * @return \Drupal\block_content\BlockContentInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked block.
   */
  protected function block(string $bundle, string $label) {
    $block = $this->createMock(BlockContentInterface::class);
    $block->method('bundle')->willReturn($bundle);
    $block->method('label')->willReturn($label);
    return $block;
  }

  /**
   * Each orphan is listed with its ID, block type and label.
   *
   * @covers ::build
   */
  public function testBuildListsEachOrphanWithTypeAndLabel(): void {
    $this->cleaner->method('analyze')->willReturn([
      'orphans' => [7, 9],
      'revision_only' => [],
    ]);
    $this->blockStorage->method('loadMultiple')->with([7, 9])->willReturn([
      7 => $this->block('text', 'Leftover text'),
      9 => $this->block('accordion', 'Leftover accordion'),
    ]);

    $build = $this->controller->build();

    $this->assertSame([
      [7, 'text', 'Leftover text'],
      [9, 'accordion', 'Leftover accordion'],
    ], $build['orphans']['#rows']);
  }

  /**
   * A reported orphan whose block row is already gone is still listed.
   *
   * Every ID the sweep reports had a block_content row when it queried, so this
   * is the narrow case where something deleted the block in between. The row is
   * kept regardless: dropping it would hide an ID from the count the operator
   * is about to act on.
   *
   * @covers ::build
   */
  public function testBuildListsOrphansWhoseBlockEntityIsMissing(): void {
    $this->cleaner->method('analyze')->willReturn([
      'orphans' => [7, 404],
      'revision_only' => [],
    ]);
    $this->blockStorage->method('loadMultiple')->willReturn([
      7 => $this->block('text', 'Leftover text'),
    ]);

    $build = $this->controller->build();

    $rows = array_map(
      fn(array $row) => array_map('strval', $row),
      $build['orphans']['#rows']
    );
    $this->assertSame([
      ['7', 'text', 'Leftover text'],
      ['404', 'missing', 'n/a'],
    ], $rows);
  }

  /**
   * The delete action counts the orphans it is about to remove.
   *
   * @covers ::build
   */
  public function testBuildDeleteActionReportsTheOrphanCount(): void {
    $this->cleaner->method('analyze')->willReturn([
      'orphans' => [7, 9, 11],
      'revision_only' => [],
    ]);
    $this->blockStorage->method('loadMultiple')->willReturn([]);

    $build = $this->controller->build();

    $this->assertSame(
      'Delete 3 orphaned inline blocks',
      (string) $build['actions']['delete']['#title']
    );
  }

  /**
   * Revision-only blocks alone produce a table but no delete action.
   *
   * The state most easily got wrong: there is something to show, but nothing
   * this screen is allowed to delete, so the destructive affordance must be
   * absent even though the page is not empty.
   *
   * @covers ::build
   */
  public function testBuildOffersNoDeleteActionForRevisionOnlyBlocks(): void {
    $this->cleaner->method('analyze')->willReturn([
      'orphans' => [],
      'revision_only' => [11],
    ]);
    $this->blockStorage->method('loadMultiple')->willReturn([
      11 => $this->block('text', 'Held by an old revision'),
    ]);

    $build = $this->controller->build();

    $this->assertArrayNotHasKey('actions', $build);
    $this->assertArrayHasKey('revision_only', $build);
    $this->assertSame([[11, 'text', 'Held by an old revision']], $build['revision_only']['#rows']);
  }

  /**
   * Each table's explanation renders above its own rows.
   *
   * Ordering is behaviour here, not cosmetics: built in the wrong order, the
   * delete button lands directly beneath the revision-only table - the one
   * table it must never act on.
   *
   * @covers ::build
   */
  public function testBuildOrdersDeleteActionAboveTheRevisionOnlyTable(): void {
    $this->cleaner->method('analyze')->willReturn([
      'orphans' => [7],
      'revision_only' => [11],
    ]);
    $this->blockStorage->method('loadMultiple')->willReturn([
      7 => $this->block('text', 'Leftover text'),
      11 => $this->block('text', 'Held by an old revision'),
    ]);

    $build = $this->controller->build();

    $order = array_values(array_filter(
      array_keys($build),
      fn($key) => !str_starts_with($key, '#')
    ));
    $this->assertSame([
      'description',
      'orphans',
      'actions',
      'revision_only_description',
      'revision_only',
    ], $order);
  }

  /**
   * The delete action is offered only when there is something to delete.
   *
   * @covers ::build
   */
  public function testBuildOffersNoDeleteActionWithoutOrphans(): void {
    $this->cleaner->method('analyze')->willReturn([
      'orphans' => [],
      'revision_only' => [],
    ]);
    $this->blockStorage->method('loadMultiple')->willReturn([]);

    $build = $this->controller->build();

    $this->assertArrayNotHasKey('actions', $build);
    $this->assertArrayHasKey('#empty', $build['orphans']);
  }

  /**
   * With orphans present, the delete action links to the confirm step.
   *
   * Deletion is never a one-click action from the report: the link goes to a
   * separate confirm form, which is what keeps the drush command's
   * report-by-default posture intact in the UI.
   *
   * @covers ::build
   */
  public function testBuildLinksDeleteActionToConfirmForm(): void {
    $this->cleaner->method('analyze')->willReturn([
      'orphans' => [7],
      'revision_only' => [],
    ]);
    $this->blockStorage->method('loadMultiple')->willReturn([
      7 => $this->block('text', 'Leftover text'),
    ]);

    $build = $this->controller->build();

    $this->assertSame(
      'ys_layouts.orphaned_blocks_delete',
      $build['actions']['delete']['#url']->getRouteName()
    );
  }

  /**
   * Revision-only blocks are reported apart from the deletable orphans.
   *
   * The cleaner never deletes these, because a node rollback would make them
   * live again. Listing them in the same table would misrepresent what the
   * delete action is about to do.
   *
   * @covers ::build
   */
  public function testBuildSeparatesRevisionOnlyBlocksFromOrphans(): void {
    $this->cleaner->method('analyze')->willReturn([
      'orphans' => [7],
      'revision_only' => [11],
    ]);
    $this->blockStorage->method('loadMultiple')->willReturnMap([
      [[7], [7 => $this->block('text', 'Leftover text')]],
      [[11], [11 => $this->block('text', 'Held by an old revision')]],
    ]);

    $build = $this->controller->build();

    $this->assertSame([[7, 'text', 'Leftover text']], $build['orphans']['#rows']);
    $this->assertSame([[11, 'text', 'Held by an old revision']], $build['revision_only']['#rows']);
  }

  /**
   * The revision-only table is omitted when there are none.
   *
   * @covers ::build
   */
  public function testBuildOmitsRevisionOnlyTableWhenEmpty(): void {
    $this->cleaner->method('analyze')->willReturn([
      'orphans' => [],
      'revision_only' => [],
    ]);
    $this->blockStorage->method('loadMultiple')->willReturn([]);

    $build = $this->controller->build();

    $this->assertArrayNotHasKey('revision_only', $build);
  }

  /**
   * The report is never cached.
   *
   * It is a live view of the database that an editor's next layout save can
   * invalidate, and it is the basis for a destructive action, so serving a
   * cached copy would show an operator counts that are no longer true.
   *
   * @covers ::build
   */
  public function testBuildIsNotCached(): void {
    $this->cleaner->method('analyze')->willReturn([
      'orphans' => [],
      'revision_only' => [],
    ]);
    $this->blockStorage->method('loadMultiple')->willReturn([]);

    $build = $this->controller->build();

    $this->assertSame(0, $build['#cache']['max-age']);
  }

}
