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

    $form['#attached']['library'][] = 'ys_core/header_footer_settings';
    $form['#attributes']['class'][] = 'ys-core-header-footer-settings';

    $form['footer_variation'] = [
      '#type' => 'radios',
      '#options' => [
        'basic' => $this->t('Basic') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_core/images/preview-icons/footer-basic.svg" class="preview-icon" alt="Basic footer icon showing a small Yale logo, and gray placeholders for copyright and social media icons.">',
        'mega' => $this->t('Mega') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_core/images/preview-icons/footer-mega-2.svg" class="preview-icon" alt="Mega footer icon with gray placeholders for more information than the basic footer.">',
      ],
      '#title' => $this->t('Footer variation'),
      '#default_value' => ($footerConfig->get('footer_variation')) ? $footerConfig->get('footer_variation') : 'basic',
      '#attributes' => [
        'class' => [
          'variation-radios',
        ],
      ],
    ];

    $form['desc_basic_container'] = [
      '#type' => 'container',
      '#title' => $this->t('Basic Footer'),
      '#states' => [
        'visible' => [
          ':input[name="footer_variation"]' => ['value' => 'basic'],
        ],
      ],
    ];

    $form['desc_mega_container'] = [
      '#type' => 'container',
      '#title' => $this->t('Mega Footer'),
      '#states' => [
        'visible' => [
          ':input[name="footer_variation"]' => ['value' => 'mega'],
        ],
      ],
    ];

    $form['desc_basic_container']['desc_basic'] = [
      '#type' => 'markup',
      '#prefix' => '<h2>Basic Footer</h2>',
      '#markup' => '<p>' . $this->t('The basic footer of your website contains only social media icons and Yale branding.') . '</p>',
    ];

    $form['desc_mega_container']['desc_mega'] = [
      '#type' => 'markup',
      '#prefix' => '<h2>Mega Footer</h2>',
      '#markup' => '<p>' . $this->t('The mega footer of your website can be customized to suit your organizational needs. You can upload icons for various organizational identities and other platforms that your organization uses. You can also add a customizable text area with general information, contact information, or a physical address. Additionally, you can add up to 8 links in a two-column format.') . '</p>',
    ];

    $form['social_links'] = [
      '#type' => 'details',
      '#title' => $this->t('Social Links'),
    ];

    $form['footer_logos'] = [
      '#type' => 'details',
      '#title' => $this->t('Footer Logos'),
      '#states' => [
        'disabled' => [
          ':input[name="footer_variation"]' => ['value' => 'basic'],
        ],
      ],
    ];

    $form['footer_content'] = [
      '#type' => 'details',
      '#title' => $this->t('Footer Content'),
      '#states' => [
        'disabled' => [
          ':input[name="footer_variation"]' => ['value' => 'basic'],
        ],
      ],
    ];

    $form['footer_links'] = [
      '#type' => 'details',
      '#title' => $this->t('Footer Links'),
      '#attributes' => [
        'class' => [
          'ys-footer-links',
        ],
      ],
      '#states' => [
        'disabled' => [
          ':input[name="footer_variation"]' => ['value' => 'basic'],
        ],
      ],
    ];

    $form['footer_logos']['logos'] = [
      '#type' => 'multivalue',
      '#title' => $this->t('Footer Logos'),
      '#cardinality' => 2,
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

    $form['footer_logos']['school_logo_group']['aria-description'] = [
      '#type' => 'markup',
      '#markup' => "<div id='edit-school-logo--description'>A horizontal logotype that is placed below the 4 logos on the left side of the footer.</div>",
    ];

    $form['footer_logos']['school_logo_group']['school_logo'] = [
      '#type' => 'media_library',
      '#title' => $this->t('School logo'),
      '#allowed_bundles' => ['image'],
      '#required' => FALSE,
      '#default_value' => ($footerConfig->get('content.school_logo')) ? $footerConfig->get('content.school_logo') : NULL,
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
    foreach ($form_state->getValue('logos') as $key => $logo) {

      $logoLinks[$key]['logo_url'] = $logo['logo_url'] ? $this->translateNodeLinks($logo['logo_url']) : NULL;
      $logoLinks[$key]['logo'] = $logo['logo'];
    }

    if ($schoolLogoLink = $form_state->getValue('school_logo_url')) {
      $schoolLogoLink = $this->translateNodeLinks($schoolLogoLink);
    }

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
    $link = (str_starts_with($link, "/node/")) ? $this->pathAliasManager->getAliasByPath($link) : $link;
    return $link;
  }

}
