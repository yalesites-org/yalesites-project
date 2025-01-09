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
    return 'ys_campus_group_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ys_campus_group.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ys_campus_group.settings');

    $form['enable_campus_group_sync'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Campus Group sync'),
      '#description' => $this->t('Once enabled, Campus Group data will sync events for the selected group roughly every hour.'),
      '#default_value' => $config->get('enable_campus_group_sync') ?: FALSE,
    ];

    $form['campus_group_endpoint'] = [
      '#type' => 'url',
      '#required' => TRUE,
      '#title' => $this->t('Campus Group endpoint base URL'),
      '#description' => $this->t('Ex: https://yaleconnect.yale.edu'),
      '#default_value' => $config->get('campus_group_endpoint') ?: 'https://yaleconnect.yale.edu',
    ];
    $form['campus_group_future_days'] = [
      '#type' => 'textfield',
      '#required' => TRUE,
      '#title' => $this->t('Future number of days'),
      '#default_value' => $config->get('campus_group_future_days') ?: 120,
      '#description' => $this->t('Enter the number of future days'),
    ];
    $form['campus_group_groupids'] = [
      '#type' => 'textfield',
      '#required' => TRUE,
      '#title' => $this->t('Group ids'),
      '#default_value' => $config->get('campus_group_groupids'),
      '#description' => $this->t('Enter the group ids comman seperated'),
    ];
    $form['enable_campus_group_redirect'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically redirect users to Campus Groups when they click an event card'),
      '#default_value' => $config->get('enable_campus_group_redirect') ?: FALSE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $this->configFactory->getEditable('ys_campus_group.settings')
       // Set the submitted configuration setting.
      ->set('enable_campus_group_sync', $form_state->getValue('enable_campus_group_sync'))
      ->set('campus_group_endpoint', rtrim($form_state->getValue('campus_group_endpoint'), "/"))
      ->set('campus_group_future_days', $form_state->getValue('campus_group_future_days'))
      ->set('campus_group_groupids', $form_state->getValue('campus_group_groupids'))
      ->set('enable_campus_group_redirect', $form_state->getValue('enable_campus_group_redirect'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
