<?php

namespace Drupal\ys_core\Form;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ys_core\SocialLinksManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing footer-related settings.
 *
 * @package Drupal\ys_core\Form
 */
class FooterSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_core_footer_settings_form';
  }

  /**
   * THe Drupal backend cache renderer service.
   *
   * @var \Drupal\Core\Path\CacheBackendInterface
   */
  protected $cacheRender;

  /**
   * Social Links Manager.
   *
   * @var \Drupal\ys_core\SocialLinksManager
   */
  protected $socialLinks;

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
    $config = $this->config('ys_core.social_links');

    $form['#attached']['library'][] = 'ys_core/footer_settings_form';

    $form['footer_tabs'] = [
      '#type' => 'horizontal_tabs',
    ];

    $form['footer_content'] = [
      '#type' => 'details',
      '#title' => $this->t('Footer Content'),
      '#open' => TRUE,
      '#group' => 'footer_tabs',
    ];

    $form['footer_links'] = [
      '#type' => 'details',
      '#title' => $this->t('Footer Links'),
      '#group' => 'footer_tabs',
      '#attributes' => [
        'class' => [
          'ys-footer-links',
        ],
      ],
    ];

    $form['social_links'] = [
      '#type' => 'details',
      '#title' => $this->t('Social Links'),
      '#group' => 'footer_tabs',
    ];

    $form['footer_content']['footer_logos'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['image'],
      '#title' => $this->t('Footer logos'),
      '#required' => FALSE,
      '#cardinality' => 4,
      //'#default_value' => ($yaleConfig->get('image_fallback')) ? $yaleConfig->get('image_fallback')['teaser'] : NULL,
      //'#description' => $this->t('This image will be used for event and post card displays when no teaser image is selected.'),
    ];

    $form['footer_links']['links_col_1_heading'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link Column 1 Heading'),
    ];

    $form['footer_links']['links_col_2_heading'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link Column 2 Heading'),
    ];

    $form['footer_links']['links_col_1'] = [
      '#type' => 'multivalue',
      '#title' => $this->t('Links Column 1'),
      '#cardinality' => 4,
      'link_url' => [
        '#type' => 'linkit',
        '#title' => $this->t('URL'),
        '#description' => $this->t('Type the URL or autocomplete for internal paths.'),
        '#autocomplete_route_name' => 'linkit.autocomplete',
        '#default_value' => $config->get('alert.link_url'),
        '#autocomplete_route_parameters' => [
          'linkit_profile_id' => 'default',
        ],
      ],
      'link_title' => [
        '#type' => 'textfield',
        '#title' => $this->t('Link Title'),
      ],
    ];

    $form['footer_links']['links_col_2'] = [
      '#type' => 'multivalue',
      '#title' => $this->t('Links Column 2'),
      '#cardinality' => 4,
      'link_url' => [
        '#type' => 'linkit',
        '#title' => $this->t('URL'),
        '#description' => $this->t('Type the URL or autocomplete for internal paths.'),
        '#autocomplete_route_name' => 'linkit.autocomplete',
        '#default_value' => $config->get('alert.link_url'),
        '#autocomplete_route_parameters' => [
          'linkit_profile_id' => 'default',
        ],
      ],
      'link_title' => [
        '#type' => 'textfield',
        '#title' => $this->t('Link Title'),
      ],
    ];

    foreach ($this->socialLinks::SITES as $id => $name) {
      $form['social_links'][$id] = [
        '#type' => 'url',
        '#title' => $this->t('@name URL', ['@name' => $name]),
        '#default_value' => $config->get($id),
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
    $config = $this->config('ys_core.social_links');
    foreach ($this->socialLinks::SITES as $id => $name) {
      $config->set($id, $form_state->getValue($id));
    }
    $config->save();
    $this->cacheRender->invalidateAll();
    return parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'ys_core.social_links',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('cache.render'),
      $container->get('ys_core.social_links_manager')
    );
  }

  /**
   * Constructs the object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Path\CacheBackendInterface $cache_render
   *   The Cache backend interface.
   * @param \Drupal\ys_core\SocialLinksManager $social_links_manager
   *   The Yale social media links management service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, CacheBackendInterface $cache_render, SocialLinksManager $social_links_manager) {
    parent::__construct($config_factory);
    $this->cacheRender = $cache_render;
    $this->socialLinks = $social_links_manager;
  }

}
