<?php

namespace Drupal\ys_book\Controller;

use Drupal\book\Controller\BookController;
use Drupal\Core\Url;

/**
 * Changes text that comes from the book controller.
 */
class YsBookController extends BookController {

  /**
   * Overrides the book admin overview page.
   *
   * @return array
   *   A render array for the admin overview page.
   */
  public function adminOverview(): array {
    $build = parent::adminOverview();

    // Contrib builds these cells as plain strings, which renders a <th> with
    // no scope for a screen reader to associate the column by. Promote each
    // cell to an array to carry the attribute, normalizing rather than
    // assuming a string so a later contrib change cannot nest a cell inside
    // itself. The rename has to happen in the same pass: reading a cell as a
    // string after this point would fail. template_preprocess_table() passes
    // any key that is not data/header/colspan/class/sort/field through to the
    // <th> as an attribute.
    foreach ($build['#header'] ?? [] as $index => $cell) {
      $cell = is_array($cell) ? $cell : ['data' => $cell];
      if ((string) $cell['data'] === 'Book') {
        $cell['data'] = $this->t('Collection');
      }
      $cell['scope'] = 'col';
      $build['#header'][$index] = $cell;
    }

    // Replace the empty message.
    if (isset($build['#empty'])) {
      $build['#empty'] = $this->t('No content collections available.');
    }

    // Add a "Delete collection" operation to each row. The parent builds one
    // row per book from the book manager, in the same order, so the book IDs
    // (which equal each collection's top-level page node ID) line up
    // positionally with the rows.
    $bids = array_keys($this->bookManager->getAllBooks());
    foreach ($build['#rows'] ?? [] as $index => $row) {
      $last = array_key_last($row);
      if (!isset($bids[$index], $row[$last]['data']['#links'])) {
        continue;
      }
      $build['#rows'][$index][$last]['data']['#links']['delete'] = [
        'title' => $this->t('Delete collection'),
        'url' => Url::fromRoute('ys_book.collection_delete', ['node' => $bids[$index]]),
      ];
    }

    return $build;
  }

}
