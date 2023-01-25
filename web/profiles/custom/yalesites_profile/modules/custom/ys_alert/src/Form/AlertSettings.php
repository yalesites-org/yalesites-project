<?php

namespace Drupal\ys_alert\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\CachedDiscoveryClearerInterface;
use Drupal\ys_alert\AlertManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the manage alerts interface.
 */
class AlertSettings extends ConfigFormBase {

  /**
   * A cache backend interface instance.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheRender;

  /**
   * A plugin cache clear instance.
   *
   * @var \Drupal\Core\Plugin\CachedDiscoveryClearerInterface
   */
  protected $pluginCacheClearer;

  /**
   * The YaleSites alerts management service.
   *
   * @var \Drupal\ys_alert\AlertManager
   */
  protected $alertManager;

  /**
   * Constructs a SiteInformationForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Path\CacheBackendInterface $cacheRender
   *   The Cache BE interface.
   * @param \Drupal\Core\Routing\CachedDiscoveryClearerInterface $plugin_cache_clearer
   *   The Cache Disovery interface.
   * @param \Drupal\ys_alert\AlertManager $alert_manager
   *   The YaleSites Alert Manager service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    CacheBackendInterface $cacheRender,
    CachedDiscoveryClearerInterface $plugin_cache_clearer,
    AlertManager $alert_manager
    ) {
    parent::__construct($config_factory);
    $this->cacheRender = $cacheRender;
    $this->pluginCacheClearer = $plugin_cache_clearer;
    $this->alertManager = $alert_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('cache.render'),
      $container->get('plugin.cache_clearer'),
      $container->get('ys_alert.manager')
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

    // Attach JS for adding a modal confirmation message.
    $form['#attached']['library'][] = 'ys_alert/confirm_type_modal';

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
      '#type' => 'radios',
      '#options' => $this->alertManager->getTypeOptions(),
      '#title' => $this->t('Alert Type'),
      '#default_value' => $config->get('type'),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateAlertDescriptionCallback',
        'wrapper' => 'alert-description',
        'progress' => [
          'type' => 'none',
        ],
      ],
    ];

    // The description is rebuilt with a callback when type field changes.
    $type = $form_state->getValue('type') ?? $config->get('type');
    $form['type_description'] = [
      '#prefix' => '<div id="alert-description">',
      '#suffix' => '</div>',
      '#markup' => $this->alertManager->getTypeDescription($type),
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

  /**
   * Rebuild part of the form to display the matching type description.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The portion of the render structure that will be updated.
   */
  public function updateAlertDescriptionCallback(array $form, FormStateInterface $form_state) {
    return $form['type_description'];
  }

}
