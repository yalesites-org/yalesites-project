<?php

namespace Drupal\ys_core\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
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
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
        // Reload the node from storage to ensure we have the latest data.
        // This prevents issues with cached entity data.
        $fresh_node = $this->entityTypeManager->getStorage('node')->load($node->id());

        if ($fresh_node && $fresh_node->hasField(self::SOURCE_FIELD)) {
          $external_source_field = $fresh_node->get(self::SOURCE_FIELD)->first();

          if ($external_source_field) {
            $link = $external_source_field->getValue();

            if (!empty($link['uri'])) {
              $response = new TrustedRedirectResponse($link['uri']);
              // Comprehensive cache prevention headers to ensure redirect
              // changes are immediately reflected.
              $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0');
              $response->headers->set('Pragma', 'no-cache');
              $response->headers->set('Expires', '0');
              // Add Vary header to prevent proxy caching.
              $response->headers->set('Vary', '*');
              // Set max-age and s-maxage explicitly to 0.
              $response->setMaxAge(0);
              $response->setSharedMaxAge(0);
              // Mark response as private to prevent shared caching.
              $response->setPrivate();
              $event->setResponse($response);
            }
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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(RouteMatchInterface $route_match, EntityTypeManagerInterface $entity_type_manager) {
    $this->routeMatch = $route_match;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_route_match'),
      $container->get('entity_type.manager')
    );
  }

}
