<?php

namespace Drupal\ys_campus_groups\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines the manage Campus Group settings interface.
 */
class CampusGroupsSettings extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_campus_groups_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ys_campus_groups.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ys_campus_groups.settings');

    if ($config->get('enable_campus_groups_sync')) {
      $form['sync_now_button'] = [
        '#type' => 'markup',
        '#markup' => '<a class="button" href="/admin/yalesites/campus_groups/sync">Sync now</a>',
      ];
    }

    $form['enable_campus_groups_sync'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Campus Group sync'),
      '#description' => $this->t('Once enabled, Campus Group data will sync events for the selected group roughly every hour.'),
      '#default_value' => $config->get('enable_campus_groups_sync') ?: FALSE,
    ];

    $form['campus_groups_endpoint'] = [
      '#type' => 'url',
      '#required' => TRUE,
      '#title' => $this->t('Campus Group endpoint base URL'),
      '#description' => $this->t('Ex: https://yaleconnect.yale.edu/rss_events'),
      '#default_value' => $config->get('campus_groups_endpoint') ?: 'https://yaleconnect.yale.edu/rss_events',
    ];
    $form['campus_groups_groupids'] = [
      '#type' => 'textfield',
      '#required' => TRUE,
      '#title' => $this->t('Group ids'),
      '#default_value' => $config->get('campus_groups_groupids'),
      '#description' => $this->t('Enter the group ids comma seperated'),
    ];

    $form['campus_groups_api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Campus Groups Authentication Credentials'),
      '#default_value' => $config->get('campus_groups_api_key') ?: '',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $this->configFactory->getEditable('ys_campus_groups.settings')
      ->set('enable_campus_groups_sync', $form_state->getValue('enable_campus_groups_sync'))
      ->set('campus_groups_endpoint', rtrim($form_state->getValue('campus_groups_endpoint'), "/"))
      ->set('campus_groups_groupids', $form_state->getValue('campus_groups_groupids'))
      ->set('campus_groups_api_key', $form_state->getValue('campus_groups_api_key'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
