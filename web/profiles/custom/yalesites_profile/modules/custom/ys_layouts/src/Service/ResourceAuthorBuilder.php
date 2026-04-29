<?php

namespace Drupal\ys_layouts\Service;

use Drupal\Core\Cache\Cache;
use Drupal\node\NodeInterface;

/**
 * Builds the merged author list for a Resource node.
 *
 * Resources can have two parallel author groups: affiliated authors
 * (entity references to profile nodes via field_authors) and non-affiliated
 * authors (Double Field rows of first/last name via
 * field_nonaffiliated_authors). The two are merged into a single list,
 * sorted by last-then-first using en_US Unicode collation, and returned
 * in render-ready form so the block template, the node preprocess hook,
 * and any future caller produce identical output.
 *
 * Particles ("van", "de la", "von") are preserved literally per project
 * requirement: "van der Berg" sorts under V, "de la Cruz" under D.
 */
class ResourceAuthorBuilder {

  /**
   * Unicode collator used for last-then-first author sorting.
   *
   * @var \Collator
   */
  protected \Collator $collator;

  /**
   * Constructs a ResourceAuthorBuilder.
   *
   * @param \Collator $collator
   *   Unicode collator (en_US) used for author sorting. Injected so tests
   *   can swap in a different locale and to stay consistent with other
   *   ys_layouts services.
   */
  public function __construct(\Collator $collator) {
    $this->collator = $collator;
  }

  /**
   * Builds the merged, sorted author list for a Resource node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The Resource node being rendered.
   * @param array $cache_tags
   *   By-reference accumulator for cache tags from referenced profile
   *   entities. Callers should merge these into the surrounding render
   *   array's `#cache.tags` so author edits invalidate the output.
   *
   * @return array
   *   List of author entries, each an associative array with keys:
   *   - label: Display string. Affiliated authors use the profile node's
   *     auto-generated Display Name (prefix + first + last); non-affiliated
   *     authors render as "First Last".
   *   - url: Profile URL (string) for affiliated authors, NULL for
   *     non-affiliated.
   */
  public function build(NodeInterface $node, array &$cache_tags = []): array {
    $entries = [];

    // Affiliated: entity references to profile nodes. The profile node title
    // is the "Display Name" (auto-built from honorific prefix + first + last)
    // and is what should render. Sort keys still come from the discrete
    // first/last fields so the prefix doesn't pull every author under "D".
    if ($node->hasField('field_authors') && !$node->get('field_authors')->isEmpty()) {
      foreach ($node->get('field_authors')->referencedEntities() as $profile) {
        if (!$profile->access('view')) {
          continue;
        }
        $first = $profile->hasField('field_first_name')
          ? trim((string) $profile->get('field_first_name')->getString())
          : '';
        $last = $profile->hasField('field_last_name')
          ? trim((string) $profile->get('field_last_name')->getString())
          : '';
        $label = (string) $profile->label();
        $entries[] = [
          'label' => $label,
          'url' => $profile->toUrl()->toString(),
          'sort_last' => $last !== '' ? $last : $label,
          'sort_first' => $first,
        ];
        $cache_tags = Cache::mergeTags($cache_tags, $profile->getCacheTags());
      }
    }

    // Non-affiliated: Double Field rows with `first` and `second` columns.
    if (
      $node->hasField('field_nonaffiliated_authors')
      && !$node->get('field_nonaffiliated_authors')->isEmpty()
    ) {
      foreach ($node->get('field_nonaffiliated_authors') as $item) {
        $first = trim((string) ($item->first ?? ''));
        $last = trim((string) ($item->second ?? ''));
        if ($first === '' && $last === '') {
          continue;
        }
        $entries[] = [
          'label' => trim($first . ' ' . $last),
          'url' => NULL,
          'sort_last' => $last,
          'sort_first' => $first,
        ];
      }
    }

    if (!$entries) {
      return [];
    }

    // en_US Unicode collation: Ö collates near O, ä near a, etc.
    $collator = $this->collator;
    usort($entries, function (array $a, array $b) use ($collator): int {
      $cmp = $collator->compare($a['sort_last'], $b['sort_last']);
      if ($cmp !== 0) {
        return $cmp;
      }
      return $collator->compare($a['sort_first'], $b['sort_first']);
    });

    return array_map(static fn(array $entry): array => [
      'label' => $entry['label'],
      'url' => $entry['url'],
    ], $entries);
  }

}
