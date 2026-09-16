<?php

namespace Drupal\ys_themes\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ys_themes\ColorTokenResolver;
use Drupal\ys_themes\ThemeSettingsManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * YaleSites themes settings form.
 *
 * @package Drupal\ys_themes\Form
 */
class ThemesSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_themes_settings_form';
  }

  /**
   * Themes Settings Manager.
   *
   * @var \Drupal\ys_themes\Service\ThemeSettingsManager
   */
  protected $themeSettingsManager;

  /**
   * Color Token Resolver.
   *
   * @var \Drupal\ys_themes\ColorTokenResolver
   */
  protected $colorTokenResolver;

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

    $allSettings = $this->themeSettingsManager->getOptions();

    $form = parent::buildForm($form, $form_state);

    $form['global_settings'] = [
      '#type' => 'fieldset',
      '#attributes' => [
        'class' => [
          'ys-themes--global-settings',
        ],
      ],
    ];

    foreach ($allSettings as $settingName => $settingDetail) {
      $options = [];
      foreach ($settingDetail['values'] as $key => $value) {
        $options[$key] = $value['label'];
      }
      $form['global_settings'][$settingName] = [
        '#type' => 'radios',
        '#title' => $this->t(
          '@setting_name',
          ['@setting_name' => $settingDetail['name']]
        ),
        '#options' => $options,
        '#default_value' => $this->themeSettingsManager->getSetting($settingName) ?: $settingDetail['default'],
        '#attributes' => [
          'class' => [
            'ys-themes--setting',
          ],
          'data-prop-type' => $settingDetail['prop_type'],
          'data-selector' => $settingDetail['selector'],
        ],
      ];
    }

    $form['#attached']['library'][] = 'ys_themes/levers';
    // Every palette's colors, so selecting one can re-tint the swatches of the
    // settings that follow it without a round trip.
    $form['#attached']['drupalSettings']['ysThemes']['paletteColors'] = $this->colorTokenResolver->getSlotHexMap();

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
    $allSettings = $this->themeSettingsManager->getOptions();
    foreach ($allSettings as $settingName => $settingDetail) {
      $this->themeSettingsManager->setSetting($settingName, $form_state->getValue($settingName));
    }
    $form_state->setRedirect('<current>');
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
      $container->get('ys_themes.theme_settings_manager'),
      $container->get('ys_themes.color_token_resolver'),
    );
  }

  /**
   * Constructs the object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\ys_themes\ThemeSettingsManager $theme_settings_manager
   *   The Theme Settings Manager.
   * @param \Drupal\ys_themes\ColorTokenResolver $color_token_resolver
   *   The Color Token Resolver.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ThemeSettingsManager $theme_settings_manager, ColorTokenResolver $color_token_resolver) {
    parent::__construct($config_factory);
    $this->themeSettingsManager = $theme_settings_manager;
    $this->colorTokenResolver = $color_token_resolver;
  }

}
