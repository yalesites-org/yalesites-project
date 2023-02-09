<?php

namespace Drupal\ys_node_access\EventSubscriber;

use Drupal\Core\EventSubscriber\HttpExceptionSubscriberBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Drupal\path_alias\AliasManager;

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
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManager
   */
  protected $pathAlias;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * The construct for this class.
   *
   * @param Drupal\Core\Session\AccountInterface $current_user
   *   The current user service.
   * @param Drupal\path_alias\AliasManager $path_alias
   *   The path alias manager.
   */
  public function __construct(AccountInterface $current_user, AliasManager $path_alias) {
    $this->currentUser = $current_user;
    $this->pathAlias = $path_alias;
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

      // Make sure this is a node.
      if ($node = $event->getRequest()->attributes->get('node')) {

        /* Check to see if node is set to require login only.
         * If so, redirect to CAS login.
         */
        if ($node->hasField('field_login_required')) {
          if (!$node->field_login_required->isEmpty()) {
            $casRedirectUrl = Url::fromRoute('cas.login', ['destination' => $path])->toString();
            $returnResponse = new RedirectResponse($casRedirectUrl);
            $event->setResponse($returnResponse);
          }
        }
      }
    }
  }

}
