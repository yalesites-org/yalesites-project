<?php

namespace Drupal\ys_core\Form;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxy;
use Drupal\ys_core\YaleSitesMediaManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing header-related settings.
 *
 * @package Drupal\ys_core\Form
 */
class HeaderSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_core_header_settings_form';
  }

  /**
   * THe Drupal backend cache renderer service.
   *
   * @var \Drupal\Core\Path\CacheBackendInterface
   */
  protected $cacheRender;

  /**
   * Current user session.
   *
   * @var \Drupal\Core\Session\AccountProxy
   */
  protected $currentUserSession;

  /**
   * The ys media manager.
   *
   * @var \Drupal\ys_core\YaleSitesMediaManager
   */
  protected $ysMediaManager;

  /**
   * Constructs the object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Path\CacheBackendInterface $cache_render
   *   The Cache backend interface.
   * @param \Drupal\Core\Session\AccountProxy $current_user_session
   *   The current user session.
   * @param \Drupal\ys_core\YaleSitesMediaManager $ys_media_manager
   *   The media manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    CacheBackendInterface $cache_render,
    AccountProxy $current_user_session,
    YaleSitesMediaManager $ys_media_manager,
    ) {
    parent::__construct($config_factory);
    $this->cacheRender = $cache_render;
    $this->currentUserSession = $current_user_session;
    $this->ysMediaManager = $ys_media_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('cache.render'),
      $container->get('current_user'),
      $container->get('ys_core.media_manager'),
    );
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
    $headerConfig = $this->config('ys_core.header_settings');

    $form['#attached']['library'][] = 'ys_core/header_footer_settings';
    $form['#attributes']['class'][] = 'ys-core-header-footer-settings';

    $form['header_variation'] = [
      '#type' => 'radios',
      '#options' => [
        'basic' => $this->t('Basic Nav') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_core/images/preview-icons/header-basic.svg" class="preview-icon" alt="Basic header icon showing a site title and a simplified navigation.">',
        'mega' => $this->t('Mega Nav') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_core/images/preview-icons/header-mega.svg" class="preview-icon" alt="Mega header icon showing a site title and a flyout style mega menu.">',
        'focus' => $this->t('Focus Nav') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_core/images/preview-icons/header-focus.svg" class="preview-icon" alt="TKTKTK">',
      ],
      '#title' => $this->t('Header variation'),
      '#default_value' => ($headerConfig->get('header_variation')) ? $headerConfig->get('header_variation') : 'basic',
      '#attributes' => [
        'class' => [
          'variation-radios',
        ],
      ],
    ];

    $form['desc_basic_container'] = [
      '#type' => 'container',
      '#title' => $this->t('Basic'),
      '#states' => [
        'visible' => [
          ':input[name="header_variation"]' => ['value' => 'basic'],
        ],
      ],
    ];

    $form['desc_mega_container'] = [
      '#type' => 'container',
      '#title' => $this->t('Mega'),
      '#states' => [
        'visible' => [
          ':input[name="header_variation"]' => ['value' => 'mega'],
        ],
      ],
    ];

    $form['desc_focus_container'] = [
      '#type' => 'container',
      '#title' => $this->t('Focus'),
      '#states' => [
        'visible' => [
          ':input[name="header_variation"]' => ['value' => 'focus'],
        ],
      ],
    ];

    if ($this->allowSecretItems()) {
      $form['site_name_image_container'] = [
        '#type' => 'details',
        '#title' => $this->t('Site Name Image'),
      ];
    }

    $form['nav_position_container'] = [
      '#type' => 'details',
      '#title' => $this->t('Navigation Position'),
      '#states' => [
        'disabled' => [
          ':input[name="header_variation"]' => [
            'value' => 'focus',
          ],
        ],
      ],
    ];

    $form['site_search_container'] = [
      '#type' => 'details',
      '#title' => $this->t('Site Search'),
      '#states' => [
        'disabled' => [
          ':input[name="header_variation"]' => [
            'value' => 'focus',
          ],
        ],
      ],
    ];

    $form['full_screen_homepage_image_container'] = [
      '#type' => 'details',
      '#title' => $this->t('Full Screen Homepage Image'),
      '#states' => [
        'enabled' => [
          ':input[name="header_variation"]' => [
            'value' => 'focus',
          ],
        ],
      ],
    ];

    $form['desc_basic_container']['desc_basic'] = [
      '#type' => 'markup',
      '#prefix' => '<h2>Basic Nav</h2>',
      '#markup' => '<p>' . $this->t('The basic nav can have any number of items but only displays up to two levels of navigation using single-column dropdown menus.') . '</p>',
    ];

    $form['desc_mega_container']['desc_mega'] = [
      '#type' => 'markup',
      '#prefix' => '<h2>Mega Nav</h2>',
      '#markup' => '<p>' . $this->t('The mega nav provides a third level of navigation, giving you the ability to organize menu links into columns with dropdown menus.') . '</p>',
    ];

    $form['desc_focus_container']['desc_focus'] = [
      '#type' => 'markup',
      '#prefix' => '<h2>Focus Nav</h2>',
      '#markup' => '<p>' . $this->t('The focus nav combines a full image landing page with a single level of navigation.') . '</p>',
    ];

    if ($this->allowSecretItems()) {
      $form['site_name_image_container']['site_name_image'] = [
        '#type' => 'managed_file',
        '#upload_location' => 'public://site-name-images',
        '#multiple' => FALSE,
        '#description' => $this->t('Replaces the site name text with an image.<br>Allowed extensions: svg'),
        '#upload_validators' => [
          'file_validate_extensions' => ['svg'],
        ],
        '#title' => $this->t('Site Name Image'),
        '#default_value' => ($headerConfig->get('site_name_image')) ? $headerConfig->get('site_name_image') : NULL,
        '#theme' => 'image_widget',
        '#preview_image_style' => 'media_library',
        '#use_preview' => TRUE,
        '#use_svg_preview' => TRUE,
      ];
    }

    $form['nav_position_container']['nav_position'] = [
      '#type' => 'radios',
      '#options' => [
        'left' => $this->t('Left') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_core/images/preview-icons/lever-nav-left.svg" class="preview-icon" alt="Basic header icon showing a site title and a simplified navigation.">',
        'center' => $this->t('Center') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_core/images/preview-icons/lever-nav-center.svg" class="preview-icon" alt="Mega header icon showing a site title and a flyout style mega menu.">',
        'right' => $this->t('Right') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_core/images/preview-icons/lever-nav-right.svg" class="preview-icon" alt="TKTKTK">',
      ],
      '#title' => $this->t('Navigation Position'),
      '#description' => $this->t('Justifies the menu to the left, center, or right.'),
      '#default_value' => ($headerConfig->get('nav_position')) ? $headerConfig->get('nav_position') : 'left',
      '#attributes' => [
        'class' => [
          'variation-radios',
        ],
      ],
    ];

    $form['site_search_container']['enable_search_form'] = [
      '#type' => 'checkbox',
      '#description' => $this->t('When enabled, a site search form will be displayed in the Utility Menu.'),
      '#title' => $this->t('Enable search form'),
      '#default_value' => $headerConfig->get('search.enable_search_form'),
      '#states' => [
        'invisible' => [
          ':input[name="header_variation"]' => ['value' => 'focus'],
        ],
      ],
    ];

    $form['full_screen_homepage_image_container']['focus_header_image'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['image'],
      '#title' => $this->t('Homepage header image'),
      '#required' => FALSE,
      '#default_value' => ($headerConfig->get('focus_header_image')) ? $headerConfig->get('focus_header_image') : NULL,
      '#description' => $this->t('Used for the full-screen homepage image when the Focus Header is selected.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue('header_variation') == 'focus' && !$form_state->getValue('focus_header_image')) {
      $form_state->setErrorByName(
        'focus_header_image',
        $this->t("The homepage header image is required when Focus nav is selected")
      );
    }
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

    // Header settings config.
    $headerConfig = $this->config('ys_core.header_settings');

    // Site settings config.
    $siteConfig = $this->config('system.site');

    // Handles adding a title to the uploaded SVG.
    if ($siteNameImage = $form_state->getValue('site_name_image')) {
      $this->ysMediaManager->titleSvg($siteNameImage[0], $siteConfig->get('name'));
    };

    // Handle the favicon filesystem if needed.
    $this->ysMediaManager->handleMediaFilesystem(
      $form_state->getValue('site_name_image'),
      $headerConfig->get('site_name_image')
    );

    $headerConfig->set('header_variation', $form_state->getValue('header_variation'));
    $headerConfig->set('site_name_image', $form_state->getValue('site_name_image'));
    $headerConfig->set('nav_position', $form_state->getValue('nav_position'));
    $headerConfig->set('search.enable_search_form', $form_state->getValue('enable_search_form'));
    $headerConfig->set('focus_header_image', $form_state->getValue('focus_header_image'));

    $headerConfig->save();

    $this->cacheRender->invalidateAll();
    return parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'ys_core.header_settings',
    ];
  }

  /**
   * If current user is platform admin or user 1, allow secret items.
   *
   * @return bool
   *   Returns TRUE if current user is a platform admin or user 1.
   */
  private function allowSecretItems() {
    $allowSecretItems = FALSE;

    if ($this->currentUserSession->getAccount()->id() == 1 || in_array('platform_admin', $this->currentUserSession->getAccount()->getRoles())) {
      $allowSecretItems = TRUE;
    }

    return $allowSecretItems;
  }

}
