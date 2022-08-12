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

    $form['global_settings']['pull_quote_color'] = [
      '#type' => 'radios',
      '#title' => $this->t('Pull Quote Accent Color'),
      '#options' => [
        'gray-200' => $this->t('Light Gray'),
        'gray-500' => $this->t('Gray'),
        'blue' => $this->t('Blue'),
        'accent' => $this->t('Accent'),
      ],
      '#default_value' => $config->get('pull_quote_color'),
    ];

    $form['global_settings']['line_color'] = [
      '#type' => 'radios',
      '#title' => $this->t('Line Color'),
      '#options' => [
        'gray-500' => $this->t('Gray'),
        'blue' => $this->t('Blue'),
        'accent' => $this->t('Accent'),
      ],
      '#default_value' => $config->get('pull_quote_color'),
    ];

    $form['global_settings']['line_thickness'] = [
      '#type' => 'radios',
      '#title' => $this->t('Line Thickness'),
      '#options' => [
        'thin' => $this->t('Thin'),
        'thick' => $this->t('Thick'),
      ],
      '#default_value' => $config->get('line_thickness'),
    ];

    $form['global_settings']['nav_position'] = [
      '#type' => 'radios',
      '#title' => $this->t('Navigation Position'),
      '#options' => [
        'right' => $this->t('Right'),
        'center' => $this->t('Center'),
        'left' => $this->t('Left'),
      ],
      '#default_value' => $config->get('nav_position'),
    ];

    $form['global_settings']['header_theme'] = [
      '#type' => 'radios',
      '#title' => $this->t('Header Theme'),
      '#options' => [
        'white' => $this->t('White'),
        'gray-100' => $this->t('Light Gray'),
        'blue' => $this->t('Blue'),
      ],
      '#default_value' => $config->get('header_theme'),
    ];

    $form['global_settings']['footer_theme'] = [
      '#type' => 'radios',
      '#title' => $this->t('Footer Theme'),
      '#options' => [
        'white' => $this->t('White'),
        'gray-100' => $this->t('Light Gray'),
        'gray-700' => $this->t('Gray'),
        'gray-800' => $this->t('Dark Gray'),
        'blue' => $this->t('Blue'),
      ],
      '#default_value' => $config->get('footer_theme'),
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
    $config->set('pull_quote_color', $form_state->getValue('pull_quote_color'));
    $config->set('line_color', $form_state->getValue('line_color'));
    $config->set('line_thickness', $form_state->getValue('line_thickness'));
    $config->set('nav_position', $form_state->getValue('nav_position'));
    $config->set('header_theme', $form_state->getValue('header_theme'));
    $config->set('footer_theme', $form_state->getValue('footer_theme'));
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
