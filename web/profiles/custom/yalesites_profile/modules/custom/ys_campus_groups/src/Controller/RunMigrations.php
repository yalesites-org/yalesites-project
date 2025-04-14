<?php

namespace Drupal\ys_campus_groups\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\ys_campus_groups\CampusGroupsManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Runs Campus Groups migrations on request.
 */
class RunMigrations extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $campusGroupsConfig;

  /**
   * The Campus Groups Manager.
   *
   * @var \Drupal\ys_campus_groups\CampusGroupsManager
   */
  protected $campusGroupsManager;

  /**
   * Drupal messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Drupal migration manager.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManager
   */
  protected $migrationManager;

  /**
   * Drupal time interface.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Drupal module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new RunMigrations object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    CampusGroupsManager $campus_groups_manager,
    MessengerInterface $messenger,
    RequestStack $request_stack,
  ) {
    $this->campusGroupsConfig = $config_factory->get('ys_campus_groups.settings');
    $this->campusGroupsManager = $campus_groups_manager;
    $this->messenger = $messenger;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('ys_campus_groups.manager'),
      $container->get('messenger'),
      $container->get('request_stack'),
    );
  }

  /**
   * Runs all Localist migrations.
   */
  public function runAllMigrations() {
    if ($this->campusGroupsConfig->get('enable_campus_groups_sync')) {
      $messageData = $this->campusGroupsManager->runAllMigrations();
      if ($messageData) {
        $eventsImported = $messageData['campus_groups_events']['imported'];
        $message = "Synchronized $eventsImported events.";
        $this->messenger()->addStatus($message);
      }
    }
    else {
      $message = 'Campus Groups syncing is not enabled.';
      $this->messenger()->addError($message);
    }

    $redirectUrl = $this->getRedirectUrl();
    $response = new RedirectResponse($redirectUrl);
    return $response;
  }

  /**
   * Retrieves the URL to redirect to after the migration is run.
   *
   * @return string
   *   The URL to redirect to.
   */
  protected function getRedirectUrl(): string {
    $referer = $this->requestStack->getCurrentRequest()->server->get('HTTP_REFERER');

    if ($referer == NULL) {
      $referer = Url::fromRoute('<front>')->toString();
    }

    return $referer;
  }

}
