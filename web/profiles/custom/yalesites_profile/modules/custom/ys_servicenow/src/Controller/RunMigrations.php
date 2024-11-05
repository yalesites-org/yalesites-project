<?php

namespace Drupal\ys_servicenow\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\ys_servicenow\ServiceNowManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Runs ServiceNow migrations on request.
 */
class RunMigrations extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $servicenowConfig;

  /**
   * The ServiceNow Manager.
   *
   * @var \Drupal\ys_servicenow\ServiceNowManager
   */
  protected $servicenowManager;

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
    ServiceNowManager $servicenow_manager,
    MessengerInterface $messenger,
  ) {
    $this->servicenowConfig = $config_factory->get('ys_servicenow.settings');
    $this->servicenowManager = $servicenow_manager;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('ys_servicenow.manager'),
      $container->get('messenger'),
    );

  }

  /**
   * Runs all ServiceNow migrations.
   */
  public function runAllMigrations() {
    if ($this->servicenowConfig->get('enable_servicenow_sync')) {
      $this->messenger->addMessage('Running ServiceNow migrations...');
      $this->servicenowManager->runAllMigrations();
      $this->messenger->addMessage('ServiceNow migrations complete.');
    }
    else {
      $this->messenger->addMessage('ServiceNow sync is disabled.  No sync was performed.');
    }

    $redirectUrl = Url::fromRoute('ys_servicenow.settings')->toString();
    $response = new RedirectResponse($redirectUrl);
    return $response;
  }

}
