<?php

namespace Drupal\ys_views_basic\EventSubscriber;

use Drupal\views\Ajax\ViewAjaxResponse;
use Drupal\ys_views_basic\ViewsBasicManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Leaves the reader where they were when a views basic pager is clicked.
 *
 * The scaffold views set `use_ajax: true`, so a pager link is an AJAX request
 * handled by \Drupal\views\Controller\ViewAjaxController::ajaxView(). That
 * controller unconditionally adds a ScrollTopCommand targeting the view
 * wrapper, and the client command is `element.scrollIntoView()`, which pulls
 * the top of the listing to the top of the window. On a page where the listing
 * sits below other content that throws the reader upward mid-read, which is
 * what YaleSites-Internal#1648 asks us to stop doing.
 *
 * The command is added inside the controller with nothing to opt out of it, so
 * it is dropped here on the way out instead. Scoped to the two scaffold views
 * so every other view on the platform keeps core's behaviour.
 */
class PagerScrollSubscriber implements EventSubscriberInterface {

  /**
   * Drops the scroll-to-top command from a views basic AJAX response.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    if (!$response instanceof ViewAjaxResponse) {
      return;
    }

    $view = $response->getView();
    if (!$view || !in_array($view->id(), ViewsBasicManager::SCAFFOLD_VIEWS, TRUE)) {
      return;
    }

    // AjaxResponse::getCommands() returns by reference, so the filtered list
    // replaces the response's own.
    $commands = &$response->getCommands();
    $commands = array_values(array_filter(
      $commands,
      static fn($command): bool => !is_array($command) || ($command['command'] ?? '') !== 'scrollTop'
    ));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Default priority, stated rather than implied: it has to stay above -100,
    // where AjaxResponseSubscriber::onResponse() renders the commands out.
    // Filtering after that point would change nothing.
    $events[KernelEvents::RESPONSE][] = ['onResponse', 0];
    return $events;
  }

}
