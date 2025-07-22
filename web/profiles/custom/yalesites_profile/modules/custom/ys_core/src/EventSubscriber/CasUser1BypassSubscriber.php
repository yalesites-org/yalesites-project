<?php

namespace Drupal\ys_core\EventSubscriber;

use Drupal\cas\Event\CasPreRedirectEvent;
use Drupal\cas\Service\CasHelper;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
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
    $request = $this->requestStack->getCurrentRequest();

    // Check if current user is user 1 OR if this is a user 1 login flow.
    $is_user_1 = ($this->currentUser->id() == 1);

    // Check if this is a user 1 one-time login link.
    $is_user_1_login = FALSE;
    if (preg_match('/^\/user\/reset\/1\//', $request->getPathInfo())) {
      $is_user_1_login = TRUE;
    }

    // Also check session for user 1 authentication.
    $session = $request->getSession();
    $is_user_1_session = FALSE;
    if ($session && $session->has('_drupal_uid') && $session->get('_drupal_uid') == 1) {
      $is_user_1_session = TRUE;
    }

    if ($is_user_1 || $is_user_1_login || $is_user_1_session) {
      // Get the redirect data from the event.
      $redirect_data = $event->getCasRedirectData();

      // Prevent the CAS redirect from occurring.
      $redirect_data->preventRedirection();

      // Stop propagation to prevent other subscribers from processing.
      $event->stopPropagation();
    }
  }

}
