<?php

namespace Drupal\ys_demo_content\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ys_demo_content\DemoContent;

class YaleSitesDemoContentSettings extends ConfigFormBase {

    public function getFormId() {
    return 'ys_demo_content_form';
  }

  /**
   * @param array $form
   * @param FormStateInterface $form_state
   * @return array
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    //\Drupal::classResolver()->getInstanceFromDefinition(DemoContent::class)->get_yml_data();

    // Form constructor
    $form = parent::buildForm($form, $form_state);
    // Default settings
    //$config = $this->config('ys_demo_content.settings');

    $renderable = [
      '#theme' => 'admin_action_links',
    ];

    $rendered = \Drupal::service('renderer')->renderPlain($renderable);

    $form['action_links'] = array(
      '#type' => 'item',
      '#markup' => $rendered,
    );

    // $form['general'] = array(
    //   '#type' => 'details',
    //   '#title' => $this->t('General Settings'),
    //   '#open' => TRUE,
    // );

    // $form['general']['debug'] = array(
    //   '#type' => 'checkbox',
    //   '#title' => $this->t('Delete demo content on uninstall'),
    //   '#description' => $this->t('Deletes all content created by YaleSites Demo Content on uninstall, even if it has been edited.'),
    //   '#default_value' => $config->get('ys_demo_content.delete_content_uninstall'),
    // );

    return $form;
  }

  /**
   * @param array $form
   * @param FormStateInterface $form_state
   */
  // public function submitForm(array &$form, FormStateInterface $form_state) {
  //   $config = $this->config('ys_demo_content.settings');
  //   // General
  //   $config->set('ys_demo_content.delete_content_uninstall', $form_state->getValue('delete_content_uninstall'));

  //   $config->save();

  //   return parent::submitForm($form, $form_state);
  // }

  protected function getEditableConfigNames() {
    return [
      'ys_demo_content.settings',
    ];
  }

}
