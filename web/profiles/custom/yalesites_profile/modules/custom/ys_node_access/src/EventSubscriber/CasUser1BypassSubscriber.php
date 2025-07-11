<?php

namespace Drupal\ys_node_access\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event subscriber to allow user 1 to bypass CAS forced login.
 */
class CasUser1BypassSubscriber implements EventSubscriberInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new CasUser1BypassSubscriber.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(AccountInterface $current_user, ConfigFactoryInterface $config_factory) {
    $this->currentUser = $current_user;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Run with priority 30, higher than CAS forced auth subscriber (29).
    $events[KernelEvents::REQUEST][] = ['onRequest', 30];
    return $events;
  }

  /**
   * Handles the request event.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The event to process.
   */
  public function onRequest(RequestEvent $event) {
    // Only handle master requests.
    if ($event->getRequestType() !== HttpKernelInterface::MASTER_REQUEST) {
      return;
    }

    // Only bypass for user 1.
    if ($this->currentUser->id() != 1) {
      return;
    }

    $request = $event->getRequest();
    $config = $this->configFactory->getEditable('cas.settings');

    // Check if forced login is enabled.
    if (!$config->get('forced_login.enabled')) {
      return;
    }

    // Temporarily disable forced login for this request for user 1.
    $config->set('forced_login.enabled', FALSE);
  }

}