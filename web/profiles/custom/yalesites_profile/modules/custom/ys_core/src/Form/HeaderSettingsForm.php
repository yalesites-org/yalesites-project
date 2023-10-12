<?php

namespace Drupal\ys_core\Form;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxy;
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
   * Constructs the object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Path\CacheBackendInterface $cache_render
   *   The Cache backend interface.
   * @param \Drupal\Core\Session\AccountProxy $currentUserSession
   *   The current user session.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    CacheBackendInterface $cache_render,
    AccountProxy $current_user_session,
    ) {
    parent::__construct($config_factory);
    $this->cacheRender = $cache_render;
    $this->currentUserSession = $current_user_session;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('cache.render'),
      $container->get('current_user'),
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
    $allowSecretItems = FALSE;

    if ($this->currentUserSession->getAccount()->id() == 1 || in_array('platform_admin', $this->currentUserSession->getAccount()->getRoles())) {
      $allowSecretItems = TRUE;
    }

    $form['#attached']['library'][] = 'ys_core/header_footer_settings';
    $form['#attributes']['class'][] = 'ys-core-header-footer-settings';

    $form['header_variation'] = [
      '#type' => 'radios',
      '#options' => [
        'basic' => $this->t('Basic') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_core/images/preview-icons/header-basic.svg" class="preview-icon" alt="Basic header icon showing a site title and a simplified navigation.">',
        'mega' => $this->t('Mega') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_core/images/preview-icons/header-mega.svg" class="preview-icon" alt="Mega header icon showing a site title and a flyout style mega menu.">',
        'focus' => $this->t('Focus') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_core/images/preview-icons/footer-mega-2.svg" class="preview-icon" alt="TKTKTK">',
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

    if ($allowSecretItems) {
      $form['all_headers'] = [
        '#type' => 'details',
        '#title' => $this->t('All Headers'),
      ];
    }

    $form['basic_and_mega_header'] = [
      '#type' => 'details',
      '#title' => $this->t('Basic & Mega'),
      '#states' => [
        'disabled' => [
          ':input[name="header_variation"]' => [
            'value' => 'focus',
          ],
        ],
      ],
    ];

    $form['focus_header'] = [
      '#type' => 'details',
      '#title' => $this->t('Focus'),
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
      '#prefix' => '<h2>Basic</h2>',
      '#markup' => '<p>' . $this->t('The basic header of your website contains...') . '</p>',
    ];

    $form['desc_mega_container']['desc_mega'] = [
      '#type' => 'markup',
      '#prefix' => '<h2>Mega Header</h2>',
      '#markup' => '<p>' . $this->t('The mega header of your website can ...') . '</p>',
    ];

    $form['desc_focus_container']['desc_focus'] = [
      '#type' => 'markup',
      '#prefix' => '<h2>Focus Header</h2>',
      '#markup' => '<p>' . $this->t('Focus header shows a full-width image on the homepage...') . '</p>',
    ];

    if ($allowSecretItems) {
      $form['all_headers']['site_name_image'] = [
        '#type' => 'file',
        //'#upload_location' => 'public://header-logos',
        '#multiple' => FALSE,
        '#description' => $this->t('Allowed extensions: svg'),
        // '#upload_validators' => [
        //   'file_validate_is_image' => [],
        //   'file_validate_extensions' => ['svg'],
        // ],
        '#title' => $this->t('Site Name Image'),
        //'#default_value' => ($yaleConfig->get('custom_favicon')) ? $yaleConfig->get('custom_favicon') : NULL,
        // '#theme' => 'image_widget',
        // '#preview_image_style' => 'favicon_16x16',
        // '#use_favicon_preview' => TRUE,
      ];
    }

    $form['basic_and_mega_header']['enable_search_form'] = [
      '#type' => 'checkbox',
      '#description' => $this->t('Enable the search form located in the utility navigation area.'),
      '#title' => $this->t('Enable search form'),
      '#default_value' => $headerConfig->get('search.enable_search_form'),
      '#states' => [
        'invisible' => [
          ':input[name="header_variation"]' => ['value' => 'focus'],
        ],
      ],
    ];

    $form['focus_header']['focus_header_image'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['image'],
      '#title' => $this->t('Homepage Header image'),
      '#required' => FALSE,
      '#default_value' => ($headerConfig->get('focus_header_image')) ? $headerConfig->get('focus_header_image') : NULL,
      '#description' => $this->t('Used only on the homepage when focus header is selected.'),
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

    // Footer settings config.
    $headerConfig = $this->config('ys_core.header_settings');

    $headerConfig->set('header_variation', $form_state->getValue('header_variation'));
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

}
