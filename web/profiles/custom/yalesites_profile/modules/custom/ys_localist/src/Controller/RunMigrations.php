<?php

namespace Drupal\ys_localist\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\ys_localist\LocalistManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Runs Localist migrations on request.
 */
class RunMigrations extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $localistConfig;

  /**
   * The Localist Manager.
   *
   * @var \Drupal\ys_localist\LocalistManager
   */
  protected $localistManager;

  /**
   * Drupal messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

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
    LocalistManager $localist_manager,
    MessengerInterface $messenger,
    RequestStack $request_stack,
  ) {
    $this->localistConfig = $config_factory->get('ys_localist.settings');
    $this->localistManager = $localist_manager;
    $this->messenger = $messenger;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
          $container->get('config.factory'),
          $container->get('ys_localist.manager'),
          $container->get('messenger'),
          $container->get('request_stack')
      );
  }

  /**
   * Runs all Localist migrations.
   */
  public function runAllMigrations() {

    if ($this->localistConfig->get('enable_localist_sync')) {

      $messageData = $this->localistManager->runAllMigrations();
      if ($messageData) {
        $eventsImported = $messageData['localist_events']['imported'];
        $message = "Synchronized $eventsImported events.";
        $this->messenger()->addStatus($message);
      }
    }
    else {
      $message = "Localist sync is not enabled. No sync was performed.";
      $this->messenger()->addError($message);
    }

    $redirectUrl = $this->getRedirectUrl();
    $response = new RedirectResponse($redirectUrl);
    return $response;

  }

  /**
   * Runs the group migration.
   */
  public function syncGroups() {

    if ($this->localistConfig->get('enable_localist_sync')) {
      // Check endpoint before running migration.
      if ($this->localistManager->checkGroupsEndpoint()) {
        $this->localistManager->runMigration('localist_groups');
        $this->localistManager->removeOldExperiences();
        $this->messenger()->addStatus('Successfully imported Localist groups.');
      }
      else {
        $this->messenger()->addError('Error getting groups. Check that the endpoint is correct.');
      }
    }
    else {
      $message = "Localist sync is not enabled. No sync was performed.";
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
