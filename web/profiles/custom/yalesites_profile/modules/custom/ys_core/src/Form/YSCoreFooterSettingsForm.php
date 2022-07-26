<?php

namespace Drupal\ys_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for footer settings.
 *
 * @package Drupal\ys_core\Form
 */
class YSCoreFooterSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_core_footer_settings_form';
  }

  /**
   * Settings configuration form.
   *
   * @param array $form
   *   Form array.
   * @param Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return array
   *   Form array to render.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    // Form constructor.
    $form = parent::buildForm($form, $form_state);
    // Default settings.
    $config = $this->config('ys_core.settings');

    $form['social_links'] = [
      '#type' => 'details',
      '#title' => $this->t('Social Links'),
      '#open' => TRUE,
    ];

    $form['social_links']['social_facebook_link'] = [
      '#type' => 'url',
      '#title' => $this->t('Facebook URL'),
      '#default_value' => $config->get('ys_core.social_facebook_link'),
    ];

    $form['social_links']['social_instagram_link'] = [
      '#type' => 'url',
      '#title' => $this->t('Instagram URL'),
      '#default_value' => $config->get('ys_core.social_instagram_link'),
    ];

    $form['social_links']['social_twitter_link'] = [
      '#type' => 'url',
      '#title' => $this->t('Twitter URL'),
      '#default_value' => $config->get('ys_core.social_twitter_link'),
    ];

    $form['social_links']['social_youtube_link'] = [
      '#type' => 'url',
      '#title' => $this->t('YouTube URL'),
      '#default_value' => $config->get('ys_core.social_youtube_link'),
    ];

    $form['social_links']['social_weibo_link'] = [
      '#type' => 'url',
      '#title' => $this->t('Weibo URL'),
      '#default_value' => $config->get('ys_core.social_weibo_link'),
    ];

    return $form;
  }

  /**
   * Submit form action.
   *
   * @param array $form
   *   Form array.
   * @param Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('ys_core.settings');
    $config->set('ys_core.social_facebook_link', $form_state->getValue('social_facebook_link'));
    $config->set('ys_core.social_instagram_link', $form_state->getValue('social_instagram_link'));
    $config->set('ys_core.social_twitter_link', $form_state->getValue('social_twitter_link'));
    $config->set('ys_core.social_youtube_link', $form_state->getValue('social_youtube_link'));
    $config->set('ys_core.social_weibo_link', $form_state->getValue('social_weibo_link'));
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
