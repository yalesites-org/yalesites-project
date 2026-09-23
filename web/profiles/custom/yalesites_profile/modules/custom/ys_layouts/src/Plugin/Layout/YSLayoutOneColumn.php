<?php

namespace Drupal\ys_layouts\Plugin\Layout;

use Drupal\Core\Form\FormStateInterface;

/**
 * Layout class attached to core's "One column" layout via hook_layout_alter.
 *
 * Extends YSLayoutOptions so `layout_onecol` sections get the same
 * "Component theme" picker as Two Column (50/50), Two Column (70/30), and
 * Three Column (33/33/33). One column has a single region, so there is
 * nothing to divide, and the inherited divider checkbox is hidden.
 *
 * The inherited 'divider' default needs no override here: YSLayoutOptions
 * defaults it to int 0 to match the type the config schema declares, which is
 * what every layout it backs needs, not just this one.
 *
 * @see \Drupal\ys_layouts\Plugin\Layout\YSLayoutOptions
 * @see ys_layouts_layout_alter()
 */
class YSLayoutOneColumn extends YSLayoutOptions {

  /**
   * {@inheritdoc}
   *
   * Hides rather than unset()s the divider element: parent's element carries
   * a '#default_value' read from $this->configuration['divider'] (set by
   * defaultConfiguration() above). '#access' => FALSE keeps the element out
   * of the rendered form while still round-tripping its stored value through
   * submit, so a save doesn't overwrite that default with the NULL an unset
   * element's getValue() would return.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['divider']['#access'] = FALSE;

    return $form;
  }

}
