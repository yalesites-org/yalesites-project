<?php

namespace Drupal\ys_core\EventSubscriber;

use Drupal\Core\Link;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects visitors to an external source if a link is provided.
 */
class ExternalSourceRedirectSubscriber implements EventSubscriberInterface {

  /**
   * The machine name of the field with the external source link.
   */
  const SOURCE_FIELD = 'field_external_source';

  /**
   * The route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::VIEW][] = ['onKernelView', 25];
    return $events;
  }

  /**
   * Redirects visitors to an external source if a link is provided.
   *
   * If viewing the canonical display of a node and the content has a URL
   * populated in field_external_source, then redirect the user to this URL.
   *
   * @param \Symfony\Component\HttpKernel\Event\ViewEvent $event
   *   The event to process.
   */
  public function onKernelView(ViewEvent $event) {
    if ($this->routeMatch->getRouteName() === 'layout_builder.overrides.node.view' || $this->routeMatch->getRouteName() === 'entity.node.edit_form') {
      /** @var \Drupal\node\NodeInterface $node */
      $node = $this->routeMatch->getParameter('node');
      if (!empty($node) && $node->hasField(self::SOURCE_FIELD)) {
        if (!empty($node->get(self::SOURCE_FIELD)->first())) {
          $link = $node->get(self::SOURCE_FIELD)->first()->getValue();
          if (!empty($link['uri'])) {
            $this->messenger->addWarning(
              'An External Source has been assigned to this content and as a result, any additions you make in Edit Layout and Content will not be visible to your users unless the External Source is removed.'
            );
            $editLink = $node->toUrl('edit-form')->toString();
            $title = $node->getTitle();
            $editLinkMarkup = "<a href='$editLink'>Edit $title</a>";
            $message = [
              '#type' => 'markup',
              '#markup' => "$editLinkMarkup",
            ];
            $this->messenger->addWarning(\Drupal::service('renderer')->renderPlain($message));
          }
        }
      }
    }
    if ($this->routeMatch->getRouteName() === 'entity.node.canonical') {
      /** @var \Drupal\node\NodeInterface $node */
      $node = $this->routeMatch->getParameter('node');
      if (!empty($node) && $node->hasField(self::SOURCE_FIELD)) {
        if (!empty($node->get(self::SOURCE_FIELD)->first())) {
          $link = $node->get(self::SOURCE_FIELD)->first()->getValue();
          if (!empty($link['uri'])) {
            $response = new TrustedRedirectResponse($link['uri']);
            $event->setResponse($response);
          }
        }
      }
    }
  }

  /**
   * Constructs a new ExternalSourceRedirectSubscriber object.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(RouteMatchInterface $route_match, MessengerInterface $messenger) {
    $this->routeMatch = $route_match;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('router.route_provider'),
      $container->get('messenger')
    );
  }

}
