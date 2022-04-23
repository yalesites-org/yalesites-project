<?php

namespace Drupal\ys_admin\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure example settings for this site.
 */
class Settings extends ConfigFormBase {

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
    return ['system.site'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('system.site');
    $form['site_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site name'),
      '#default_value' => $config->get('name'),
      '#required' => TRUE,
    ];

    $form['site_mail'] = [
      '#type' => 'textfield',
      '#description' => $this->t("The From address in automated emails sent during registration and new password requests, and other notifications. (Use an address ending in your site's domain to help prevent this email being flagged as spam.)"),
      '#title' => $this->t('Site email'),
      '#default_value' => $config->get('mail'),
      '#required' => TRUE,
    ];

    $form['site_page_front'] = [
      '#type' => 'textfield',
      '#description' => $this->t("Specify a relative URL to display as the front page."),
      '#title' => $this->t('Front page'),
      '#default_value' => $config->get('page')['front'],
      '#required' => TRUE,
    ];

    $form['site_page_403'] = [
      '#type' => 'textfield',
      '#description' => $this->t('This page is displayed when the requested document is denied to the current user. Leave blank to display a generic "access denied" page.'),
      '#title' => $this->t('403 page'),
      '#default_value' => $config->get('page')['403'],
    ];

    $form['site_page_404'] = [
      '#type' => 'textfield',
      '#description' => $this->t('This page is displayed when no other content matches the requested document. Leave blank to display a generic "page not found" page.'),
      '#title' => $this->t('404 page'),
      '#default_value' => $config->get('page')['404'],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validate front page path.
    if (($value = $form_state->getValue('site_page_front')) && $value[0] !== '/') {
      $form_state->setErrorByName('site_page_front', $this->t("The path '%path' has to start with a slash.", ['%path' => $form_state->getValue('site_page_front')]));
    }
    if (!$this->pathValidator->isValid($form_state->getValue('site_page_front'))) {
      $form_state->setErrorByName('site_page_front', $this->t("Either the path '%path' is invalid or you do not have access to it.", ['%path' => $form_state->getValue('site_page_front')]));
    }
    // Get the normal paths of both error pages.
    if (!$form_state->isValueEmpty('site_page_403')) {
      $form_state->setValueForElement($form['site_page_403'], $this->aliasManager->getPathByAlias($form_state->getValue('site_page_403')));
    }
    if (!$form_state->isValueEmpty('site_page_404')) {
      $form_state->setValueForElement($form['site_page_404'], $this->aliasManager->getPathByAlias($form_state->getValue('site_page_404')));
    }
    if (($value = $form_state->getValue('site_page_403')) && $value[0] !== '/') {
      $form_state->setErrorByName('site_page_403', $this->t("The path '%path' has to start with a slash.", ['%path' => $form_state->getValue('site_page_403')]));
    }
    if (($value = $form_state->getValue('site_page_404')) && $value[0] !== '/') {
      $form_state->setErrorByName('site_page_404', $this->t("The path '%path' has to start with a slash.", ['%path' => $form_state->getValue('site_page_404')]));
    }
    // Validate 403 error path.
    if (!$form_state->isValueEmpty('site_page_403') && !$this->pathValidator->isValid($form_state->getValue('site_page_403'))) {
      $form_state->setErrorByName('site_page_403', $this->t("Either the path '%path' is invalid or you do not have access to it.", ['%path' => $form_state->getValue('site_page_403')]));
    }
    // Validate 404 error path.
    if (!$form_state->isValueEmpty('site_page_404') && !$this->pathValidator->isValid($form_state->getValue('site_page_404'))) {
      $form_state->setErrorByName('site_page_404', $this->t("Either the path '%path' is invalid or you do not have access to it.", ['%path' => $form_state->getValue('site_page_404')]));
    }
    // Email validations.
    if (($value = $form_state->getValue('site_mail'))) {
      // Validate email format.
      if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
        $form_state->setErrorByName('site_mail', $this->t('Email format for "%email" is not valid. Expected format is "user@yale.edu".', ['%email' => $form_state->getValue('site_mail')]));
      }
      // Validate yale email.
      if (strpos($value, '@yale.edu') === FALSE) {
        $form_state->setErrorByName('site_mail', $this->t('Email domain has to be @yale.edu.'));
      }
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Retrieve the configuration.
    $this->configFactory->getEditable('system.site')
      // Set the submitted configuration setting.
      ->set('name', $form_state->getValue('site_name'))
      ->set('mail', $form_state->getValue('site_mail'))
      ->set('page.front', $form_state->getValue('site_page_front'))
      ->set('page.403', $form_state->getValue('site_page_403'))
      ->set('page.404', $form_state->getValue('site_page_404'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
