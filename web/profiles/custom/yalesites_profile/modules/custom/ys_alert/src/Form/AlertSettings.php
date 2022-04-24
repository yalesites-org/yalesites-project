<?php

namespace Drupal\ys_alert\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\CachedDiscoveryClearerInterface;

/**
 * Configure example settings for this site.
 */
class AlertSettings extends ConfigFormBase {

  /**
   * Constructs a SiteInformationForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Path\CacheBackendInterface $cacheRender
   *   The Cache BE interface.
   * @param \Drupal\Core\Routing\CachedDiscoveryClearerInterface $plugin_cache_clearer
   *   The Cache Disovery interface.
   */
  public function __construct(ConfigFactoryInterface $config_factory, CacheBackendInterface $cacheRender, CachedDiscoveryClearerInterface $plugin_cache_clearer) {
    parent::__construct($config_factory);
    $this->cacheRender = $cacheRender;
    $this->pluginCacheClearer = $plugin_cache_clearer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('cache.render'),
      $container->get('plugin.cache_clearer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_alert_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ys_alert.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ys_alert.settings');

    $form['id'] = [
      '#type' => 'hidden',
      '#value' => $config->get('id'),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled?'),
      '#description' => $this->t('Check this field if you want the alert to be visible across the site.'),
      '#default_value' => $config->get('status'),
      '#required' => FALSE,
    ];

    $form['type'] = [
      '#type' => 'select',
      '#options' => [
        'announcement' => $this->t('Announcement'),
        'emergency' => $this->t('Emergency'),
        'marketing' => $this->t('Marketing'),
      ],
      '#title' => $this->t('Alert type'),
      '#description' => $this->t('Pick the desired alert type.'),
      '#default_value' => $config->get('type'),
      '#required' => TRUE,
    ];

    $form['headline'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Headline'),
      '#default_value' => $config->get('headline'),
      '#required' => TRUE,
    ];

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#default_value' => $config->get('message'),
      '#required' => TRUE,
    ];

    $form['link_wrapper'] = [
      '#type' => 'details',
      '#title' => $this->t('Link'),
      '#open' => TRUE,
    ];

    $form['link_wrapper']['link_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link Title'),
      '#default_value' => $config->get('link_title'),
      '#states' => [
        // Make link title required if URL is set.
        'required' => [
          ':input[name="link_url"]' => ['filled' => TRUE],
        ],
      ],
    ];

    $form['link_wrapper']['link_url'] = [
      '#type' => 'linkit',
      '#title' => $this->t('URL'),
      '#description' => $this->t('Type the URL or autocomplete for internal paths.'),
      '#autocomplete_route_name' => 'linkit.autocomplete',
      '#default_value' => $config->get('link_url'),
      '#autocomplete_route_parameters' => [
        'linkit_profile_id' => 'default',
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Retrieve the configuration.
    $this->configFactory->getEditable('ys_alert.settings')
      // Set the submitted configuration setting.
      ->set('id', time())
      ->set('headline', $form_state->getValue('headline'))
      ->set('message', $form_state->getValue('message'))
      ->set('status', $form_state->getValue('status'))
      ->set('type', $form_state->getValue('type'))
      ->set('link_title', $form_state->getValue('link_title'))
      ->set('link_url', $form_state->getValue('link_url'))
      ->save();

    if ($this->cacheRender->get('config')) {
      Cache::invalidateTags(['block.block.alertblock']);
    }
    $this->cacheRender->invalidateAll();
    $this->pluginCacheClearer->clearCachedDefinitions();
    parent::submitForm($form, $form_state);
  }

}
