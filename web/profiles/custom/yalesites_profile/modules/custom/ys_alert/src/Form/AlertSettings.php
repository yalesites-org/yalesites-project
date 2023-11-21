<?php

namespace Drupal\ys_alert\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\CachedDiscoveryClearerInterface;
use Drupal\path_alias\AliasManager;
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
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManager
   */
  protected $pathAliasManager;

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
   * @param \Drupal\path_alias\AliasManager $path_alias_manager
   *   The Path Alias Manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    CacheBackendInterface $cacheRender,
    CachedDiscoveryClearerInterface $plugin_cache_clearer,
    AlertManager $alert_manager,
    AliasManager $path_alias_manager,
    ) {
    parent::__construct($config_factory);
    $this->cacheRender = $cacheRender;
    $this->pluginCacheClearer = $plugin_cache_clearer;
    $this->alertManager = $alert_manager;
    $this->pathAliasManager = $path_alias_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('cache.render'),
      $container->get('plugin.cache_clearer'),
      $container->get('ys_alert.manager'),
      $container->get('path_alias.manager'),
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
      '#value' => $config->get('alert.id'),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Turn on Alerts'),
      '#description' => $this->t('When selected, your alert will be visible across the site.'),
      '#default_value' => $config->get('alert.status'),
      '#required' => FALSE,
    ];

    $form['type'] = [
      '#type' => 'radios',
      '#options' => $this->alertManager->getTypeOptions(),
      '#title' => $this->t('Alert Type'),
      '#default_value' => $config->get('alert.type'),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateAlertDescriptionWrapperCallback',
        'wrapper' => 'alert-description-wrapper',
        'progress' => [
          'type' => 'none',
        ],
      ],
    ];

    // The description is rebuilt with a callback when type field changes.
    $type = $form_state->getValue('type') ?? $config->get('alert.type') ?? '';

    $form['type_description_wrapper'] = [
      '#prefix' => '<div id="alert-description-wrapper" role="dialog" aria-live="polite" aria-labelledby="alert-label" aria-describedby="alert-description">',
      '#suffix' => '</div>',
      '#markup' => '<h1 id="alert-label">' . $this->alertManager->getTypeLabel($type) . '</h1> <div id="alert-description">' . $this->alertManager->getTypeDescription($type) . '</div',
    ];

    $form['headline'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Headline'),
      '#default_value' => $config->get('alert.headline'),
      '#required' => TRUE,
    ];

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#default_value' => $config->get('alert.message'),
      '#required' => TRUE,
      '#attributes' => [
        'class' => [
          'maxlength',
        ],
        'data-maxlength' => 255,
        '#maxlength_js_enforce' => TRUE,
      ],
      '#attached' => [
        'library' => [
          'maxlength/maxlength',
        ],
      ],
    ];

    $form['link_wrapper'] = [
      '#type' => 'details',
      '#title' => $this->t('Link'),
      '#open' => TRUE,
    ];

    $form['link_wrapper']['link_url'] = [
      '#type' => 'linkit',
      '#title' => $this->t('URL'),
      '#description' => $this->t('Type the URL or autocomplete for internal paths.'),
      '#autocomplete_route_name' => 'linkit.autocomplete',
      '#default_value' => $config->get('alert.link_url'),
      '#autocomplete_route_parameters' => [
        'linkit_profile_id' => 'default',
      ],
    ];

    $form['link_wrapper']['link_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link Title'),
      '#default_value' => $config->get('alert.link_title'),
      '#states' => [
        // Make link title required if URL is set.
        'required' => [
          ':input[name="link_url"]' => ['filled' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validate link path.
    $this->validateUrl($form_state, 'link_url');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // If link URL is an internal path, use the path alias instead.
    $linkUrl = $form_state->getValue('link_url');
    if ($linkUrl) {
      if (!str_starts_with($linkUrl, "http")) {
        $linkUrl = $this->pathAliasManager->getAliasByPath($linkUrl);
      }
    }

    $this->configFactory->getEditable('ys_alert.settings')
      // Set the submitted configuration setting.
      ->set('alert.id', time())
      ->set('alert.headline', $form_state->getValue('headline'))
      ->set('alert.message', $form_state->getValue('message'))
      ->set('alert.status', $form_state->getValue('status'))
      ->set('alert.type', $form_state->getValue('type'))
      ->set('alert.link_title', $form_state->getValue('link_title'))
      ->set('alert.link_url', $linkUrl)
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
  public function updateAlertDescriptionWrapperCallback(array $form, FormStateInterface $form_state) {
    return $form['type_description_wrapper'];
  }

  /**
   * Check that a submitted value starts with a slash or is an external link.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the parent form.
   * @param string $fieldId
   *   The id of a field on the config form.
   */
  protected function validateUrl(FormStateInterface &$form_state, string $fieldId) {
    if ($value = $form_state->getValue($fieldId)) {
      if (!str_starts_with($value, '/') && !str_starts_with($value, 'http')) {
        $form_state->setErrorByName(
        $fieldId,
        $this->t(
          "The path '%path' has to start with a '/' for internal links or 'https://' for external links.",
         ['%path' => $form_state->getValue($fieldId)]
        )
        );
      }
    }
  }

}
