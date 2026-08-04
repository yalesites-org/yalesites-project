<?php

namespace Drupal\ys_ai\Plugin\ys_integrations;

use Drupal\ys_integrations\IntegrationPluginBase;
use Drupal\ys_integrations\Attribute\Integration;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ys_ai\BeaconSupersession;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
* Provides an AI integration plugin.
*/
#[Integration(
  id: 'ys_ai',
  label: new TranslatableMarkup('AI'),
  description: new TranslatableMarkup('Provides integration with the AI engine.'),
)]
class AiIntegrationPlugin extends IntegrationPluginBase {

  /**
   * The legacy AI supersession service.
   *
   * @var \Drupal\ys_ai\BeaconSupersession
   */
  protected BeaconSupersession $supersession;

  /**
   * Constructs a new AiIntegrationPlugin object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param array $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\ys_ai\BeaconSupersession $supersession
   *   The legacy AI supersession service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, array $plugin_definition, AccountInterface $current_user, BeaconSupersession $supersession) {
    parent::__construct($config_factory, $plugin_definition, $current_user);
    $this->supersession = $supersession;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('config.factory'),
      $plugin_definition,
      $container->get('current_user'),
      $container->get('ys_ai.beacon_supersession'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isTurnedOn(): bool {
    if ($this->supersession->isSuperseded()) {
      return FALSE;
    }

    $config = $this->configFactory->get('ai_engine_chat.settings');
    return $config->get('azure_base_url') != NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function configUrl() {
    return Url::fromRoute('ys_ai.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function syncUrl() {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // Render no card at all once Beacon supersedes this integration, rather
    // than the "not configured" card, which would still advertise a retired
    // integration. Checked here as well as in isTurnedOn() because an
    // unconfigured-but-not-superseded site keeps showing that card.
    if ($this->supersession->isSuperseded()) {
      return [];
    }

    $form = [];

    $form['title'] = $this->pluginDefinition['label'];
    $form['description'] = $this->pluginDefinition['description'];

    $configUrl = $this->configUrl();
    $configUrlAccess = $configUrl->access($this->currentUser);

    if ($this->isTurnedOn()) {
      $form['#actions']['configure'] = [
        '#type' => 'link',
        '#title' => $this->t('Configure'),
        '#url' => $configUrl,
        '#access' => $configUrlAccess,
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }
    else {
      $form['#actions']['not_configured'] = [
        '#markup' => '<p>' . $this->t('This integration is not configured.') . '</p>',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save($form, $form_state): void {
  }

}
