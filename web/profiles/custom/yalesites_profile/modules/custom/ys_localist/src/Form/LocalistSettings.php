<?php

namespace Drupal\ys_localist\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\CachedDiscoveryClearerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\taxonomy\Entity\Term;

/**
 * Defines the manage Localist settings interface.
 */
class LocalistSettings extends ConfigFormBase {

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
   *   The Cache Discovery interface.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    CacheBackendInterface $cacheRender,
    CachedDiscoveryClearerInterface $plugin_cache_clearer,
    ) {
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
      $container->get('plugin.cache_clearer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_localist_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ys_localist.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ys_localist.settings');

    $form['enable_localist_sync'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Localist sync'),
      '#description' => $this->t('Once enabled, Localist data will sync events for the selected group roughly every hour.'),
      '#default_value' => $config->get('enable_localist_sync') ?: FALSE,
    ];

    $form['localist_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Localist endpoint base URL'),
      '#description' => $this->t('Ex: https://yale.enterprise.localist.com'),
      '#default_value' => $config->get('localist_endpoint') ?: NULL,
    ];

    $term = Term::load($config->get('localist_group'));

    $form['localist_group'] = [
      '#title' => $this->t('Group to sync events'),
      '#type' => 'entity_autocomplete',
      '#target_type' => 'taxonomy_term',
      '#tags' => FALSE,
      '#default_value' => $term,
      '#selection_handler' => 'default',
      '#selection_settings' => [
        'target_bundles' => ['event_groups'],
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

    $this->configFactory->getEditable('ys_localist.settings')
      // Set the submitted configuration setting.
      ->set('enable_localist_sync', $form_state->getValue('enable_localist_sync'))
      ->set('localist_endpoint', rtrim($form_state->getValue('localist_endpoint'), "/"))
      ->set('localist_group', $form_state->getValue('localist_group'))
      ->save();

    // if ($this->cacheRender->get('config')) {
    //   Cache::invalidateTags(['block.block.alertblock']);
    // }
    // $this->cacheRender->invalidateAll();
    // $this->pluginCacheClearer->clearCachedDefinitions();
    parent::submitForm($form, $form_state);
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
