<?php

namespace Drupal\ys_layouts\Plugin\Layout;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Layout\LayoutDefault;

/**
 * Configuration per section for YS Layouts.
 */
class YSLayoutOptions extends LayoutDefault {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $configuration = parent::defaultConfiguration();

    return $configuration + [
      'divider' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['divider'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Divider'),
      '#default_value' => $this->configuration['divider'],
      '#description' => $this->t('Add a divider between the columns.'),
      '#weight' => 10,
    ];

    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $this->configuration['divider'] = $form_state->getValue('divider');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $regions): array {
    $build = parent::build($regions);
    //kint($build);
    //$build['divider'] = $this->configuration['divider'];

    return $build;
  }

}
