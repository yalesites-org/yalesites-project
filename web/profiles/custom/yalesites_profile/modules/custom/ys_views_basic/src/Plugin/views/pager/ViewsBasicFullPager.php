<?php

namespace Drupal\ys_views_basic\Plugin\views\pager;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsPager;
use Drupal\views\Plugin\views\pager\Full;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * The plugin to handle full pager.
 *
 * @ingroup views_pager_plugins
 */
#[ViewsPager(
  id: 'views_basic_full_pager',
  title: new TranslatableMarkup('Views Basic Paged output, full pager'),
  short_title: new TranslatableMarkup('VB Full'),
  help: new TranslatableMarkup('Paged output, full Drupal style'),
  theme: 'pager',
  register_theme: FALSE,
)]
class ViewsBasicFullPager extends Full {

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    if (!$this->hasItemsPerPage()) {
      return;
    }
    // The offset is $this->options['offset'], set by the managers through
    // ViewExecutable::setOffset(). Not read from view->args: the two managers
    // put it at different positions.
    $this->setItemsPerPage($this->itemsPerPage());
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
   * {@inheritdoc}
   *
   * A listing that does not page always starts at its first item. Its pager
   * element is 0, and ?page for element 0 can belong to a paged listing on
   * the same page.
   */
  public function setCurrentPage($number = NULL) {
    if ($this->isNonPagedListing()) {
      $this->current_page = 0;
      return;
    }
    parent::setCurrentPage($number);
  }

  /**
   * {@inheritdoc}
   *
   * A listing that does not page registers no pager. The pager manager keeps
   * one pager per element and the pager template reads it at render time, so
   * a non-paged listing on element 0 would replace the totals of a paged
   * listing on that element and give it extra, empty pages
   * (yalesites-org/YaleSites-Internal#1612).
   */
  public function updatePageInfo() {
    if ($this->isNonPagedListing()) {
      return;
    }
    parent::updatePageInfo();
  }

  /**
   * Checks if the view is a Views Basic listing that does not page.
   *
   * @return bool
   *   TRUE when the stored params say the listing does not page. FALSE when
   *   it pages or the arguments carry no usable params (Content Resources).
   */
  protected function isNonPagedListing(): bool {
    return ViewsBasicManager::usesPagerFromArgs($this->view->args ?? []) === FALSE;
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
    return isset($this->view->args[ViewsBasicManager::viewArgumentIndex('items')]);

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
    return (int) $this->view->args[ViewsBasicManager::viewArgumentIndex('items')];

  }

}
