<?php

namespace Drupal\ys_node_access\EventSubscriber;

use Drupal\Core\EventSubscriber\HttpExceptionSubscriberBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
      // Get path alias.
      $path = $event->getRequest()->getPathInfo();

      // Convert path alias into node object.
      $internalPath = \Drupal::service('path_alias.manager')->getPathByAlias($path);
      $params = Url::fromUri("internal:" . $internalPath)->getRouteParameters();
      $entityType = key($params);

      // Make sure this is a node.
      if ($entityType == 'node') {
        /** @var \Drupal\node\Entity $node */
        $node = \Drupal::entityTypeManager()->getStorage($entityType)->load($params[$entityType]);

        /* Check to see if node is set to require login only.
         * If so, redirect to CAS login.
         */
        if ($node->hasField('field_login_required')) {
          if ($node->get('field_login_required')->getValue()[0]['value']) {
            $casRedirectUrl = Url::fromRoute('cas.login', ['destination' => $path])->toString();
            $returnResponse = new RedirectResponse($casRedirectUrl);
            $event->setResponse($returnResponse);
          }
        }
      }
    }
  }

}
