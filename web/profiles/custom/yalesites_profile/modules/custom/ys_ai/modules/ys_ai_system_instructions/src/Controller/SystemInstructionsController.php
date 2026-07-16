<?php

namespace Drupal\ys_ai_system_instructions\Controller;

use Drupal\ys_ai_system_instructions\Service\SystemInstructionsManagerService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\user\UserStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for system instructions management.
 */
class SystemInstructionsController extends ControllerBase {

  /**
   * The system instructions manager.
   *
   * @var \Drupal\ys_ai_system_instructions\Service\SystemInstructionsManagerService
   */
  protected $instructionsManager;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The user storage.
   *
   * @var \Drupal\user\UserStorageInterface
   */
  protected $userStorage;

  /**
   * Constructs a SystemInstructionsController.
   *
   * @param \Drupal\ys_ai_system_instructions\Service\SystemInstructionsManagerService $instructions_manager
   *   The system instructions manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   * @param \Drupal\user\UserStorageInterface $user_storage
   *   The user storage.
   */
  public function __construct(SystemInstructionsManagerService $instructions_manager, DateFormatterInterface $date_formatter, UserStorageInterface $user_storage) {
    $this->instructionsManager = $instructions_manager;
    $this->dateFormatter = $date_formatter;
    $this->userStorage = $user_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ys_ai_system_instructions.manager'),
      $container->get('date.formatter'),
      $container->get('entity_type.manager')->getStorage('user')
    );
  }

  /**
   * Display system instructions version history.
   *
   * @return array
   *   Render array for the version history page.
   */
  public function versionHistory() {
    // Check if the feature is enabled.
    $config = $this->config('ys_ai_system_instructions.settings');
    if (!$config->get('system_instructions_enabled')) {
      throw new AccessDeniedHttpException('System instruction modification is not enabled.');
    }

    // Use pagination with 25 items per page.
    $versions = $this->instructionsManager->getAllVersions(TRUE, 25);
    $stats = $this->instructionsManager->getVersionStats();

    $build = [];

    // Summary information.
    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['system-instructions-summary']],
    ];

    $build['summary']['stats'] = [
      '#type' => 'item',
      '#markup' => $this->t('Total versions: @total | Active version: @active | Last updated: @date', [
        '@total' => $stats['total_versions'],
        '@active' => $stats['active_version'],
        '@date' => $stats['active_created'] ? $this->dateFormatter->format($stats['active_created']) : $this->t('Never'),
      ]),
    ];

    if (empty($versions)) {
      $build['empty'] = [
        '#markup' => $this->t('No system instructions found. <a href="@url">Create the first version</a>.', [
          '@url' => Url::fromRoute('ys_ai_system_instructions.form')->toString(),
        ]),
      ];
      return $build;
    }

    // Batch load all users to avoid N+1 query problem.
    $user_ids = array_unique(array_column($versions, 'created_by'));
    $users = $this->userStorage->loadMultiple($user_ids);

    // Build the version history table.
    $header = [
      $this->t('Version'),
      $this->t('Created'),
      $this->t('Author'),
      $this->t('Status'),
      $this->t('Notes'),
      $this->t('Actions'),
    ];

    $rows = [];
    foreach ($versions as $version) {
      // Use batch-loaded users instead of individual load() calls.
      $user = $users[$version['created_by']] ?? NULL;
      $username = $user ? $user->getDisplayName() : $this->t('Unknown');

      if ($version['created_by'] == 1) {
        $username = $this->t('System (API Sync)');
      }

      $actions = [];

      if (!$version['is_active']) {
        $actions['revert'] = [
          'title' => $this->t('Revert'),
          'url' => Url::fromRoute('ys_ai_system_instructions.revert', ['version' => $version['version']]),
          'attributes' => ['class' => ['button', 'button--small']],
        ];
      }

      $actions['view'] = [
        'title' => $this->t('View'),
        'url' => Url::fromRoute('ys_ai_system_instructions.view', ['version' => $version['version']]),
        'attributes' => ['class' => ['button', 'button--small']],
      ];

      $rows[] = [
        $version['version'],
        $this->dateFormatter->format($version['created_date']),
        $username,
        $version['is_active'] ? $this->t('Active') : $this->t('Inactive'),
        $version['notes'] ?: $this->t('No notes'),
        [
          'data' => [
            '#type' => 'operations',
            '#links' => $actions,
          ],
        ],
      ];
    }

    $build['versions'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No versions found.'),
      '#attributes' => ['class' => ['system-instructions-history']],
    ];

    // Add pager.
    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

  /**
   * View a specific version of system instructions.
   *
   * @param int $version
   *   The version number.
   *
   * @return array
   *   Render array for the version view page.
   */
  public function viewVersion(int $version) {
    // Check if the feature is enabled.
    $config = $this->config('ys_ai_system_instructions.settings');
    if (!$config->get('system_instructions_enabled')) {
      throw new AccessDeniedHttpException('System instruction modification is not enabled.');
    }

    // Get the specific version with full data including instructions.
    $current_version = $this->instructionsManager->getStorageService()->getVersion($version);

    if (!$current_version) {
      $build['error'] = [
        '#markup' => $this->t('Version @version not found.', ['@version' => $version]),
      ];
      return $build;
    }

    $user = $this->userStorage->load($current_version['created_by']);
    $username = $user ? $user->getDisplayName() : $this->t('Unknown');

    if ($current_version['created_by'] == 1) {
      $username = $this->t('System (API Sync)');
    }

    $build = [];

    $build['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['system-instructions-version-header']],
    ];

    $build['header']['info'] = [
      '#type' => 'item',
      '#markup' => $this->t('<h2>Version @version</h2><p><strong>Created:</strong> @date<br><strong>Author:</strong> @author<br><strong>Status:</strong> @status<br><strong>Notes:</strong> @notes</p>', [
        '@version' => $current_version['version'],
        '@date' => $this->dateFormatter->format($current_version['created_date']),
        '@author' => $username,
        '@status' => $current_version['is_active'] ? $this->t('Active') : $this->t('Inactive'),
        '@notes' => $current_version['notes'] ?: $this->t('No notes'),
      ]),
    ];

    if (!$current_version['is_active']) {
      $build['header']['revert'] = [
        '#type' => 'link',
        '#title' => $this->t('Revert to this version'),
        '#url' => Url::fromRoute('ys_ai_system_instructions.revert', ['version' => $version]),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
          'onclick' => 'return confirm("' . $this->t('Are you sure you want to revert to version @version?', ['@version' => $version]) . '")',
        ],
      ];
    }

    $build['content'] = [
      '#type' => 'details',
      '#title' => $this->t('Instructions Content'),
      '#open' => TRUE,
    ];

    $build['content']['instructions'] = [
      '#type' => 'textarea',
      '#value' => $current_version['instructions'],
      '#rows' => 20,
      '#attributes' => ['readonly' => 'readonly'],
    ];

    $build['actions'] = [
      '#type' => 'actions',
    ];

    $build['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to version history'),
      '#url' => Url::fromRoute('ys_ai_system_instructions.versions'),
      '#attributes' => ['class' => ['button']],
    ];

    return $build;
  }

}
