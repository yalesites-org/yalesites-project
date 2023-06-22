<?php

namespace Drupal\ys_node_access\EventSubscriber;

use Drupal\Core\EventSubscriber\HttpExceptionSubscriberBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Overrides the 403 response to perform a CAS redirect for specific pages.
 */
class NodeAccessEventSubscriber extends HttpExceptionSubscriberBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The construct for this class.
   *
   * @param Drupal\Core\Session\AccountInterface $current_user
   *   The current user service.
   */
  public function __construct(AccountInterface $current_user) {
    $this->currentUser = $current_user;
  }

  /**
   * Request format that will be handled by this subscriber.
   */
  protected function getHandledFormats() {
    return ['html'];
  }

  /**
   * Manage the 403 exceptions.
   *
   * @param Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The Incoming event.
   */
  public function on403(ExceptionEvent $event) {
    if ($this->currentUser->isAnonymous()) {

      // Make sure this is a node.
      if ($node = $event->getRequest()->attributes->get('node')) {
        /* Check to see if node is set to require login only.
         * If so, redirect to CAS login.
         */
        if ($node->hasField('field_login_required')) {
          if ($node->isPublished() && $node->field_login_required->first()->getValue()['value']) {
            $casRedirectUrl = Url::fromRoute('cas.login', ['destination' => $node->toUrl()->toString()])->toString();
            $returnResponse = new TrustedRedirectResponse($casRedirectUrl);
            $returnResponse->getCacheableMetadata()->setCacheMaxAge(0);
            $event->setResponse($returnResponse);
          }
        }
      }
    }
  }

}
