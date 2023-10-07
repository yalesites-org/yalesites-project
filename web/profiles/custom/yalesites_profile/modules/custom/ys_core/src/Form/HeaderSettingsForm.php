<?php

namespace Drupal\ys_core\Form;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
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
   * Constructs the object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Path\CacheBackendInterface $cache_render
   *   The Cache backend interface.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    CacheBackendInterface $cache_render,
    ) {
    parent::__construct($config_factory);
    $this->cacheRender = $cache_render;
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
        'basic' => $this->t('Basic') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_core/images/preview-icons/header-basic.svg" class="preview-icon" alt="Basic header icon showing a site title and a simplified navigation.">',
        'mega' => $this->t('Mega') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_core/images/preview-icons/header-mega.svg" class="preview-icon" alt="Mega header icon showing a site title and a flyout style mega menu.">',
        'focus' => $this->t('Focus') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_core/images/preview-icons/footer-mega-2.svg" class="preview-icon" alt="TKTKTK">',
      ],
      '#title' => $this->t('Header variation'),
      '#default_value' => ($headerConfig->get('header_variation')) ? $headerConfig->get('footer_variation') : 'basic',
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

    $form['desc_basic_container']['desc_basic'] = [
      '#type' => 'markup',
      '#prefix' => '<h2>Basic</h2>',
      '#markup' => '<p>' . $this->t('The basic header of your website contains TKTKTK only social media icons and Yale branding.') . '</p>',
    ];

    $form['desc_mega_container']['desc_mega'] = [
      '#type' => 'markup',
      '#prefix' => '<h2>Mega Header</h2>',
      '#markup' => '<p>' . $this->t('The mega header of your website TKTKTK can be customized to suit your organizational needs. You can upload icons for various organizational identities and other platforms that your organization uses. You can also add a customizable text area with general information, contact information, or a physical address. Additionally, you can add up to 8 links in a two-column format.') . '</p>',
    ];

    $form['enable_search_form'] = [
      '#type' => 'checkbox',
      '#description' => $this->t('Enable the search form located in the utility navigation area.'),
      '#title' => $this->t('Enable search form'),
      // '#default_value' => $yaleConfig->get('search')['enable_search_form'],
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
