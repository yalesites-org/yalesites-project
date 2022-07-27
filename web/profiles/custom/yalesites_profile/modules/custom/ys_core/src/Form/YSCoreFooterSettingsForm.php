<?php

namespace Drupal\ys_core\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ys_core\SocialLinksManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * Social Links Manager.
   *
   * @var \Drupal\ys_core\SocialLinksManager
   */
  protected $socialLinks;

  /**
   * Settings configuration form.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return array
   *   Form array to render.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form = parent::buildForm($form, $form_state);
    $config = $this->config('ys_core.social_links');
    $form['social_links'] = [
      '#type' => 'details',
      '#title' => $this->t('Social Links'),
      '#open' => TRUE,
    ];
    foreach($this->socialLinks::SITES as $id => $name) {
      $form['social_links'][$id] = [
        '#type' => 'url',
        '#title' => $this->t("$name URL"),
        '#default_value' => $config->get($id),
      ];
    }
    return $form;
  }

  /**
   * Submit form action.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('ys_core.social_links');
    foreach($this->socialLinks::SITES as $id => $name) {
      $config->set($id, $form_state->getValue($id));
    }
    $config->save();
    return parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'ys_core.social_links',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('ys_core.social_links_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(ConfigFactoryInterface $config_factory, SocialLinksManager $social_links_manager) {
    parent::__construct($config_factory);
    $this->socialLinks = $social_links_manager;
  }

}
