<?php

namespace Drupal\ys_core_test\Form;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * A settings form carrying media_library elements at each cardinality.
 *
 * Stands in for the real ys_core settings forms, which all place
 * '#type' => 'media_library' elements on plain config forms. Building those
 * forms in a kernel test would drag in most of the platform; this carries the
 * element under test at the cardinalities they use.
 *
 * Every one of those production elements is effectively cardinality 1: the
 * Site Settings fallback teaser image, the Header Settings focus image, and
 * both Footer Settings logos. Footer Settings does use '#cardinality' => 2,
 * but on the multivalue wrapper around the element, not on the element itself.
 * The higher cardinalities here therefore guard the element's own arithmetic
 * rather than mirroring a specific form.
 *
 * @see \Drupal\ys_core\Form\SiteSettingsForm
 * @see \Drupal\ys_core\Form\HeaderSettingsForm
 * @see \Drupal\ys_core\Form\FooterSettingsForm
 */
class MediaLibraryTestForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_core_test_media_library_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('ys_core_test.media_library.settings');

    // No explicit '#cardinality', so this defaults to 1 -- the shape every
    // real ys_core media_library element has.
    $form['single'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['image'],
      '#title' => $this->t('Single image'),
      '#default_value' => $config->get('single') ?: NULL,
    ];

    $form['multiple_partial'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['image'],
      '#title' => $this->t('Two images, one selected'),
      '#default_value' => $config->get('multiple_partial') ?: NULL,
      '#cardinality' => 2,
    ];

    $form['multiple_full'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['image'],
      '#title' => $this->t('Two images, both selected'),
      '#default_value' => $config->get('multiple_full') ?: NULL,
      '#cardinality' => 2,
    ];

    $form['unlimited'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['image'],
      '#title' => $this->t('Unlimited images'),
      '#default_value' => $config->get('unlimited') ?: NULL,
      '#cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ys_core_test.media_library.settings'];
  }

}
