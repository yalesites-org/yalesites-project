<?php

/**
 * Contains form class for ys_core settings.
 * 
 * @file
 */

namespace Drupal\ys_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class YSCoreFooterSettings.
 *
 * @package Drupal\ys_core\Form
 */

class YSCoreFooterSettings extends ConfigFormBase {

    /**
     * {@inheritdoc}
     */

  public function getFormId() {
    return 'ys_core_settings_form';
  }

  /**
   * @param array $form form array
   * @param Drupal\Core\Form\FormStateInterface $form_state form state
   * @return array form array to render
   * Settings configuration form
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    // Form constructor.
    $form = parent::buildForm($form, $form_state);
    // Default settings.
    $config = $this->config('ys_core.settings');

    $form['social_facebook_link'] = [
      '#type' => 'url',
      '#title' => $this->t('Social Facebook Link'),
      '#default_value' => $config->get('ys_core.social_facebook_link'),
    ];

    return $form;
  }

  /**
   * @param array $form form array
   * @param Drupal\Core\Form\FormStateInterface $form_state form state
   * Submit form action
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('ys_core.settings');
    $config->set('ys_core.social_facebook_link', $form_state->getValue('social_facebook_link'));
    $config->save();
    return parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */

  protected function getEditableConfigNames() {
    return [
      'ys_core.settings',
    ];
  }

}
