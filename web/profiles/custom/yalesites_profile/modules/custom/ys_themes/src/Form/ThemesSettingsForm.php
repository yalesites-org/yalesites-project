<?php

namespace Drupal\ys_themes\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ys_themes\ThemeSettingsManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ys_themes\Form\ElementTypes\Radios;
use Drupal\ys_themes\Form\ElementTypes\Select;

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
      $form['global_settings'][$settingName] = $this->getElementByType($settingName, $settingDetail, $options);
    }
    $form['#attached']['library'][] = 'ys_themes/levers';

    return $form;
  }

  /**
   *
   */
  public function getElementByType($settingName, $settingDetail, $options) {
    $returnElement = NULL;

    switch ($settingDetail['type'] ?? 'radios') {
      case 'select':
        $returnElement = $this->select($settingName, $settingDetail, $options);
        break;

      default:
        $returnElement = $this->radio($settingName, $settingDetail, $options);
        break;
    }

    return $returnElement;
  }

  /**
   *
   */
  public function select($settingName, $settingDetail, $options) {
    $element = new Select();
    return $element->toElementDefinition($settingName, $settingDetail, $options, $this->themeSettingsManager);
    /* return [ */
    /*   '#type' => 'select', */
    /*   '#title' => $this->t( */
    /*     '@setting_name', */
    /*     ['@setting_name' => $settingDetail['name']] */
    /*   ), */
    /*   '#options' => $options, */
    /*   '#default_value' => $this->themeSettingsManager->getSetting($settingName) ?: $settingDetail['default'], */
    /*   '#attributes' => [ */
    /*     'class' => [ */
    /*       'ys-themes--setting', */
    /*     ], */
    /*     'data-prop-type' => $settingDetail['prop_type'], */
    /*     'data-selector' => $settingDetail['selector'], */
    /*   ], */
    /* ]; */

  }

  /**
   *
   */
  public function radio($settingName, $settingDetail, $options) {
    $element = new Radios();
    return $element->toElementDefinition($settingName, $settingDetail, $options, $this->themeSettingsManager);

    /* return [ */
    /*   '#type' => 'radios', */
    /*   '#title' => $this->t( */
    /*     '@setting_name', */
    /*     ['@setting_name' => $settingDetail['name']] */
    /*   ), */
    /*   '#options' => $options, */
    /*   '#default_value' => $this->themeSettingsManager->getSetting($settingName) ?: $settingDetail['default'], */
    /*   '#attributes' => [ */
    /*     'class' => [ */
    /*       'ys-themes--setting', */
    /*     ], */
    /*     'data-prop-type' => $settingDetail['prop_type'], */
    /*     'data-selector' => $settingDetail['selector'], */
    /*   ], */
    /* ]; */

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
    );
  }

  /**
   * Constructs the object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\ys_themes\ThemeSettingsManager $theme_settings_manager
   *   The Theme Settings Manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ThemeSettingsManager $theme_settings_manager) {
    parent::__construct($config_factory);
    $this->themeSettingsManager = $theme_settings_manager;
  }

}
