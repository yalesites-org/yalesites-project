<?php

namespace Drupal\ys_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing sitewide settings.
 *
 * This form recreates some of the logic from the Drupal Site Information form
 * and may need to be updated if the core form changes. See:
 * \Drupal\system\Form\SiteInformationForm.
 *
 * @package Drupal\ys_core\Form
 */
class SiteSettingsForm extends ConfigFormBase {

  const DEFAULT_NEWS_PATH = '/news';
  const DEFAULT_EVENTS_PATH = '/events';

  /**
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * The path validator.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected $pathValidator;

  /**
   * The request context.
   *
   * @var \Drupal\Core\Routing\RequestContext
   */
  protected $requestContext;

  /**
   * Constructs a SiteInformationForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\path_alias\AliasManagerInterface $alias_manager
   *   The path alias manager.
   * @param \Drupal\Core\Path\PathValidatorInterface $path_validator
   *   The path validator.
   * @param \Drupal\Core\Routing\RequestContext $request_context
   *   The request context.
   */
  public function __construct(ConfigFactoryInterface $config_factory, AliasManagerInterface $alias_manager, PathValidatorInterface $path_validator, RequestContext $request_context) {
    parent::__construct($config_factory);
    $this->aliasManager = $alias_manager;
    $this->pathValidator = $path_validator;
    $this->requestContext = $request_context;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('path_alias.manager'),
      $container->get('path.validator'),
      $container->get('router.request_context')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['system.site', 'ys_core.site'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $siteConfig = $this->config('system.site');
    $yaleConfig = $this->config('ys_core.site');

    $form['site_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site name'),
      '#default_value' => $siteConfig->get('name'),
      '#required' => TRUE,
    ];

    $form['site_mail'] = [
      '#type' => 'textfield',
      '#description' => $this->t("The From address in automated emails sent during registration and new password requests, and other notifications. (Use an address ending in your site's domain to help prevent this email being flagged as spam.)"),
      '#title' => $this->t('Site email'),
      '#default_value' => $siteConfig->get('mail'),
      '#required' => TRUE,
    ];

    $form['site_page_front'] = [
      '#type' => 'textfield',
      '#description' => $this->t("Specify a relative URL to display as the front page."),
      '#title' => $this->t('Front page'),
      '#default_value' => $siteConfig->get('page')['front'],
      '#required' => TRUE,
    ];

    $form['site_page_news'] = [
      '#type' => 'textfield',
      '#description' => $this->t("Specify a relative URL to display as the news landing page. This can be set to an existing page URL or use the default value '/news'."),
      '#title' => $this->t('News landing page'),
      '#default_value' => $yaleConfig->get('page')['news'],
      '#required' => FALSE,
    ];

    $form['site_page_events'] = [
      '#type' => 'textfield',
      '#description' => $this->t("Specify a relative URL to display as the events calendar page. This can be set to an existing page URL or use the default value '/events'."),
      '#title' => $this->t('Events calendar page'),
      '#default_value' => $yaleConfig->get('page')['events'],
      '#required' => FALSE,
    ];

    $form['site_page_403'] = [
      '#type' => 'textfield',
      '#description' => $this->t('This page is displayed when the requested document is denied to the current user. Leave blank to display a generic "access denied" page.'),
      '#title' => $this->t('403 page'),
      '#default_value' => $siteConfig->get('page')['403'],
    ];

    $form['site_page_404'] = [
      '#type' => 'textfield',
      '#description' => $this->t('This page is displayed when no other content matches the requested document. Leave blank to display a generic "page not found" page.'),
      '#title' => $this->t('404 page'),
      '#default_value' => $siteConfig->get('page')['404'],
    ];

    $form['enable_search_form'] = [
      '#type' => 'checkbox',
      '#description' => $this->t('Enable the search form located in the utility navigation area.'),
      '#title' => $this->t('Enable search form'),
      '#default_value' => $yaleConfig->get('search')['enable_search_form'],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validate front, news, and event page paths.
    $this->validateStartWithSlash($form_state, 'site_page_front');
    $this->validatePath($form_state, 'site_page_front');
    $this->validateStartWithSlash($form_state, 'site_page_news');
    $this->validateStartWithSlash($form_state, 'site_page_events');
    $this->validateIsNotRootPath($form_state, 'site_page_news');
    $this->validateIsNotRootPath($form_state, 'site_page_events');
    if ($form_state->getValue('site_page_news') !== self::DEFAULT_NEWS_PATH) {
      $this->validatePath($form_state, 'site_page_news');
    }
    if ($form_state->getValue('site_page_events') !== self::DEFAULT_EVENTS_PATH) {
      $this->validatePath($form_state, 'site_page_events');
    }

    // Get the normal paths of error pages.
    if (!$form_state->isValueEmpty('site_page_403')) {
      $form_state->setValueForElement($form['site_page_403'], $this->aliasManager->getPathByAlias($form_state->getValue('site_page_403')));
    }
    if (!$form_state->isValueEmpty('site_page_404')) {
      $form_state->setValueForElement($form['site_page_404'], $this->aliasManager->getPathByAlias($form_state->getValue('site_page_404')));
    }

    // Validate error page paths.
    $this->validateStartWithSlash($form_state, 'site_page_404');
    $this->validateStartWithSlash($form_state, 'site_page_403');
    $this->validatePath($form_state, 'site_page_404');
    $this->validatePath($form_state, 'site_page_403');

    // Email validations.
    $this->validateEmail($form_state, 'site_mail');

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('system.site')
      ->set('name', $form_state->getValue('site_name'))
      ->set('mail', $form_state->getValue('site_mail'))
      ->set('page.front', $form_state->getValue('site_page_front'))
      ->set('page.403', $form_state->getValue('site_page_403'))
      ->set('page.404', $form_state->getValue('site_page_404'))
      ->save();
    $this->configFactory->getEditable('ys_core.site')
      ->set('page.news', $form_state->getValue('site_page_news'))
      ->set('page.events', $form_state->getValue('site_page_events'))
      ->set('search.enable_search_form', $form_state->getValue('enable_search_form'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Check that a submitted value starts with a slash.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the parent form.
   * @param string $fieldId
   *   The id of a field on the connfig form.
   */
  protected function validateStartWithSlash(FormStateInterface &$form_state, string $fieldId) {
    if (($value = $form_state->getValue($fieldId)) && $value[0] !== '/') {
      $form_state->setErrorByName(
        $fieldId,
        $this->t(
          "The path '%path' has to start with a slash.",
         ['%path' => $form_state->getValue($fieldId)]
        )
      );
    }
  }

  /**
   * Check that a submitted value is not the root path.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state passed by reference.
   * @param string $fieldId
   *   The id of a field on the connfig form.
   */
  protected function validateIsNotRootPath(FormStateInterface &$form_state, string $fieldId) {
    if (($value = $form_state->getValue($fieldId)) && $value == '/') {
      $form_state->setErrorByName(
        $fieldId,
        $this->t(
          "The path '%path' can not be the site root.",
         ['%path' => $form_state->getValue($fieldId)]
        )
      );
    }
  }

  /**
   * Check that a submitted value represents a valid Drupal path.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state passed by reference.
   * @param string $fieldId
   *   The id of a field on the connfig form.
   */
  protected function validatePath(FormStateInterface &$form_state, string $fieldId) {
    if (!$this->pathValidator->isValid($form_state->getValue($fieldId))) {
      $form_state->setErrorByName(
        $fieldId,
        $this->t(
          "Either the path '%path' is invalid or you do not have access to it.",
          ['%path' => $form_state->getValue($fieldId)]
        )
      );
    }
  }

  /**
   * Check that a submitted value matches the format of a valid Yale email.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state passed by reference.
   * @param string $fieldId
   *   The id of a field on the connfig form.
   */
  protected function validateEmail(FormStateInterface &$form_state, string $fieldId) {
    if (($value = $form_state->getValue($fieldId))) {
      if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
        $form_state->setErrorByName(
          $fieldId,
          $this->t(
            'Email format for "%email" is not valid. Expected format is "user@yale.edu".',
            ['%email' => $form_state->getValue('site_mail')]
          )
        );
      }
      if (strpos($value, '@yale.edu') === FALSE) {
        $form_state->setErrorByName(
          $fieldId, $this->t('Email domain has to be @yale.edu.')
        );
      }
    }
  }

}
