<?php

namespace Drupal\ai_feed\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai_feed\Sources;

/**
 * Class ContentFeed.
 *
 * @package Drupal\ai_feed\Controller
 */
class ContentFeed extends ControllerBase {

  /**
   * .
   *
   * @var \Drupal\ai_feed\Sources
   */
  protected $sources;

  /**
   * Returns all nodes as fully rendered entities in a JSON feed.
   */
  public function jsonResponse() {
    $content = $this->sources->getContent();
    $response = new JsonResponse($content);
    $response->headers->set('Content-Type', 'application/json');
    return $response;
  }

  /**
   * Constructs a new CustomController object.
   *
   * @param \Drupal\ai_feed\Sources $sources
   */
  public function __construct(Sources $sources) {
    $this->sources = $sources;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('ai_feed.sources'));
  }

}
