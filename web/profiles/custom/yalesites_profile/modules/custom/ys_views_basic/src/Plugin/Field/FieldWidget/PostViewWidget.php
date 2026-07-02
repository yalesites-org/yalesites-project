<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Post listing widget.
 *
 * Serves the post_card, post_list_item, and post_condensed bundles. The
 * display mode is fixed by the bundle (ADR DR-2), so this widget only adds the
 * post-specific controls: the "Show post teaser lead-in" (eyebrow) option and
 * the post-only "Show Year" exposed filter. All shared form logic lives in
 * ViewsBasicWidgetBase.
 *
 * @FieldWidget(
 *   id = "post_view_widget",
 *   label = @Translation("Post listing widget"),
 *   field_types = {
 *     "views_basic_params"
 *   }
 * )
 */
class PostViewWidget extends ViewsBasicWidgetBase {

  /**
   * {@inheritdoc}
   */
  protected function getContentType(): ?string {
    return ViewsBasicManager::CONTENT_TYPE_POST;
  }

  /**
   * {@inheritdoc}
   *
   * Adds the post-only "Show post teaser lead-in" (eyebrow) option. No #states
   * are needed because this widget only ever serves posts.
   */
  protected function buildEntitySpecificOptions(array &$form, FieldItemListInterface $items, int $delta): void {
    $form['group_user_selection']['entity_and_view_mode']['post_field_options'] = [
      '#type' => 'checkboxes',
      '#options' => [
        'show_eyebrow' => $this->t('Show post teaser lead-in'),
      ],
      '#title' => $this->t('Post Field Display Options'),
      '#tree' => TRUE,
      '#default_value' => $items[$delta]->params
        ? $this->viewsBasicManager->getDefaultParamValue('post_field_options', $items[$delta]->params)
        : [],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Posts add the "Show Year" exposed filter to the shared set.
   */
  protected function getExposedFilterOptions(): array {
    $options = parent::getExposedFilterOptions();
    $options['show_year_filter'] = $this->t('Show Year');
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function massageEntitySpecificParams(array &$paramData, array $form, FormStateInterface $form_state): void {
    $paramData['post_field_options'] = $form['group_user_selection']['entity_and_view_mode']['post_field_options']['#value'];
  }

}
