<?php

namespace Drupal\ys_core\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\Core\Session\AccountProxy;
use Drupal\google_analytics\Constants\GoogleAnalyticsPatterns;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\ys_core\YaleSitesMediaManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing site-wide settings.
 *
 * This form recreates some of the logic from the Drupal Site Information form
 * and may need to be updated if the core form changes. See:
 * \Drupal\system\Form\SiteInformationForm.
 *
 * @package Drupal\ys_core\Form
 */
class SiteSettingsForm extends ConfigFormBase implements ContainerInjectionInterface {

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
   * The ys media manager.
   *
   * @var \Drupal\ys_core\YaleSitesMediaManager
   */
  protected $ysMediaManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxy
   */
  protected $currentUser;

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
   * @param \Drupal\ys_core\YaleSitesMediaManager $ys_media_manager
   *   The media manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxy $account_interface
   *   The current user.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    AliasManagerInterface $alias_manager,
    PathValidatorInterface $path_validator,
    RequestContext $request_context,
    YaleSitesMediaManager $ys_media_manager,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxy $account_interface,
    ) {
    parent::__construct($config_factory);
    $this->aliasManager = $alias_manager;
    $this->pathValidator = $path_validator;
    $this->requestContext = $request_context;
    $this->ysMediaManager = $ys_media_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $account_interface;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('path_alias.manager'),
      $container->get('path.validator'),
      $container->get('router.request_context'),
      $container->get('ys_core.media_manager'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
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
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Front page'),
      '#description' => $this->t("Specify a relative URL to display as the front page. Typically this points to a page in Drupal and is referenced by a node id. Use this autocomplete field to select the correct node."),
      '#default_value' => $this->pathToNode($siteConfig->get('page')['front']),
      '#required' => TRUE,
      '#target_type' => 'node',
    ];

    $form['site_page_posts'] = [
      '#type' => 'textfield',
      '#description' => $this->t("Specify a relative URL to display as the post landing page. This can be set to an existing page URL or use the default value '/post'."),
      '#title' => $this->t('Post landing page'),
      '#default_value' => $yaleConfig->get('page')['posts'],
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

    $form['google_site_verification'] = [
      '#type' => 'textfield',
      '#description' => $this->t('Get a verification key from Google Search Console Tools using the "URL Prefix" tool, clicking on the the alternate methods tab, and selecting the HTML Tag option. Use the "content" attribute from the Google tag within this field. Example: <code>&#60;meta name="google-site-verification" content="USE-THIS-CODE" /></code>'),
      '#title' => $this->t('Google Site Verification'),
      '#default_value' => $yaleConfig->get('seo')['google_site_verification'],
    ];

    $form['google_analytics_id'] = [
      '#type' => 'textfield',
      '#description' => $this->t('This ID has the form of <code>UA-xxxxx-yy</code>, <code>G-xxxxxxxx</code>, <code>AW-xxxxxxxxx</code>, or <code>DC-xxxxxxxx</code>. To get a Web Property ID, register your site with Google Analytics, or if you already have registered your site, go to your Google Analytics Settings page to see the ID next to every site profile.'),
      '#title' => $this->t('Google Analytics Web Property ID'),
      '#default_value' => $yaleConfig->get('seo')['google_analytics_id'],
    ];

    $form['teaser_image_fallback'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['image'],
      '#title' => $this->t('Fallback teaser image'),
      '#required' => FALSE,
      '#default_value' => ($yaleConfig->get('image_fallback')) ? $yaleConfig->get('image_fallback')['teaser'] : NULL,
      '#description' => $this->t('This image will be used for event and post card displays when no teaser image is selected.'),
    ];

    $form['favicon'] = [
      '#type' => 'managed_file',
      '#upload_location' => 'public://favicons',
      '#multiple' => FALSE,
      '#description' => $this->t('Allowed extensions: gif png jpg jpeg<br>Image must be at least 180x180'),
      '#upload_validators' => [
        'file_validate_is_image' => [],
        'file_validate_extensions' => ['gif png jpg jpeg'],
        'file_validate_image_resolution' => [0, "180x180"],
      ],
      '#title' => $this->t('Custom Favicon'),
      '#default_value' => ($yaleConfig->get('custom_favicon')) ? $yaleConfig->get('custom_favicon') : NULL,
      '#theme' => 'image_widget',
      '#preview_image_style' => 'favicon_16x16',
      '#use_preview' => TRUE,
      '#use_favicon_preview' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validate front, post, and event page paths.
    /* $this->validateStartWithSlash($form_state, 'site_page_front'); */
    /* $this->validatePath($form_state, 'site_page_front'); */
    $this->validateIsNode($form_state, 'site_page_front');

    if (!$form_state->isValueEmpty('site_page_posts')) {
      $this->validateStartWithSlash($form_state, 'site_page_posts');
      $this->validateIsNotRootPath($form_state, 'site_page_posts');
      $this->validatePath($form_state, 'site_page_posts');
    }

    if (!$form_state->isValueEmpty('site_page_events')) {
      $this->validateStartWithSlash($form_state, 'site_page_events');
      $this->validateIsNotRootPath($form_state, 'site_page_events');
      $this->validatePath($form_state, 'site_page_events');
    }

    // Get the normal paths of error pages.
    if (!$form_state->isValueEmpty('site_page_403')) {
      $form_state->setValueForElement($form['site_page_403'], $this->aliasManager->getPathByAlias($form_state->getValue('site_page_403')));
      $this->validateStartWithSlash($form_state, 'site_page_403');
      $this->validatePath($form_state, 'site_page_403');
    }
    if (!$form_state->isValueEmpty('site_page_404')) {
      $form_state->setValueForElement($form['site_page_404'], $this->aliasManager->getPathByAlias($form_state->getValue('site_page_404')));
      $this->validateStartWithSlash($form_state, 'site_page_404');
      $this->validatePath($form_state, 'site_page_404');
    }

    // Email validations.
    $this->validateEmail($form_state, 'site_mail');

    // Ensure Google Analytics is a valid format.
    $this->validateGoogleAnalyticsId($form_state, 'google_analytics_id');

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Handle the favicon filesystem if needed.
    $this->ysMediaManager->handleMediaFilesystem(
      $form_state->getValue('favicon'),
      $this->configFactory->getEditable('ys_core.site')->get('custom_favicon')
    );

    $this->configFactory->getEditable('system.site')
      ->set('name', $form_state->getValue('site_name'))
      ->set('mail', $form_state->getValue('site_mail'))
      ->set('page.front', '/node/' . $form_state->getValue('site_page_front'))
      ->set('page.403', $form_state->getValue('site_page_403'))
      ->set('page.404', $form_state->getValue('site_page_404'))
      ->save();
    $this->configFactory->getEditable('ys_core.site')
      ->set('page.posts', $form_state->getValue('site_page_posts'))
      ->set('page.events', $form_state->getValue('site_page_events'))
      ->set('seo.google_site_verification', $form_state->getValue('google_site_verification'))
      ->set('seo.google_analytics_id', $form_state->getValue('google_analytics_id'))
      ->set('image_fallback.teaser', $form_state->getValue('teaser_image_fallback'))
      ->set('custom_favicon', $form_state->getValue('favicon'))
      ->save();
    $this->configFactory->getEditable('google_analytics.settings')
      ->set('account', $form_state->getValue('google_analytics_id'))
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
   *   The id of a field on the config form.
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

  /**
   * Check that a submitted GA value matches a valid Google Analytics format.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state passed by reference.
   * @param string $fieldId
   *   The id of a field on the cnnfig form.
   */
  protected function validateGoogleAnalyticsId(FormStateInterface &$form_state, string $fieldId) {
    // Exit early if the google_analytics module changed and no longer applies.
    if (!class_exists('Drupal\google_analytics\Constants\GoogleAnalyticsPatterns')) {
      return;
    }
    if (($value = $form_state->getValue($fieldId))) {
      if (!preg_match(GoogleAnalyticsPatterns::GOOGLE_ANALYTICS_GTAG_MATCH, $value)) {
        $form_state->setErrorByName(
          $fieldId,
          $this->t('A valid Google Analytics Web Property ID is case sensitive and formatted like UA-xxxxx-yy, G-xxxxxxxx, AW-xxxxxxxxx, or DC-xxxxxxxx.')
        );
      }
    }
  }

  /**
   * Check that a submitted value is a valid node that the user has access to.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state passed by reference.
   * @param string $fieldId
   *   The id of a field on the config form.
   */
  protected function validateIsNode(FormStateInterface &$form_state, string $fieldId) {
    $value = $form_state->getValue($fieldId);
    $node = NULL;
    $access = FALSE;

    $isNumeric = is_numeric($value);
    if ($isNumeric) {
      $node = $this->entityTypeManager->getStorage('node')->load($value);
    }

    if ($node) {
      $access = $node->access('view', $this->currentUser);
    }

    if (!$isNumeric || !$node || !$access) {
      $form_state->setErrorByName(
      $fieldId,
      $this->t(
        "The node '%node' is invalid or you do not have access to it.",
        ['%node' => $form_state->getValue($fieldId)]
      )
      );
    }

  }

  /**
   * Convert a path to a node entity.
   *
   * @param string|object $pathOrNode
   *   A path or node object.
   *
   * @return object
   *   A node object.
   */
  protected function pathToNode($pathOrNode) {
    if ($pathOrNode && is_string($pathOrNode)) {
      $parts = explode('/', trim($pathOrNode, '/'));
      $node_id = end($parts);
      $node = $this->entityTypeManager->getStorage('node')->load($node_id);
      return $node;
    }

    return $pathOrNode;
  }

}
