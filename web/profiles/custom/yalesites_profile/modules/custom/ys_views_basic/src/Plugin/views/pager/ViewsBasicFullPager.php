<?php

namespace Drupal\ys_views_basic\Plugin\views\pager;

use Drupal\views\Plugin\views\pager\Full;

/**
 * The plugin to handle full pager.
 *
 * @ingroup views_pager_plugins
 *
 * @ViewsPager(
 *   id = "views_basic_full_pager",
 *   title = @Translation("Views Basic Paged output, full pager"),
 *   short_title = @Translation("VB Full"),
 *   help = @Translation("Paged output, full Drupal style"),
 *   theme = "pager",
 *   register_theme = FALSE
 * )
 */
class ViewsBasicFullPager extends Full {

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    if (!$this->hasItemsPerPage()) {
      return;
    }
    $this->setItemsPerPage($this->itemsPerPage());
    $this->setOffset($this->offset());
    $limit = $this->options['items_per_page'];
    $offset = $this->current_page * $this->options['items_per_page'] + $this->options['offset'];
    if ($this->hasTotalPages() && $this->pastLastPage()) {
      // Set them within the bounds of the total pages.
      $limit = $this->options['items_per_page'];
      $offset = $this->options['total_pages'] * $this->options['items_per_page'];
    }

    $this->view->query->setLimit($limit);
    $this->view->query->setOffset($offset);
  }

  /**
   * Checks if the current page is past the last page.
   *
   * @return bool
   *   TRUE if the current page is past the last page, FALSE otherwise.
   */
  protected function pastLastPage(): bool {
    return $this->current_page >= $this->options['total_pages'];
  }

  /**
   * Checks if the view has items per page argument.
   *
   * @return bool
   *   TRUE if items per page argument is set, FALSE otherwise.
   */
  protected function hasItemsPerPage(): bool {
    return isset($this->view->args[5]);

  }

  /**
   * Checks if the total pages option is set.
   *
   * @return bool
   *   TRUE if total pages option is set, FALSE otherwise.
   */
  protected function hasTotalPages(): bool {
    return (!empty($this->options['total_pages']));

  }

  /**
   * Gets the number of items per page.
   *
   * @return int
   *   The number of items per page.
   */
  protected function itemsPerPage(): int {
    return (int) $this->view->args[5];

  }

  /**
   * Gets the offset for the query.
   *
   * @return int
   *   The offset for the query.
   */
  protected function offset(): int {
    return (int) $this->view->args[7];

  }

}
