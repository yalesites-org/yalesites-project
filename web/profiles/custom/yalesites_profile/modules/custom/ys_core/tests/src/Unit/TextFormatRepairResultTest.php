<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\TextFormatRepairResult;

/**
 * Tests the summarising of a text format repair outcome.
 *
 * The deferred list is per row, so one node contributes a row per revision
 * carrying the bad format. What a human chasing these down needs is the node
 * list and the tag list, deduplicated — that is what these cover.
 *
 * @coversDefaultClass \Drupal\ys_core\TextFormatRepairResult
 *
 * @group ys_core
 * @group yalesites
 *
 * @see yalesites-org/YaleSites-Internal#1646
 */
class TextFormatRepairResultTest extends UnitTestCase {

  /**
   * Builds a deferred row.
   */
  protected function deferral(int $entity_id, int $revision_id, array $dropped): array {
    return [
      'entity_id' => $entity_id,
      'revision_id' => $revision_id,
      'from' => 'restricted_html',
      'to' => 'heading_html',
      'dropped' => $dropped,
    ];
  }

  /**
   * An empty result reports nothing rather than erroring.
   *
   * @covers ::deferredEntityCount
   * @covers ::deferredEntityIds
   * @covers ::droppedTags
   */
  public function testEmptyResultSummarisesToNothing(): void {
    $result = new TextFormatRepairResult();

    $this->assertSame(0, $result->repaired);
    $this->assertSame([], $result->deferred);
    $this->assertSame(0, $result->deferredEntityCount());
    $this->assertSame([], $result->deferredEntityIds());
    $this->assertSame([], $result->droppedTags());
  }

  /**
   * Several revisions of one node count as one node, not several.
   *
   * @covers ::deferredEntityCount
   * @covers ::deferredEntityIds
   */
  public function testRevisionsOfOneEntityCollapseToOneEntity(): void {
    $result = new TextFormatRepairResult(0, [
      $this->deferral(12, 40, ['a']),
      $this->deferral(12, 41, ['a']),
      $this->deferral(9, 22, ['br']),
    ]);

    $this->assertSame([9, 12], $result->deferredEntityIds());
    $this->assertSame(2, $result->deferredEntityCount());
  }

  /**
   * Dropped tags are pooled across rows, deduplicated and sorted.
   *
   * @covers ::droppedTags
   */
  public function testDroppedTagsArePooledAndDeduplicated(): void {
    $result = new TextFormatRepairResult(0, [
      $this->deferral(12, 40, ['br', 'a']),
      $this->deferral(9, 22, ['a']),
    ]);

    $this->assertSame(['a', 'br'], $result->droppedTags());
  }

}
