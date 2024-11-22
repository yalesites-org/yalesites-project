<?php

namespace Drupal\ys_core\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\ys_campus_group\CampusGroupConfig;
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
   * The route match service.
   *
   * @var \Drupal\ys_campus_group\CampusGroupConfig
   */
  protected $campusGroupConfig;

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
          if ($node->getType() == "event" && $node->hasField('field_event_source')) {
            $event_source_name = $node->field_event_source->entity->label();
            if ($event_source_name == "Campus Groups") {
              $config = $this->campusGroupConfig->getConfig();
              if ($config->get('enable_campus_group_redirect')) {
                $link = $node->get(self::SOURCE_FIELD)->first()->getValue();
                if (!empty($link['uri'])) {
                  $response = new TrustedRedirectResponse($link['uri']);
                  $event->setResponse($response);
                }
              }
            }
          }
          else {
            $link = $node->get(self::SOURCE_FIELD)->first()->getValue();
            if (!empty($link['uri'])) {
              $response = new TrustedRedirectResponse($link['uri']);
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
   * @param \Drupal\ys_campus_group\CampusGroupConfig $campus_group_config
   *   Whether event card clicks redirect to Campus Groups
   */
  public function __construct(RouteMatchInterface $route_match, CampusGroupConfig $campus_group_config) {
    $this->routeMatch = $route_match;
    $this->campusGroupConfig = $campus_group_config;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('router.route_provider'),
      $container->get('ys_campus_group.config')
    );
  }

}
