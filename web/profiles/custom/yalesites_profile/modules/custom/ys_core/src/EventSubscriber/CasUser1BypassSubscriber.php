<?php

namespace Drupal\ys_core\EventSubscriber;

use Drupal\cas\Event\CasPreRedirectEvent;
use Drupal\cas\Service\CasHelper;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a user 1 bypass for CAS forced login.
 */
class CasUser1BypassSubscriber implements EventSubscriberInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new CasUser1BypassSubscriber object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(AccountInterface $current_user, RequestStack $request_stack) {
    $this->currentUser = $current_user;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[CasHelper::EVENT_PRE_REDIRECT][] = ['onPreRedirect', 100];
    return $events;
  }

  /**
   * Prevents CAS redirect for user 1.
   *
   * @param \Drupal\cas\Event\CasPreRedirectEvent $event
   *   The CAS pre-redirect event.
   */
  public function onPreRedirect(CasPreRedirectEvent $event) {
    // Check if current user is user 1.
    if ($this->currentUser->id() == 1) {
      // Get the current request to determine destination.
      $request = $this->requestStack->getCurrentRequest();
      
      // Get destination from query parameters or default to homepage.
      $destination = $request->query->get('destination', '/');
      
      // Create a redirect response to bypass CAS.
      $response = new TrustedRedirectResponse($destination);
      
      // Set the response in the event to prevent the CAS redirect.
      $event->setResponse($response);
      
      // Stop propagation to prevent other subscribers from processing.
      $event->stopPropagation();
    }
  }

}