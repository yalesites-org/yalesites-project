<?php

namespace Drupal\ys_contoso_chat\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ys_ai\Service\BeaconIndexProvisioner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for the Yale Chat module.
 */
class YsContosoChatSettingsForm extends ConfigFormBase {

  const CONFIG_NAME = 'ys_contoso_chat.settings';

  /**
   * The Search API server entity id that backs the Beacon chatbot.
   */
  const BEACON_SERVER_ID = 'beacon';

  /**
   * The Search API index entity id that backs the Beacon chatbot.
   */
  const BEACON_INDEX_ID = 'beacon_index';

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ?TypedConfigManagerInterface $typed_config_manager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected BeaconIndexProvisioner $beaconIndexProvisioner,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('ys_ai.beacon_index_provisioner'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ys_contoso_chat_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG_NAME);

    $form['enable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable chat widget'),
      '#default_value' => $config->get('enable'),
    ];

    $is_user_1 = ($this->currentUser()->id() == 1);

    if ($is_user_1) {
      $form['assistant_id'] = [
        '#type' => 'select',
        '#title' => $this->t('AI Assistant'),
        '#description' => $this->t('Select the AI Assistant entity to handle chat requests. Configure assistants at <a href="/admin/config/ai/assistants">AI Assistants</a>.'),
        '#options' => $this->getAssistantOptions(),
        '#empty_option' => $this->t('-- Select --'),
        '#default_value' => $config->get('assistant_id'),
        '#required' => TRUE,
      ];
    }
    else {
      $options = $this->getAssistantOptions();
      $current = $config->get('assistant_id');
      $form['assistant_id'] = [
        '#type' => 'item',
        '#title' => $this->t('AI Assistant'),
        '#markup' => $options[$current] ?? $this->t('None selected'),
      ];
    }

    $form['floating_button'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show floating launch button'),
      '#default_value' => $config->get('floating_button'),
    ];

    $form['floating_button_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Floating button label'),
      '#default_value' => $config->get('floating_button_text') ?? 'Ask Beacon',
      '#states' => [
        'visible' => [
          ':input[name="floating_button"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['floating_button_icon'] = [
      '#type' => 'select',
      '#title' => $this->t('Floating button icon'),
      '#description' => $this->t('Select the icon to display on the floating chat button. Changes take effect immediately after saving.'),
      '#options' => [
        'fa-comments' => $this->t('Chat (default)'),
        'fa-sparkles' => $this->t('Sparkles'),
      ],
      '#default_value' => $config->get('floating_button_icon') ?? 'fa-comments',
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="floating_button"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['prompts'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Initial Prompt Suggestions'),
      '#description' => $this->t('Example prompts shown when the chat is first opened. Up to four.'),
      '#tree' => TRUE,
    ];
    for ($i = 0; $i < 4; $i++) {
      $form['prompts'][$i] = [
        '#type' => 'textfield',
        '#title' => $this->t('Prompt @n', ['@n' => $i + 1]),
        '#default_value' => $config->get('initial_questions')[$i] ?? '',
      ];
    }

    $form['disclaimer'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Disclaimer'),
      '#description' => $this->t('Appears below the chat input. Limited HTML allowed (e.g. links).'),
      '#default_value' => $config->get('disclaimer') ?? '',
      '#format' => 'restricted_html',
      '#allowed_formats' => ['restricted_html'],
    ];

    $form['footer'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Footer'),
      '#description' => $this->t('Displays at the bottom of the chat modal. Limited HTML allowed (e.g. links).'),
      '#default_value' => $config->get('footer') ?? '',
      '#format' => 'restricted_html',
      '#allowed_formats' => ['restricted_html'],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(self::CONFIG_NAME)
      ->set('enable', (bool) $form_state->getValue('enable'))
      ->set('initial_questions', array_values(array_filter($form_state->getValue('prompts'))))
      ->set('disclaimer', $form_state->getValue('disclaimer')['value'])
      ->set('footer', $form_state->getValue('footer')['value'])
      ->set('floating_button', (bool) $form_state->getValue('floating_button'))
      ->set('floating_button_text', $form_state->getValue('floating_button_text'))
      ->set('floating_button_icon', $form_state->getValue('floating_button_icon'));

    if ($this->currentUser()->id() == 1) {
      $config->set('assistant_id', $form_state->getValue('assistant_id'));
    }

    $config->save();

    if ((bool) $form_state->getValue('enable')) {
      $this->enableBeaconStack();
    }
    else {
      $this->disableBeaconStack();
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Provisions and enables the Beacon Search API stack for the chatbot.
   *
   * Ensures the Azure AI Search index the chatbot queries exists (the
   * provisioner only creates it if missing, so this is idempotent), then
   * enables the Search API server and index and queues a full reindex.
   * Enabling the server does not re-enable its indexes, so the index is
   * enabled explicitly. If provisioning fails, the stack is left disabled
   * because there is no remote index to write to.
   */
  protected function enableBeaconStack(): void {
    $result = $this->beaconIndexProvisioner->ensureIndexExists();
    if ($result->isFailure()) {
      $this->messenger()->addError($result->getMessage());
      return;
    }
    $this->messenger()->addStatus($result->getMessage());

    $server = $this->entityTypeManager->getStorage('search_api_server')->load(self::BEACON_SERVER_ID);
    if ($server && !$server->status()) {
      $server->setStatus(TRUE)->save();
    }

    $index = $this->entityTypeManager->getStorage('search_api_index')->load(self::BEACON_INDEX_ID);
    if ($index) {
      if (!$index->status()) {
        $index->setStatus(TRUE)->save();
      }
      $index->reindex();
    }
  }

  /**
   * Disables the Beacon Search API stack so indexing and embeddings stop.
   *
   * Disabling the server cascades to disabling its indexes (see
   * search_api Server::preSave()), so the Azure index is left untouched and
   * idle storage is preserved for a smooth re-enable.
   */
  protected function disableBeaconStack(): void {
    $server = $this->entityTypeManager->getStorage('search_api_server')->load(self::BEACON_SERVER_ID);
    if ($server && $server->status()) {
      $server->setStatus(FALSE)->save();
    }
  }

  /**
   * Returns a keyed array of available AiAssistant entity options.
   */
  protected function getAssistantOptions(): array {
    $options = [];
    $assistants = $this->entityTypeManager->getStorage('ai_assistant')->loadMultiple();
    foreach ($assistants as $assistant) {
      $options[$assistant->id()] = $assistant->label();
    }
    return $options;
  }

}
