<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Page listing widget.
 *
 * Serves the page_card, page_list_item, and page_condensed bundles. Pages are
 * the simplest content type: they have no content-type-specific form controls
 * beyond the shared filters, sort, and display options provided by
 * ViewsBasicWidgetBase. The page category filter resolves to the
 * field_category_target_id_1 view filter in ViewsBasicManager::setupView()
 * (ADR DR-4); that render mapping lives in the manager, not here.
 *
 * @FieldWidget(
 *   id = "page_view_widget",
 *   label = @Translation("Page listing widget"),
 *   field_types = {
 *     "views_basic_params"
 *   }
 * )
 */
class PageViewWidget extends ViewsBasicWidgetBase {

  /**
   * {@inheritdoc}
   */
  protected function getContentType(): ?string {
    return ViewsBasicManager::CONTENT_TYPE_PAGE;
  }

  /**
   * {@inheritdoc}
   *
   * Pages have no content-type-specific controls.
   */
  protected function buildEntitySpecificOptions(array &$form, FieldItemListInterface $items, int $delta): void {
  }

}
