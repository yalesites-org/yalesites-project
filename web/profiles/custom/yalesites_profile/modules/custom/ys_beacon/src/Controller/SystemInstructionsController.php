<?php

namespace Drupal\ys_beacon\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\user\UserStorageInterface;
use Drupal\ys_beacon\Service\SystemInstructionsStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the Beacon system instructions version history.
 */
class SystemInstructionsController extends ControllerBase {

  /**
   * The system instructions storage.
   *
   * @var \Drupal\ys_beacon\Service\SystemInstructionsStorage
   */
  protected SystemInstructionsStorage $storage;

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
   * @param \Drupal\ys_beacon\Service\SystemInstructionsStorage $storage
   *   The system instructions storage.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   * @param \Drupal\user\UserStorageInterface $user_storage
   *   The user storage.
   */
  public function __construct(SystemInstructionsStorage $storage, DateFormatterInterface $date_formatter, UserStorageInterface $user_storage) {
    $this->storage = $storage;
    $this->dateFormatter = $date_formatter;
    $this->userStorage = $user_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ys_beacon.system_instructions_storage'),
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
    $versions = $this->storage->getAllVersions(TRUE);
    $active = $this->storage->getActiveInstructions();

    $build = [];
    $build['#attached']['library'][] = 'ys_beacon/system_instructions';

    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['system-instructions-summary']],
    ];

    $build['summary']['stats'] = [
      '#type' => 'item',
      '#markup' => $this->t('Total versions: @total | Active version: @active | Last updated: @date', [
        '@total' => $this->storage->getVersionCount(),
        '@active' => $active ? $active['version'] : $this->t('None'),
        '@date' => $active ? $this->dateFormatter->format($active['created_date']) : $this->t('Never'),
      ]),
    ];

    if (empty($versions)) {
      $build['empty'] = [
        '#markup' => $this->t('No system instructions found. <a href="@url">Create the first version</a>.', [
          '@url' => Url::fromRoute('ys_beacon.instructions')->toString(),
        ]),
      ];
      return $build;
    }

    // Batch load all users to avoid N+1 query problem.
    $user_ids = array_unique(array_column($versions, 'created_by'));
    $users = $this->userStorage->loadMultiple($user_ids);

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
      $user = $users[$version['created_by']] ?? NULL;
      $username = $user ? $user->getDisplayName() : $this->t('Unknown');

      $actions = [];

      if (!$version['is_active']) {
        $actions['revert'] = [
          'title' => $this->t('Revert'),
          'url' => Url::fromRoute('ys_beacon.instructions_revert', ['version' => $version['version']]),
          'attributes' => ['class' => ['button', 'button--small']],
        ];
      }

      $actions['view'] = [
        'title' => $this->t('View'),
        'url' => Url::fromRoute('ys_beacon.instructions_view', ['version' => $version['version']]),
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
      '#attributes' => ['class' => ['system-instructions-history']],
    ];

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
    $current_version = $this->storage->getVersion($version);

    if (!$current_version) {
      $build['error'] = [
        '#markup' => $this->t('Version @version not found.', ['@version' => $version]),
      ];
      return $build;
    }

    $user = $this->userStorage->load($current_version['created_by']);
    $username = $user ? $user->getDisplayName() : $this->t('Unknown');

    $build = [];
    $build['#attached']['library'][] = 'ys_beacon/system_instructions';

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
        '#url' => Url::fromRoute('ys_beacon.instructions_revert', ['version' => $version]),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
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
      '#url' => Url::fromRoute('ys_beacon.instructions_versions'),
      '#attributes' => ['class' => ['button']],
    ];

    return $build;
  }

}
