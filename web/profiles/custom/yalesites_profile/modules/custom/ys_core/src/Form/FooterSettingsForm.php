<?php

namespace Drupal\ys_core\Form;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
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
    $socialConfig = $this->config('ys_core.social_links');
    $footerConfig = $this->config('ys_core.footer_settings');

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
      '#title' => $this->t('Footer logos'),
      '#allowed_bundles' => ['image'],
      '#required' => FALSE,
      '#cardinality' => 4,
      '#default_value' => ($footerConfig->get('content.logos')) ? implode(',', $footerConfig->get('content.logos')) : NULL,
    ];

    $form['footer_content']['footer_text'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Text Content'),
      '#format' => 'restricted_html',
      '#default_value' => (isset($footerConfig->get('content.text')['value'])) ? $footerConfig->get('content.text')['value'] : NULL,
    ];

    $form['footer_links']['links_col_1_heading'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link Column 1 Heading'),
      '#default_value' => ($footerConfig->get('links.links_col_1_heading')) ? $footerConfig->get('links.links_col_1_heading') : NULL,
    ];

    $form['footer_links']['links_col_2_heading'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link Column 2 Heading'),
      '#default_value' => ($footerConfig->get('links.links_col_2_heading')) ? $footerConfig->get('links.links_col_2_heading') : NULL,
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
        //'#default_value' => $config->get('alert.link_url'),
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
        //'#default_value' => $config->get('alert.link_url'),
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
        '#default_value' => $socialConfig->get($id),
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $this->validateFooterLinks($form_state, 'links_col_1');
    $this->validateFooterLinks($form_state, 'links_col_2');
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
    // Social config.
    $socialConfig = $this->config('ys_core.social_links');
    foreach ($this->socialLinks::SITES as $id => $name) {
      $socialConfig->set($id, $form_state->getValue($id));
    }
    $socialConfig->save();

    // Footer settings config.
    $footerConfig = $this->config('ys_core.footer_settings');
    $footerConfig->set('content.logos', explode(',', $form_state->getValue('footer_logos')));
    $footerConfig->set('content.text', $form_state->getValue('footer_text'));
    $footerConfig->set('links.links_col_1_heading', $form_state->getValue('links_col_1_heading'));
    $footerConfig->set('links.links_col_2_heading', $form_state->getValue('links_col_2_heading'));

    $footerConfig->save();

    $this->cacheRender->invalidateAll();
    return parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'ys_core.social_links',
      'ys_core.footer_settings',
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

  protected function validateFooterLinks($form_state, $field_id) {
    if (($value = $form_state->getValue($field_id))) {
      foreach ($value as $index => $link) {

      //   if (empty($link['link_url'])) {
      //     $form_state->setErrorByName(
      //       $field_id,
      //       $this->t(
      //         "Links must contain a URL",
      //         ['%path' => $form_state->getValue($field_id)]
      //       )
      //     );
      //   }
        global $base_url;

        if (empty($link['link_title'])) {
          $response = new TrustedRedirectResponse($base_url . '/admin/yalesites/footer#edit-footer-links');

          $form_state->setErrorByName(
            $field_id,
            $this->t("Links must contain a title"),
          );
          $form_state->setResponse($response);
        }

      }
    }
  }

}
