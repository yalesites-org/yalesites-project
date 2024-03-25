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
   * Constructs a new RunMigrations object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LocalistManager $localist_manager,
    MessengerInterface $messenger,
    ) {
    $this->localistConfig = $config_factory->get('ys_localist.settings');
    $this->localistManager = $localist_manager;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('ys_localist.manager'),
      $container->get('messenger'),
    );
  }

  /**
   * Runs all Localist migrations.
   */
  public function runAllMigrations() {

    if ($this->localistConfig->get('enable_localist_sync')) {

      $messageData = $this->localistManager->runAllMigrations();
      $eventsImported = $messageData['localist_events']['imported'];
      $message = "Synchronized $eventsImported events.";
      $this->messenger()->addStatus($message);
    }
    else {
      $message = "Localist sync is not enabled. No sync was performed.";
      $this->messenger()->addError($message);
    }

    $redirectUrl = Url::fromRoute('ys_localist.settings')->toString();
    $response = new RedirectResponse($redirectUrl);
    return $response;

  }

}
