<?php

namespace Drupal\ys_core\Form;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\path_alias\AliasManager;
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
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManager
   */
  protected $pathAliasManager;

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
      '#type' => 'vertical_tabs',
    ];

    $form['footer_logos'] = [
      '#type' => 'details',
      '#title' => $this->t('Footer Logos'),
      '#open' => TRUE,
      '#group' => 'footer_tabs',
    ];

    $form['footer_content'] = [
      '#type' => 'details',
      '#title' => $this->t('Footer Content'),
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

    $form['footer_variation'] = [
      '#type' => 'radios',
      '#options' => [
        'basic' => $this->t('Basic - displays only the social links.'),
        'mega' => $this->t('Mega - displays all of the fields listed below.'),
      ],
      '#title' => $this->t('Footer variation'),
      '#description' => $this->t('All variations display Yale branding, accessibility and privacy links, and copyright information.'),
      '#default_value' => ($footerConfig->get('footer_variation')) ? $footerConfig->get('footer_variation') : 'basic',
      '#weight' => -1,
    ];

    $form['footer_logos']['logos'] = [
      '#type' => 'multivalue',
      '#title' => $this->t('Footer Logos'),
      '#cardinality' => 4,
      '#default_value' => ($footerConfig->get('content.logos')) ? $footerConfig->get('content.logos') : [],

      'logo' => [
        '#type' => 'media_library',
        '#title' => $this->t('Logo'),
        '#allowed_bundles' => ['image'],
        '#required' => FALSE,
      ],

      'logo_url' => [
        '#type' => 'linkit',
        '#title' => $this->t('URL'),
        '#description' => $this->t('Type the URL or autocomplete for internal paths.'),
        '#autocomplete_route_name' => 'linkit.autocomplete',
        '#autocomplete_route_parameters' => [
          'linkit_profile_id' => 'default',
        ],
      ],

    ];

    $form['footer_logos']['school_logo_group'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('School Logo'),
    ];

    $form['footer_logos']['school_logo_group']['school_logo'] = [
      '#type' => 'media_library',
      '#title' => $this->t('School logo'),
      '#allowed_bundles' => ['image'],
      '#required' => FALSE,
      '#default_value' => ($footerConfig->get('content.school_logo')) ? $footerConfig->get('content.school_logo') : NULL,
      '#description' => $this->t('A horizontal logotype that is placed below the 4 logos on the left side of the footer.'),
    ];

    $form['footer_logos']['school_logo_group']['school_logo_url'] = [
      '#type' => 'linkit',
      '#title' => $this->t('School logo URL'),
      '#description' => $this->t('Type the URL or autocomplete for internal paths.'),
      '#autocomplete_route_name' => 'linkit.autocomplete',
      '#autocomplete_route_parameters' => [
        'linkit_profile_id' => 'default',
      ],
      '#default_value' => ($footerConfig->get('content.school_logo_url')) ? $footerConfig->get('content.school_logo_url') : '/',
    ];

    $form['footer_content']['footer_text'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Text Content'),
      '#format' => 'restricted_html',
      '#default_value' => (isset($footerConfig->get('content.text')['value'])) ? $footerConfig->get('content.text')['value'] : NULL,
    ];

    $form['footer_links']['links_col_1_heading'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Links Column 1 Heading'),
      '#default_value' => ($footerConfig->get('links.links_col_1_heading')) ? $footerConfig->get('links.links_col_1_heading') : NULL,
    ];

    $form['footer_links']['links_col_2_heading'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Links Column 2 Heading'),
      '#default_value' => ($footerConfig->get('links.links_col_2_heading')) ? $footerConfig->get('links.links_col_2_heading') : NULL,
    ];

    $form['footer_links']['links_col_1'] = [
      '#type' => 'multivalue',
      '#title' => $this->t('Links Column 1'),
      '#cardinality' => 4,
      '#default_value' => ($footerConfig->get('links.links_col_1')) ? $footerConfig->get('links.links_col_1') : [],

      'link_url' => [
        '#type' => 'linkit',
        '#title' => $this->t('URL'),
        '#description' => $this->t('Type the URL or autocomplete for internal paths.'),
        '#autocomplete_route_name' => 'linkit.autocomplete',
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
      '#default_value' => ($footerConfig->get('links.links_col_2')) ? $footerConfig->get('links.links_col_2') : [],
      'link_url' => [
        '#type' => 'linkit',
        '#title' => $this->t('URL'),
        '#description' => $this->t('Type the URL or autocomplete for internal paths.'),
        '#autocomplete_route_name' => 'linkit.autocomplete',
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

    $linksCol1 = $linksCol2 = $logoLinks = [];
    $schoolLogoLink = NULL;

    // Translate node links.
    foreach ($form_state->getValue('links_col_1') as $key => $link) {
      if ($link['link_url']) {
        $linksCol1[$key]['link_url'] = $this->translateNodeLinks($link['link_url']);
        $linksCol1[$key]['link_title'] = $link['link_title'];
      }
    }

    foreach ($form_state->getValue('links_col_2') as $key => $link) {
      if ($link['link_url']) {
        $linksCol2[$key]['link_url'] = $this->translateNodeLinks($link['link_url']);
        $linksCol2[$key]['link_title'] = $link['link_title'];
      }
    }

    foreach ($form_state->getValue('logos') as $key => $logo) {
      $logoLinks[$key]['logo_url'] = $logo['logo_url'] ? $this->translateNodeLinks($logo['logo_url']) : NULL;
      $logoLinks[$key]['logo'] = $logo['logo'];
    }

    if ($schoolLogoLink = $form_state->getValue('school_logo_url')) {
      $schoolLogoLink = $this->translateNodeLinks($schoolLogoLink);
    }

    // Footer settings config.
    $footerConfig = $this->config('ys_core.footer_settings');

    $footerConfig->set('footer_variation', $form_state->getValue('footer_variation'));
    $footerConfig->set('content.logos', $logoLinks);
    $footerConfig->set('content.school_logo', $form_state->getValue('school_logo'));
    $footerConfig->set('content.school_logo_url', $schoolLogoLink);
    $footerConfig->set('content.text', $form_state->getValue('footer_text'));
    $footerConfig->set('links.links_col_1_heading', $form_state->getValue('links_col_1_heading'));
    $footerConfig->set('links.links_col_2_heading', $form_state->getValue('links_col_2_heading'));
    $footerConfig->set('links.links_col_1', $linksCol1);
    $footerConfig->set('links.links_col_2', $linksCol2);

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
      $container->get('ys_core.social_links_manager'),
      $container->get('path_alias.manager'),
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
   * @param \Drupal\path_alias\AliasManager $path_alias_manager
   *   The Path Alias Manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    CacheBackendInterface $cache_render,
    SocialLinksManager $social_links_manager,
    AliasManager $path_alias_manager,
    ) {
    parent::__construct($config_factory);
    $this->cacheRender = $cache_render;
    $this->socialLinks = $social_links_manager;
    $this->pathAliasManager = $path_alias_manager;
  }

  /**
   * Check that footer links have both a URL and a link title.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the parent form.
   * @param string $field_id
   *   The id of a field on the config form.
   */
  protected function validateFooterLinks($form_state, $field_id) {
    if (($value = $form_state->getValue($field_id))) {
      foreach ($value as $link) {
        if (empty($link['link_url']) || empty($link['link_title'])) {
          $form_state->setErrorByName(
            $field_id,
            $this->t("Any link specified must have both a URL and a link title."),
          );
        }

      }
    }
  }

  /**
   * Translate internal node links to path links.
   *
   * @param string $link
   *   The path entered from the form.
   */
  protected function translateNodeLinks($link) {
    // If link URL is an internal path, use the path alias instead.
    if (!str_starts_with($link, "http")) {
      $linkPath = $this->pathAliasManager->getAliasByPath($link);
      return $linkPath;
    }
  }

}
