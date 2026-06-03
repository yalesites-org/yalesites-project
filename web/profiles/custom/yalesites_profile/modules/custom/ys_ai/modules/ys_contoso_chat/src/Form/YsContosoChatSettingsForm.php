<?php

namespace Drupal\ys_contoso_chat\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for the Yale Chat module.
 */
class YsContosoChatSettingsForm extends ConfigFormBase {

  const CONFIG_NAME = 'ys_contoso_chat.settings';

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
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

    parent::submitForm($form, $form_state);
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
