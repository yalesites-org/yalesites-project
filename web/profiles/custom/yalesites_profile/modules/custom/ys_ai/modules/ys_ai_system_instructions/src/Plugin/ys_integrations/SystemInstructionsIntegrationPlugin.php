<?php

namespace Drupal\ys_ai_system_instructions\Plugin\ys_integrations;

use Drupal\ys_integrations\IntegrationPluginBase;
use Drupal\ys_integrations\Attribute\Integration;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ys_ai_system_instructions\Service\SystemInstructionsManagerService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides a System Instructions integration plugin.
 */
#[Integration(
  id: 'ys_ai_system_instructions',
  label: new TranslatableMarkup('AI System Instructions'),
  description: new TranslatableMarkup('Manage AI system instructions with versioning and API synchronization.'),
)]
class SystemInstructionsIntegrationPlugin extends IntegrationPluginBase {

  /**
   * The system instructions manager service.
   *
   * @var \Drupal\ys_ai_system_instructions\Service\SystemInstructionsManagerService
   */
  protected $instructionsManager;

  /**
   * Constructs a new SystemInstructionsIntegrationPlugin object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param array $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\ys_ai_system_instructions\Service\SystemInstructionsManagerService $instructions_manager
   *   The system instructions manager service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, array $plugin_definition, AccountInterface $current_user, SystemInstructionsManagerService $instructions_manager) {
    parent::__construct($config_factory, $plugin_definition, $current_user);
    $this->instructionsManager = $instructions_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('config.factory'),
      $plugin_definition,
      $container->get('current_user'),
      $container->get('ys_ai_system_instructions.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isTurnedOn(): bool {
    $config = $this->configFactory->get('ys_ai_system_instructions.settings');

    // Check if system instructions are enabled.
    if (!$config->get('system_instructions_enabled')) {
      return FALSE;
    }

    // Verify all required API configuration is present.
    $required_settings = [
      'system_instructions_api_endpoint',
      'system_instructions_web_app_name',
      'system_instructions_api_key',
    ];

    foreach ($required_settings as $setting) {
      if (empty($config->get($setting))) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function configUrl() {
    return Url::fromRoute('ys_ai_system_instructions.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function syncUrl() {
    return Url::fromRoute('ys_ai_system_instructions.form');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // Only show the integration card if fully configured.
    if (!$this->isTurnedOn()) {
      return [];
    }

    $form = [];

    $form['title'] = $this->pluginDefinition['label'];
    $form['description'] = $this->pluginDefinition['description'];

    $configUrl = $this->configUrl();
    $syncUrl = $this->syncUrl();
    $configUrlAccess = $configUrl->access($this->currentUser);
    $syncUrlAccess = $syncUrl->access($this->currentUser);

    // Get version statistics and current status.
    try {
      $stats = $this->instructionsManager->getVersionStats();
      $current = $this->instructionsManager->getCurrentInstructions();

      $form['status'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['integration-status']],
      ];

      $form['status']['version_info'] = [
        '#markup' => '<p><strong>' . $this->t('Current version:') . '</strong> ' .
        ($current['version'] ?: $this->t('None')) .
        ' | <strong>' . $this->t('Total versions:') . '</strong> ' .
        $stats['total_versions'] . '</p>',
      ];

      if (!$current['synced']) {
        $form['status']['sync_warning'] = [
          '#markup' => '<div class="messages messages--warning">' .
          $this->t('Warning: API sync issues detected') . '</div>',
        ];
      }

      $form['#actions']['manage'] = [
        '#type' => 'link',
        '#title' => $this->t('Manage Instructions'),
        '#url' => $syncUrl,
        '#access' => $syncUrlAccess,
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];

      $form['#actions']['configure'] = [
        '#type' => 'link',
        '#title' => $this->t('Configure'),
        '#url' => $configUrl,
        '#access' => $configUrlAccess,
        '#attributes' => ['class' => ['button']],
      ];

      $form['#actions']['history'] = [
        '#type' => 'link',
        '#title' => $this->t('Version History'),
        '#url' => Url::fromRoute('ys_ai_system_instructions.versions'),
        '#access' => $syncUrlAccess,
        '#attributes' => ['class' => ['button']],
      ];

    }
    catch (\Exception $e) {
      $form['error'] = [
        '#markup' => '<div class="messages messages--error">' .
        $this->t('Error loading system instructions status: @error', ['@error' => $e->getMessage()]) .
        '</div>',
      ];

      $form['#actions']['configure'] = [
        '#type' => 'link',
        '#title' => $this->t('Configure'),
        '#url' => $configUrl,
        '#access' => $configUrlAccess,
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save($form, $form_state): void {
    // No specific save functionality needed for this integration.
    // Configuration is handled by the settings form.
  }

}
