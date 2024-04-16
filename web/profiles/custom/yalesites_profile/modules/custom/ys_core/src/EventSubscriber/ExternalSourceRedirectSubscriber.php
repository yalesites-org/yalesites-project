<?php

namespace Drupal\ys_core\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
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
   */
  public function __construct(RouteMatchInterface $route_match) {
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('router.route_provider')
    );
  }

}
