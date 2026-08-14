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
 * @see \Drupal\ys_layouts\Plugin\Layout\YSLayoutOptions
 * @see ys_layouts_layout_alter()
 */
class YSLayoutOneColumn extends YSLayoutOptions {

  /**
   * {@inheritdoc}
   *
   * Overrides (does not drop) the inherited 'divider' default: parent's
   * YSLayoutOptions::defaultConfiguration() sets it to '' (string), but a
   * real checkbox submission stores an int (Drupal core's Checkbox element
   * always resolves to 0/1). Since 'divider' is hidden here rather than
   * unset -- see buildConfigurationForm() -- every layout_onecol section
   * still carries this key, so its default has to match the type the config
   * schema declares (config/schema/ys_layouts.schema.yml) for every save
   * path, including one that never goes through the form at all (a config
   * entity's default section built directly from defaultConfiguration(),
   * e.g. core.entity_view_display.*.yml).
   */
  public function defaultConfiguration() {
    return [
      'divider' => 0,
    ] + parent::defaultConfiguration();
  }

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
