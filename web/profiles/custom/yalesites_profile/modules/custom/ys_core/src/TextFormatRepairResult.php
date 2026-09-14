<?php

namespace Drupal\ys_core;

/**
 * The outcome of repairing one field's stored text formats.
 *
 * A repair has two possible outcomes per value, and the caller needs both: the
 * rows that were corrected, and the rows that were deliberately left alone
 * because correcting them would have dropped markup the target format does not
 * permit. The second list is the interesting one — it is what a human has to
 * look at before the repair can be widened.
 *
 * @see \Drupal\ys_core\TextFormatRepair::repairFieldStorage()
 * @see yalesites-org/YaleSites-Internal#1646
 */
class TextFormatRepairResult {

  /**
   * Constructs a new TextFormatRepairResult.
   *
   * @param int $repaired
   *   The number of rows whose format column was rewritten.
   * @param array $deferred
   *   The rows left alone because the repair would have been lossy, each an
   *   associative array of:
   *   - entity_id: int, the entity the value belongs to.
   *   - revision_id: int, the revision the row belongs to.
   *   - from: string, the out-of-contract format currently stored.
   *   - to: string, the format the value would have been repaired to.
   *   - dropped: string[], the HTML tag names that repairing would remove from
   *     the rendered output.
   */
  public function __construct(
    public readonly int $repaired = 0,
    public readonly array $deferred = [],
  ) {}

  /**
   * Returns the number of entities holding a deferred value.
   *
   * Deferrals are counted per row, so one entity contributes a row for its
   * default revision and one for every other revision carrying the same bad
   * format. A human chasing these down cares about the nodes, not the rows.
   *
   * @return int
   *   The distinct entity count across the deferred rows.
   */
  public function deferredEntityCount(): int {
    return count($this->deferredEntityIds());
  }

  /**
   * Returns the distinct entity IDs holding a deferred value.
   *
   * @return int[]
   *   The entity IDs, in ascending order.
   */
  public function deferredEntityIds(): array {
    $ids = array_values(array_unique(array_column($this->deferred, 'entity_id')));
    sort($ids);

    return $ids;
  }

  /**
   * Returns the distinct HTML tags a deferred repair would have dropped.
   *
   * @return string[]
   *   The tag names, alphabetically, without angle brackets.
   */
  public function droppedTags(): array {
    $tags = array_merge([], ...array_column($this->deferred, 'dropped'));
    $tags = array_values(array_unique($tags));
    sort($tags);

    return $tags;
  }

}
