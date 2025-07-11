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
   *   The current user service.
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
  public static function getSubscribedEvents(): array {
    // Run with priority 30, which is higher than CasForcedAuthSubscriber (29)
    // but after important services like RouterListener (32) and
    // MaintenanceModeSubscriber (30).
    $events[KernelEvents::REQUEST][] = ['onRequest', 30];
    return $events;
  }

  /**
   * Respond to kernel request to allow user 1 to bypass forced login.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The event.
   */
  public function onRequest(RequestEvent $event) {
    // Don't do anything if this is a sub request and not a master request.
    if ($event->getRequestType() != HttpKernelInterface::MASTER_REQUEST) {
      return;
    }

    // Check if current user is user 1.
    if ($this->currentUser->id() == 1) {
      // Temporarily disable forced login for user 1 by modifying the config.
      $config = $this->configFactory->getEditable('cas.settings');
      $original_enabled = $config->get('forced_login.enabled');

      if ($original_enabled) {
        // Store the original state and disable forced login for this request.
        $event->getRequest()->attributes->set('cas_user1_bypass', TRUE);
        $event->getRequest()->attributes->set('cas_original_forced_login', $original_enabled);
        $config->set('forced_login.enabled', FALSE);
      }
    }
  }

}
