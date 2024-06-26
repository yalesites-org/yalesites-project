<?php

namespace Drupal\ys_core\EventSubscriber;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Alters web/modules/contrib/moderation_sidebar/src/Controller/ModerationSidebarController.php.
 *
 * Changes the default button labels to match YaleSites edit and layout labels.
 */
class AlterModerationSidebarController extends ControllerBase implements EventSubscriberInterface {

  /**
   * Alters the controller output.
   */
  public function onView(ViewEvent $event) {
    $request = $event->getRequest();
    $route = $request->attributes->get('_route');

    if ($route == 'moderation_sidebar.sidebar' || $route == 'moderation_sidebar.sidebar_latest') {
      $build = $event->getControllerResult();
      if (is_array($build)) {
        $build['actions']['primary']['edit']['#title'] = $this->t('Manage Settings');
        $build['actions']['secondary']['layout_builder_ui:layout_builder.overrides.node.view']['#title'] = $this->t('Edit Layout And Content');
        $event->setControllerResult($build);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Priority > 0 so that it runs before the controller output.
    // is rendered by \Drupal\Core\EventSubscriber\MainContentViewSubscriber.
    $events[KernelEvents::VIEW][] = ['onView', 50];
    return $events;
  }

}
