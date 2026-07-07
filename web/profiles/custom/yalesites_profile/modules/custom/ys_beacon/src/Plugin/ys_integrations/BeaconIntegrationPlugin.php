<?php

namespace Drupal\ys_beacon\Plugin\ys_integrations;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ys_beacon\Service\SystemInstructionsStorage;
use Drupal\ys_integrations\Attribute\Integration;
use Drupal\ys_integrations\IntegrationPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Beacon AI chat integration plugin.
 */
#[Integration(
  id: 'ys_beacon',
  label: new TranslatableMarkup('Beacon (AI Chat)'),
  description: new TranslatableMarkup('Provides an AI chat assistant answering questions from site content.'),
)]
class BeaconIntegrationPlugin extends IntegrationPluginBase {

  /**
   * The system instructions storage.
   *
   * @var \Drupal\ys_beacon\Service\SystemInstructionsStorage
   */
  protected SystemInstructionsStorage $instructionsStorage;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Constructs a new BeaconIntegrationPlugin object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param array $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\ys_beacon\Service\SystemInstructionsStorage $instructions_storage
   *   The system instructions storage.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   */
  public function __construct(ConfigFactoryInterface $config_factory, array $plugin_definition, AccountInterface $current_user, SystemInstructionsStorage $instructions_storage, DateFormatterInterface $date_formatter) {
    parent::__construct($config_factory, $plugin_definition, $current_user);
    $this->instructionsStorage = $instructions_storage;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('config.factory'),
      $plugin_definition,
      $container->get('current_user'),
      $container->get('ys_beacon.system_instructions_storage'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isTurnedOn(): bool {
    // The Beacon card must always be actionable: admins reach the Configure and
    // Manage Instructions screens from here to enable chat and set the index
    // name, so the card can never gate itself off.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function configUrl() {
    return Url::fromRoute('ys_beacon.settings');
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
    $form = [];

    $form['title'] = $this->pluginDefinition['label'];
    $form['description'] = $this->pluginDefinition['description'];

    $configUrl = $this->configUrl();
    $configUrlAccess = $configUrl->access($this->currentUser);

    if ($this->isTurnedOn()) {
      $instructionsUrl = Url::fromRoute('ys_beacon.instructions');
      $instructionsAccess = $instructionsUrl->access($this->currentUser);

      // A storage failure (e.g. table missing mid-install) must not take
      // down the whole integrations dashboard; skip the stats instead.
      try {
        $active = $this->instructionsStorage->getActiveInstructions();
        $form['status'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['integration-status']],
          '#access' => $instructionsAccess,
        ];
        $form['status']['version_info'] = [
          '#markup' => $active
            ? $this->t('<p><strong>System instructions version:</strong> @version (@date) | <strong>Total versions:</strong> @total</p>', [
              '@version' => $active['version'],
              '@date' => $this->dateFormatter->format($active['created_date'], 'short'),
              '@total' => $this->instructionsStorage->getVersionCount(),
            ])
            : $this->t('<p><strong>System instructions version:</strong> None | <strong>Total versions:</strong> @total</p>', [
              '@total' => $this->instructionsStorage->getVersionCount(),
            ]),
        ];
      }
      catch (\Throwable $e) {
        // No stats; the card still renders its actions.
      }

      $form['#actions']['configure'] = [
        '#type' => 'link',
        '#title' => $this->t('Configure'),
        '#url' => $configUrl,
        '#access' => $configUrlAccess,
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
      $form['#actions']['manage_instructions'] = [
        '#type' => 'link',
        '#title' => $this->t('Manage Instructions'),
        '#url' => $instructionsUrl,
        '#access' => $instructionsAccess,
        '#attributes' => ['class' => ['button']],
      ];
    }
    else {
      $form['#actions']['not_configured'] = [
        '#markup' => '<p>' . $this->t('This integration is not configured.') . '</p>',
      ];
    }

    return $form;
  }

}
