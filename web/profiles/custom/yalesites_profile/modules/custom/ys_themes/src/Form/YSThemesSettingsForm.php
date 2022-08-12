<?php

namespace Drupal\ys_themes\Form;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for YaleSites themes settings.
 *
 * @package Drupal\ys_themes\Form
 */
class YSThemesSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_themes_settings_form';
  }

  /**
   * THe Drupal backend cache renderer service.
   *
   * @var \Drupal\Core\Path\CacheBackendInterface
   */
  protected $cacheRender;

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
    $config = $this->config('ys_themes.theme_settings');

    $form['global_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Global Settings'),
    ];

    $form['global_settings']['action_color'] = [
      '#type' => 'radios',
      '#title' => $this->t('Action Color'),
      '#options' => [
        'blue' => $this->t('Blue'),
      ],
      '#default_value' => $config->get('action_color'),
    ];

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
    $config = $this->config('ys_themes.theme_settings');
    $config->set('action_color', $form_state->getValue('action_color'));
    $config->save();
    $this->cacheRender->invalidateAll();
    return parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'ys_themes.theme_settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('cache.render'),
    );
  }

  /**
   * Constructs the object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Path\CacheBackendInterface $cache_render
   *   The Cache backend interface.
   */
  public function __construct(ConfigFactoryInterface $config_factory, CacheBackendInterface $cache_render) {
    parent::__construct($config_factory);
    $this->cacheRender = $cache_render;
  }

}
