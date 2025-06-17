<?php

namespace Drupal\ys_ai\Form;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai_engine_chat\Form\AiEngineChatSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for configuring the AI chat settings.
 */
class YsAiSettings extends AiEngineChatSettings implements ContainerInjectionInterface {

  /**
   * The cache render service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheRender;

  /**
   * The cache page service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cachePage;

  /**
   * Constructs a YsAiSettings object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_render
   *   The cache render service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_page
   *   The cache page service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    CacheBackendInterface $cache_render,
    CacheBackendInterface $cache_page,
  ) {
    parent::__construct($config_factory);
    $this->cacheRender = $cache_render;
    $this->cachePage = $cache_page;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('cache.render'),
      $container->get('cache.page'),
      $container->get('cache.discovery')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $chat_config = $this->config('ai_engine_chat.settings');
    $embedding_config = $this->config('ai_engine_embedding.settings');

    if ($chat_config->get('azure_base_url') != NULL) {
      $form['enable'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable chat widget'),
        '#default_value' => $chat_config->get('enable') ?? FALSE,
        '#description' => $this->t('Enable or disable chat service across the site. Chat can be launched by using the href="#launch-chat" on any link.'),
        '#weight' => -10,
      ];
      $form['floating_button'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable floating chat button'),
        '#default_value' => $chat_config->get('floating_button') ?? FALSE,
        '#weight' => -10,
      ];
      $form['floating_button_text'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Floating button text'),
        '#default_value' => $chat_config->get('floating_button_text') ?? $this->t('Ask Yale Chat'),
        '#required' => TRUE,
        '#weight' => -10,
      ];
    }

    if (
      $embedding_config->get('azure_embedding_service_url') != NULL &&
        $embedding_config->get('azure_search_service_name') != NULL &&
        $embedding_config->get('azure_search_service_index') != NULL
    ) {
      $form['enable_embedding'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable embedding services'),
        '#default_value' => $embedding_config->get('enable') ?? FALSE,
        '#description' => $this->t('Enable automatic updates of vector database.'),
        '#weight' => -11,
      ];
    }

    $form = parent::buildForm($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('ai_engine_chat.settings')
      ->set('enable', $form_state->getValue('enable'))
      ->set('floating_button', $form_state->getValue('floating_button'))
      ->set('floating_button_text', $form_state->getValue('floating_button_text'))
      ->save();
    $this->configFactory->getEditable('ai_engine_embedding.settings')
      ->set('enable', $form_state->getValue('enable_embedding'))
      ->save();

    // Render cache: Clears cached page/block renders so floating button
    // appears.
    $this->cacheRender->invalidateAll();

    // Page cache: Clears cached pages so changes are visible to anonymous
    // users.
    $this->cachePage->invalidateAll();

    parent::submitForm($form, $form_state);
  }

}
