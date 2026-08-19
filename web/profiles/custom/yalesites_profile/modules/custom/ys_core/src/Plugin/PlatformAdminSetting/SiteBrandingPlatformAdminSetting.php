<?php

namespace Drupal\ys_core\Plugin\PlatformAdminSetting;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ys_core\Attribute\PlatformAdminSetting;
use Drupal\ys_core\PlatformAdminSettingBase;
use Drupal\ys_core\YaleSitesMediaManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Platform admin control for the site's Yale branding lockup.
 *
 * The Site Name Image and the sitewide branding name and link used to live in
 * Header settings, added only when the viewer passed a platform-admin check. A
 * site admin and a platform admin therefore saw two different Header settings
 * forms, and the form needed a matching guard in its submit handler purely to
 * stop a site admin's ordinary save from overwriting these three values with
 * NULL (yalesites-org/YaleSites-Internal#1560). Moving them here lets the
 * Platform Admin Settings route permission do the gating, so Header settings is
 * the same form for everyone who can reach it and the guard is deleted rather
 * than relocated.
 *
 * The config keys are deliberately unchanged - they still live in
 * ys_core.header_settings, which is what the header template reads - so
 * existing values keep working with no update hook or data migration.
 *
 * Saving also flushes the render cache, exactly as HeaderSettingsForm did. That
 * is not optional here: the header reads these values through
 * CoreTwigExtension::getHeaderSetting(), which returns raw config values and
 * bubbles no cacheability, so the rendered header carries no
 * config:ys_core.header_settings cache tag and Config::save()'s own tag
 * invalidation reaches nothing. Without the flush a new lockup would not appear
 * until the next cache rebuild.
 */
#[PlatformAdminSetting(
  id: 'site_branding',
  label: new TranslatableMarkup('Site Branding'),
  weight: -20,
)]
class SiteBrandingPlatformAdminSetting extends PlatformAdminSettingBase {

  /**
   * The config object these settings have always been stored in.
   */
  const CONFIG_NAME = 'ys_core.header_settings';

  /**
   * The config keys this section owns, unchanged from HeaderSettingsForm.
   */
  const CONFIG_KEYS = [
    'site_name_image',
    'site_wide_branding_name',
    'site_wide_branding_link',
  ];

  /**
   * The values shown, and rendered, when config carries nothing.
   *
   * These must stay in sync with the site-header component, which applies the
   * same two fallbacks with Twig's |default() and cannot read a PHP constant
   * from its own repo (component-library-twig, in
   * components/03-organisms/site-header/_site-header--secondary.twig). Because
   * both sides agree, an unwritten value renders exactly as a stored one -
   * which is what lets an untouched save skip the write entirely.
   */
  const DISPLAY_DEFAULTS = [
    'site_wide_branding_name' => 'Yale University',
    'site_wide_branding_link' => 'https://www.yale.edu',
  ];

  /**
   * The YaleSites media manager.
   *
   * @var \Drupal\ys_core\YaleSitesMediaManager
   */
  protected YaleSitesMediaManager $mediaManager;

  /**
   * The render cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected CacheBackendInterface $cacheRender;

  /**
   * Constructs a SiteBrandingPlatformAdminSetting object.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\ys_core\YaleSitesMediaManager $media_manager
   *   The YaleSites media manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_render
   *   The render cache backend.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ConfigFactoryInterface $config_factory,
    AccountInterface $current_user,
    YaleSitesMediaManager $media_manager,
    CacheBackendInterface $cache_render,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $config_factory, $current_user);
    $this->mediaManager = $media_manager;
    $this->cacheRender = $cache_render;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('ys_core.media_manager'),
      $container->get('cache.render'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildSettings(array $form, FormStateInterface $form_state): array {
    $config = $this->configFactory->get(self::CONFIG_NAME);

    $form['site_name_image'] = [
      '#type' => 'managed_file',
      '#upload_location' => 'public://site-name-images',
      '#multiple' => FALSE,
      '#description' => $this->t('Replaces the site name text with an image.<br>Allowed extensions: svg'),
      '#upload_validators' => [
        'file_validate_extensions' => ['svg'],
      ],
      '#title' => $this->t('Site Name Image'),
      '#default_value' => $config->get('site_name_image') ?: NULL,
      '#theme' => 'image_widget',
      '#preview_image_style' => 'media_library',
      '#use_preview' => TRUE,
      '#use_svg_preview' => TRUE,
    ];

    $form['site_wide_branding_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site-wide branding name'),
      '#description' => $this->t('Enter the name of the site to be displayed in the header.'),
      '#default_value' => $config->get('site_wide_branding_name') ?? self::DISPLAY_DEFAULTS['site_wide_branding_name'],
      '#required' => TRUE,
    ];

    $form['site_wide_branding_link'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site-wide branding link'),
      '#description' => $this->t('Enter the URL that the site-wide branding name should link to.'),
      '#default_value' => $config->get('site_wide_branding_link') ?? self::DISPLAY_DEFAULTS['site_wide_branding_link'],
      '#autocomplete_route_name' => 'linkit.autocomplete',
      '#autocomplete_route_parameters' => [
        'linkit_profile_id' => 'default',
      ],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitSettings(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->getEditable(self::CONFIG_NAME);
    $submitted = [];
    $current = [];
    foreach (self::CONFIG_KEYS as $key) {
      $submitted[$key] = $form_state->getValue([$this->getPluginId(), $key]);
      $current[$key] = $config->get($key);
    }

    // The page has one Save button for every section, so this runs even when
    // nobody touched branding. Bail out then, rather than flushing the whole
    // render cache for an unrelated section's save. The comparison is
    // normalized because the two sides hold the same values in different
    // shapes - see normalize().
    if ($this->normalize($submitted) === $this->normalize($current)) {
      return;
    }

    // Mark a newly uploaded image permanent and release the one it replaces.
    $this->mediaManager->handleMediaFilesystem($submitted['site_name_image'], $current['site_name_image']);

    foreach (self::CONFIG_KEYS as $key) {
      $config->set($key, $submitted[$key]);
    }
    $config->save();

    $this->cacheRender->invalidateAll();
  }

  /**
   * Reduces branding values to a shape that compares stored against submitted.
   *
   * @param array $values
   *   Branding values keyed by config key, from either side.
   *
   * @return array
   *   The same keys, with the image as a plain list of file ids and the name
   *   and link as strings carrying their displayed fallback.
   */
  private function normalize(array $values): array {
    $normalized = [];
    foreach (self::CONFIG_KEYS as $key) {
      $value = $values[$key] ?? NULL;
      // An unset image is '' in config, NULL once read and [] from the
      // managed_file element - all of them the same "no image". The field is
      // #multiple => FALSE, so there is at most one fid to compare.
      $normalized[$key] = $key === 'site_name_image'
        ? array_filter((array) $value)
        : (string) ($value ?? self::DISPLAY_DEFAULTS[$key]);
    }
    return $normalized;
  }

}
