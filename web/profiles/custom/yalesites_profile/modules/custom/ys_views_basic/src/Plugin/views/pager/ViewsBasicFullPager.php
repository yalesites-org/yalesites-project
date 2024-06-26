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
  public function query() {
    if (!isset($this->view->args[5])) {
      return;
    }
    $this->setItemsPerPage((int) $this->view->args[5]);
    $this->setOffset($this->view->args[7]);
    $limit = $this->options['items_per_page'];
    $offset = $this->current_page * $this->options['items_per_page'] + $this->options['offset'];
    if (!empty($this->options['total_pages'])) {
      if ($this->current_page >= $this->options['total_pages']) {
        $limit = $this->options['items_per_page'];
        $offset = $this->options['total_pages'] * $this->options['items_per_page'];
      }
    }

    $this->view->query->setLimit($limit);
    $this->view->query->setOffset($offset);
  }

}
