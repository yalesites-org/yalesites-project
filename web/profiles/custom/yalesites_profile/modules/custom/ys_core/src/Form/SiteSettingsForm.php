<?php

namespace Drupal\ys_core\Form;

use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\Core\Session\AccountProxy;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\ys_media\YaleSitesMediaManager;
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
   * @var \Drupal\ys_media\YaleSitesMediaManager
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
   * The cache discovery service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheDiscovery;

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
   * @param \Drupal\ys_media\YaleSitesMediaManager $ys_media_manager
   *   The media manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxy $account_interface
   *   The current user.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_discovery
   *   The cache discovery service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    AliasManagerInterface $alias_manager,
    PathValidatorInterface $path_validator,
    RequestContext $request_context,
    YaleSitesMediaManager $ys_media_manager,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxy $account_interface,
    CacheBackendInterface $cache_discovery,
  ) {
    parent::__construct($config_factory);
    $this->aliasManager = $alias_manager;
    $this->pathValidator = $path_validator;
    $this->requestContext = $request_context;
    $this->ysMediaManager = $ys_media_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $account_interface;
    $this->cacheDiscovery = $cache_discovery;
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
      $container->get('ys_media.media_manager'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('cache.discovery'),
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

    // Group the settings by the task a user came here to do, rather than
    // leaving them in one flat run. Each setting is nested inside its details
    // group, the same way ViewsSettingsForm already does it: '#tree' is never
    // set, so nesting rearranges only the render tree and every value still
    // arrives flat in the form state. submitForm() therefore keeps reading
    // 'site_name' and friends exactly as before.
    //
    // Nesting rather than tagging each setting with '#group' is deliberate.
    // '#group' is only honoured by element types that wire preRenderGroup —
    // textfield, textarea, checkbox, container, details, fieldset — so radios
    // (font pairing), managed_file (favicon), media_library (teaser fallback)
    // and item (the Tag Manager link) would silently render outside the tabs.
    $form['vertical_tabs'] = [
      '#type' => 'vertical_tabs',
    ];

    $form['site_basics'] = [
      '#type' => 'details',
      '#title' => $this->t('Site basics'),
      '#description' => $this->t('What this site is called and who automated email comes from.'),
      '#group' => 'vertical_tabs',
    ];

    $form['site_basics']['site_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site name'),
      '#default_value' => $siteConfig->get('name'),
      '#required' => TRUE,
    ];

    $form['site_basics']['site_mail'] = [
      '#type' => 'textfield',
      '#description' => $this->t("The From address in automated emails sent during registration and new password requests, and other notifications. (Use an address ending in your site's domain to help prevent this email being flagged as spam.)"),
      '#title' => $this->t('Site email'),
      '#default_value' => $siteConfig->get('mail'),
      '#required' => TRUE,
    ];

    $form['key_pages'] = [
      '#type' => 'details',
      '#title' => $this->t('Key pages'),
      '#description' => $this->t('Which page the site should use for each of these purposes.'),
      '#group' => 'vertical_tabs',
    ];

    $form['key_pages']['site_page_front'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Homepage'),
      '#description' => $this->t("Start typing to find the page you want visitors to land on first, then pick it from the list."),
      '#default_value' => $this->pathToNode($siteConfig->get('page')['front']),
      '#required' => TRUE,
      '#target_type' => 'node',
    ];

    $form['key_pages']['site_page_posts'] = [
      '#type' => 'textfield',
      '#description' => $this->t("Specify a relative URL to display as the post landing page. This can be set to an existing page URL or use the default value '/post'."),
      '#title' => $this->t('Post landing page'),
      '#default_value' => $yaleConfig->get('page')['posts'],
      '#required' => FALSE,
    ];

    $form['key_pages']['site_page_events'] = [
      '#type' => 'textfield',
      '#description' => $this->t("Specify a relative URL to display as the events calendar page. This can be set to an existing page URL or use the default value '/events'."),
      '#title' => $this->t('Events calendar page'),
      '#default_value' => $yaleConfig->get('page')['events'],
      '#required' => FALSE,
    ];

    $form['key_pages']['site_page_403'] = [
      '#type' => 'textfield',
      '#description' => $this->t('This page is displayed when the requested document is denied to the current user. Leave blank to display a generic "access denied" page.'),
      '#title' => $this->t('Access denied page (403)'),
      '#default_value' => $siteConfig->get('page')['403'],
    ];

    $form['key_pages']['site_page_404'] = [
      '#type' => 'textfield',
      '#description' => $this->t('This page is displayed when no other content matches the requested document. Leave blank to display a generic "page not found" page.'),
      '#title' => $this->t('Page not found (404)'),
      '#default_value' => $siteConfig->get('page')['404'],
    ];

    $form['look_and_feel'] = [
      '#type' => 'details',
      '#title' => $this->t('Look and feel'),
      '#description' => $this->t('Settings that change what visitors see.'),
      '#group' => 'vertical_tabs',
    ];

    $form['look_and_feel']['font_pairing'] = [
      '#type' => 'radios',
      '#options' => [
        'yalenew' => $this->t('Yale New (Old-Style Numerals) / Mallory (YaleNew with old-style numerals for headings and other numeric text; Mallory for paragraph text)'),
        'mallory' => $this->t('Mallory / Mallory (Mallory for headings, Mallory for paragraph text)'),
        'yalenew-oldstyle' => $this->t('Yale New / Mallory (YaleNew with lining numerals for headings and other numeric text; Mallory for paragraph text)'),
      ],
      '#description' => $this->t('This font pairing controls how numbers appear in headings and other numeric text across the site.'),
      '#title' => $this->t('Font Pairing'),
      '#default_value' => $yaleConfig->get('font_pairing') ?? 'yalenew',
      '#prefix' => '<div class="font-pairing-selector">',
      '#suffix' => '</div>',
    ];

    // The preview stays directly under the font pairing radios: font-preview.js
    // toggles the active preview on change and the two read as one control.
    //
    // Only the sample digits are hidden from assistive technology, not the
    // whole preview. They are marked up as h2 purely for size, so they would
    // otherwise be the only headings in this tab's outline and read as
    // "1234567890" with nothing to convey. The paragraphs beside them do carry
    // real information the radio labels omit (which digits descend below the
    // baseline), so those stay exposed.
    $form['look_and_feel']['font_preview'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['font-preview-container'],
      ],
      '#attached' => [
        'library' => ['ys_core/font_preview'],
      ],
      'yalenew' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['font-preview', 'font-preview-yalenew'],
          'data-font-pairing' => 'yalenew',
        ],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('1234567890'),
          '#attributes' => ['class' => ['preview-heading'], 'aria-hidden' => 'true'],
        ],
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Old-Style Numerals — some digits (3, 4, 5, 7, 9) descend below the text baseline, similar to lowercase letters.'),
          '#attributes' => ['class' => ['preview-text']],
        ],
      ],
      'mallory' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['font-preview', 'font-preview-mallory'],
          'data-font-pairing' => 'mallory',
        ],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('Mallory Heading Sample'),
          '#attributes' => ['class' => ['preview-heading'], 'aria-hidden' => 'true'],
        ],
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('This is a sample paragraph in Mallory.'),
          '#attributes' => ['class' => ['preview-text']],
        ],
      ],
      'yalenew-oldstyle' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['font-preview', 'font-preview-yalenew-oldstyle'],
          'data-font-pairing' => 'yalenew-oldstyle',
        ],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('1234567890'),
          '#attributes' => ['class' => ['preview-heading'], 'aria-hidden' => 'true'],
        ],
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Lining Numerals — all digits align uniformly to the text baseline, similar to capital letters.'),
          '#attributes' => ['class' => ['preview-text']],
        ],
      ],
    ];

    $form['look_and_feel']['favicon'] = [
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

    // "Teaser" is jargon, but it is used consistently across the platform —
    // including three node field descriptions that point back at this setting —
    // so the label stays and the description does the explaining instead.
    $form['look_and_feel']['teaser_image_fallback'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['image'],
      '#title' => $this->t('Fallback teaser image'),
      '#required' => FALSE,
      '#default_value' => ($yaleConfig->get('image_fallback')) ? $yaleConfig->get('image_fallback')['teaser'] : NULL,
      '#description' => $this->t('Used on event and post cards whenever that piece of content has no teaser image of its own, so a card never appears blank.'),
    ];

    $form['search_and_analytics'] = [
      '#type' => 'details',
      '#title' => $this->t('Search and analytics'),
      '#description' => $this->t('How search engines verify the site and how visits are measured.'),
      '#group' => 'vertical_tabs',
    ];

    $form['search_and_analytics']['google_site_verification'] = [
      '#type' => 'textfield',
      '#description' => $this->t('Get a verification key from Google Search Console Tools using the "URL Prefix" tool, clicking on the the alternate methods tab, and selecting the HTML Tag option. Use the "content" attribute from the Google tag within this field. Example: <code>&#60;meta name="google-site-verification" content="USE-THIS-CODE" /></code>'),
      '#title' => $this->t('Google Site Verification'),
      '#default_value' => $yaleConfig->get('seo')['google_site_verification'],
    ];

    $form['search_and_analytics']['google_analytics_migration'] = [
      '#type' => 'item',
      '#title' => $this->t('Google Analytics/Tag Manager'),
      '#description' => $this->t('YaleSites is transitioning from Google Analytics to Google Tag Manager. Configure Google Tag Manager below to maintain your website analytics tracking.'),
      '#markup' => Link::fromTextAndUrl(
        $this->t('Configure Google Tag Manager'),
        Url::fromRoute('entity.google_tag_container.single_form')
          ->setOptions(['attributes' => ['class' => ['button'], 'style' => 'margin-top: 0; margin-bottom: 0;']])
      )->toString(),
    ];

    $form['content_and_tagging'] = [
      '#type' => 'details',
      '#title' => $this->t('Content and tagging'),
      '#description' => $this->t('Sitewide defaults for how content is organized and labeled.'),
      '#group' => 'vertical_tabs',
    ];

    $form['content_and_tagging']['custom_vocab_name'] = [
      '#type' => 'textfield',
      '#description' => $this->t('This field will update the name of the custom vocabulary for the site. By default, the name is "Custom Vocab".'),
      '#title' => $this->t('Custom Vocabulary Name'),
      '#default_value' => $yaleConfig->get('taxonomy')['custom_vocab_name'] ?? 'Custom Vocab',
    ];

    // CAS is user 1 only - moving it to the Platform Admin Settings page
    // would widen it to every platform admin
    // (yalesites-org/YaleSites-Internal#1560) - and with the environment
    // indicator moved there it is the only field left here. So the group
    // takes the user 1 gate rather than the broader platform admin one: an
    // Advanced tab a platform admin can open but never see a field inside is
    // worse than no tab at all (#1590).
    $is_user_1 = ($this->currentUser->id() == 1);

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced'),
      '#description' => $this->t('Platform-level settings. Most sites never need to change these.'),
      '#group' => 'vertical_tabs',
      '#access' => $is_user_1,
    ];

    $form['advanced']['cas_app_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CAS Application Name'),
      '#description' => $this->t('The name of the application to be used in CAS login.'),
      '#default_value' => ($yaleConfig->get('cas_app_name')) ? $yaleConfig->get('cas_app_name') : 'yalesites',
      '#access' => $is_user_1,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validate front, post, and event page paths.
    $this->validateIsNode($form_state, 'site_page_front');

    // These four fields share the same three path messages, so each message has
    // to name the field it came from. That mattered less when every setting was
    // visible in one list; now the offending field can be in a tab the user is
    // not looking at, "The path has to start with a slash" alone does not say
    // which of the four is wrong.
    if (!$form_state->isValueEmpty('site_page_posts')) {
      $label = $this->keyPageLabel($form, 'site_page_posts');
      $this->validateStartWithSlash($form_state, 'site_page_posts', $label);
      $this->validateIsNotRootPath($form_state, 'site_page_posts', $label);
      $this->validatePath($form_state, 'site_page_posts', $label);
    }

    if (!$form_state->isValueEmpty('site_page_events')) {
      $label = $this->keyPageLabel($form, 'site_page_events');
      $this->validateStartWithSlash($form_state, 'site_page_events', $label);
      $this->validateIsNotRootPath($form_state, 'site_page_events', $label);
      $this->validatePath($form_state, 'site_page_events', $label);
    }

    // Get the normal paths of error pages.
    if (!$form_state->isValueEmpty('site_page_403')) {
      $label = $this->keyPageLabel($form, 'site_page_403');
      $form_state->setValueForElement($form['key_pages']['site_page_403'], $this->aliasManager->getPathByAlias($form_state->getValue('site_page_403')));
      $this->validateStartWithSlash($form_state, 'site_page_403', $label);
      $this->validatePath($form_state, 'site_page_403', $label);
    }
    if (!$form_state->isValueEmpty('site_page_404')) {
      $label = $this->keyPageLabel($form, 'site_page_404');
      $form_state->setValueForElement($form['key_pages']['site_page_404'], $this->aliasManager->getPathByAlias($form_state->getValue('site_page_404')));
      $this->validateStartWithSlash($form_state, 'site_page_404', $label);
      $this->validatePath($form_state, 'site_page_404', $label);
    }

    // Email validations.
    $this->validateEmail($form_state, 'site_mail');

    parent::validateForm($form, $form_state);

    // Reveal the group holding anything that just failed, so the user is never
    // told there is a problem with no field in sight. Runs last so it also sees
    // errors the parent added.
    $this->openGroupsWithErrors($form, $form_state);
  }

  /**
   * Returns the visible label of a Key pages field, for error messages.
   *
   * @param array $form
   *   The form array.
   * @param string $fieldId
   *   The id of a field in the Key pages group.
   *
   * @return string
   *   The field's title, or its id if it somehow has none.
   */
  protected function keyPageLabel(array $form, string $fieldId): string {
    return (string) ($form['key_pages'][$fieldId]['#title'] ?? $fieldId);
  }

  /**
   * Opens each tab group holding a field that failed validation.
   *
   * A user must never be told there is a problem with no field in sight. Core's
   * vertical-tabs.js does open the tab containing a '.error' field, but only
   * once JS has attached, so doing it server-side as well means the reveal
   * holds with JS off and there is no flash of the wrong tab before it does.
   *
   * @param array $form
   *   The form array, by reference so the owning group can be opened.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state holding the errors collected so far.
   */
  protected function openGroupsWithErrors(array &$form, FormStateInterface $form_state) {
    foreach (array_keys($form_state->getErrors()) as $name) {
      // Error keys are '][' separated; the first segment is the element key.
      $element = explode('][', $name)[0];
      foreach ($form as $key => $group) {
        if (is_array($group) && ($group['#type'] ?? NULL) === 'details' && isset($group[$element])) {
          $form[$key]['#open'] = TRUE;
        }
      }
    }
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
    $yaleSiteConfig = $this->configFactory->getEditable('ys_core.site')
      ->set('page.posts', $form_state->getValue('site_page_posts'))
      ->set('page.events', $form_state->getValue('site_page_events'))
      ->set('seo.google_site_verification', $form_state->getValue('google_site_verification'))
      ->set('taxonomy.custom_vocab_name', $form_state->getValue('custom_vocab_name') ?: 'Custom Vocab')
      ->set('image_fallback.teaser', $form_state->getValue('teaser_image_fallback'))
      ->set('custom_favicon', $form_state->getValue('favicon'))
      ->set('font_pairing', $form_state->getValue('font_pairing'))
      ->set('cas_app_name', $form_state->getValue('cas_app_name') ?? 'yalesites');

    $yaleSiteConfig->save();

    $submitted_vocab_name = $form_state->getValue('custom_vocab_name') ?: 'Custom Vocab';
    $custom_vocab_name = $this->configFactory->getEditable('taxonomy.vocabulary.custom_vocab')->get('name');
    if ($custom_vocab_name !== $submitted_vocab_name) {
      // Update the custom vocab vocabulary name.
      $this->configFactory->getEditable('taxonomy.vocabulary.custom_vocab')
        ->set('name', $submitted_vocab_name)
        ->save();

      $content_types = ['event', 'page', 'post', 'profile', 'resource'];
      // Update the custom vocab field label for each content type.
      foreach ($content_types as $type) {
        $this->configFactory->getEditable("field.field.node.{$type}.field_custom_vocab")
          ->set('label', $submitted_vocab_name)
          ->save();
      }
      // Clear cache so the new label is reflected in the node form.
      $this->cacheDiscovery->invalidateAll();
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Check that a submitted value starts with a slash.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the parent form.
   * @param string $fieldId
   *   The id of a field on the connfig form.
   * @param string $label
   *   The field's visible label, named in the message so the user can tell
   *   which of the four path fields is being complained about.
   */
  protected function validateStartWithSlash(FormStateInterface &$form_state, string $fieldId, string $label) {
    if (($value = $form_state->getValue($fieldId)) && $value[0] !== '/') {
      $form_state->setErrorByName(
        $fieldId,
        $this->t(
          "@label: the path '%path' has to start with a slash.",
         ['@label' => $label, '%path' => $form_state->getValue($fieldId)]
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
   * @param string $label
   *   The field's visible label, named in the message.
   */
  protected function validateIsNotRootPath(FormStateInterface &$form_state, string $fieldId, string $label) {
    if (($value = $form_state->getValue($fieldId)) && $value == '/') {
      $form_state->setErrorByName(
        $fieldId,
        $this->t(
          "@label: the path '%path' can not be the site root.",
         ['@label' => $label, '%path' => $form_state->getValue($fieldId)]
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
   * @param string $label
   *   The field's visible label, named in the message.
   */
  protected function validatePath(FormStateInterface &$form_state, string $fieldId, string $label) {
    if (!$this->pathValidator->isValid($form_state->getValue($fieldId))) {
      $form_state->setErrorByName(
        $fieldId,
        $this->t(
          "@label: either the path '%path' is invalid or you do not have access to it.",
          ['@label' => $label, '%path' => $form_state->getValue($fieldId)]
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
      if (strpos($value, 'yale.edu') === FALSE) {
        $form_state->setErrorByName(
          $fieldId, $this->t('Email domain has to be yale.edu.')
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
      $node_id = $this->getIdFromNodePath($pathOrNode);
      $node = $this->entityTypeManager->getStorage('node')->load($node_id);

      if ($node === NULL) {
        // Attempt to get the node by the alias if it exists.
        $alias = $this->aliasManager->getPathByAlias($pathOrNode);
        $node_id = $this->getIdFromNodePath($alias);
        $node = $this->entityTypeManager->getStorage('node')->load($node_id);
      }
      return $node;
    }

    return $pathOrNode;
  }

  /**
   * Get the ID from a node path.
   *
   * @param string $nodePath
   *   A node path.
   *
   * @return string
   *   The node id.
   */
  private function getIdFromNodePath($nodePath) {
    if ($nodePath && is_string($nodePath)) {
      $parts = explode('/', trim($nodePath, '/'));
      return end($parts);
    }

    return NULL;
  }

}
