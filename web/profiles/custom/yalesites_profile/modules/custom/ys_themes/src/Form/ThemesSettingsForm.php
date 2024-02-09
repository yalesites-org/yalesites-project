<?php

namespace Drupal\ys_themes\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\ys_themes\ThemeSettingsManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * YaleSites themes settings form.
 *
 * @package Drupal\ys_themes\Form
 */
class ThemesSettingsForm extends ConfigFormBase {

  /**
   * The path validator service.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected $pathValidator;

  /**
   * The request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Themes Settings Manager.
   *
   * @var \Drupal\ys_themes\Service\ThemeSettingsManager
   */
  protected $themeSettingsManager;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_themes_settings_form';
  }

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
    $form['#attached']['library'][] = 'ys_themes/levers';

    // Display a custom message if the users is not currently viewing a node.
    // This interface is most useful as a preview tool when viewing content.
    if (!$this->isCurrentPageNodeView()) {
      $form['message'] = [
        '#theme' => 'ys_theme_settings_unavailable',
      ];
      return $form;
    }

    $allSettings = $this->themeSettingsManager->getOptions();

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
   * Check if the current page is a node-view route.
   *
   * This is not normally a complex task, but things are tricky with this theme
   * settings interface. Because the form is loaded via AJAX, the current route
   * match returns the route of the form, not the current page. We need to look
   * at the request history to see if the referring page was a node-view route.
   *
   * @return bool
   *   TRUE if the current page is a node view route, FALSE otherwise.
   */
  protected function isCurrentPageNodeView() {
    // Find the URL of the page that loaded this form.
    $referer = $this->requestStack->getCurrentRequest()->server->get('HTTP_REFERER');
    $request = Request::create($referer);
    $url = $this->pathValidator->getUrlIfValid($request->getRequestUri());

    // Exit early if a matching referer url object can not be found.
    if (!$url) {
      return FALSE;
    }

    // Check if the referer path is a node-view route.
    $routeName = $url->getRouteName();
    if (preg_match('/^entity\.node\.canonical/', $routeName)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('path.validator'),
      $container->get('request_stack'),
      $container->get('current_route_match'),
      $container->get('ys_themes.theme_settings_manager'),
    );
  }

  /**
   * Constructs the object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Path\PathValidatorInterface $path_validator
   *   The path validator service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack service.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match service.
   * @param \Drupal\ys_themes\ThemeSettingsManager $theme_settings_manager
   *   The Theme Settings Manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    PathValidatorInterface $path_validator,
    RequestStack $request_stack,
    RouteMatchInterface $route_match,
    ThemeSettingsManager $theme_settings_manager
  ) {
    parent::__construct($config_factory);
    $this->pathValidator = $path_validator;
    $this->requestStack = $request_stack;
    $this->routeMatch = $route_match;
    $this->themeSettingsManager = $theme_settings_manager;
  }

}
