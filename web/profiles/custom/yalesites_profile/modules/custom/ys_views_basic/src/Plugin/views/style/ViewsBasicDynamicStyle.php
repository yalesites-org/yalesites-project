<?php

namespace Drupal\ys_views_basic\Plugin\views\style;

use Drupal\views\Plugin\views\style\StylePluginBase;

/**
 * Unformatted style plugin to render rows with dynamic view mode.
 *
 * Row are rendered one after another with no decorations.
 *
 * @ingroup views_style_plugins
 *
 * @ViewsStyle(
 *   id = "ys_views_basic_dynamic_style",
 *   title = @Translation("Views Basic Dynamic Style"),
 *   help = @Translation("Displays rows one after another."),
 *   theme = "views_view_unformatted",
 *   display_types = {"normal"}
 * )
 */
class ViewsBasicDynamicStyle extends StylePluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesRowPlugin = TRUE;

  /**
   * Does the style plugin support custom css class for the rows.
   *
   * @var bool
   */
  protected $usesRowClass = TRUE;

  /**
   * {@inheritdoc}
   */
  public function preRender($result) {
    if (!empty($this->view->rowPlugin)) {

      // Gets passed view mode from ViewsBasicDefaultFormatter and sets per row.
      if (isset($this->view->args[3])) {
        $viewMode = $this->view->args[3];
        $validViewModes = \Drupal::service('entity_display.repository')->getViewModeOptions('node');
        if (array_key_exists($viewMode, $validViewModes)) {
          $this->view->rowPlugin->options['view_mode'] = $viewMode;
        }
        else {
          $this->view->rowPlugin->options['view_mode'] = 'teaser';
        }
      }

      $this->view->rowPlugin->preRender($result);
    }
  }

}
