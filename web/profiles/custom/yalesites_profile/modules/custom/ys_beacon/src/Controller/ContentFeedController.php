<?php

namespace Drupal\ys_beacon\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ys_beacon\Service\ContentFeedBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves the AI content feed for external pull consumers.
 *
 * Access-controlled (the "access ys beacon content feed" permission); returns
 * a paginated, structured JSON list of the content the chatbot indexes.
 */
class ContentFeedController extends ControllerBase {

  public function __construct(
    protected ContentFeedBuilder $feedBuilder,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('ys_beacon.content_feed_builder'));
  }

  /**
   * Returns one page of the content feed as JSON.
   *
   * Query parameters: `type` (node|media, default node), `page` (1-based,
   * default 1), `page_size` (default 50, max 200).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The feed payload, or a 400 for an unsupported type.
   */
  public function feed(Request $request): JsonResponse {
    $type = (string) $request->query->get('type', 'node');
    $page = (int) $request->query->get('page', 1);
    $page_size = (int) $request->query->get('page_size', ContentFeedBuilder::DEFAULT_PAGE_SIZE);

    try {
      $payload = $this->feedBuilder->build($type, $page, $page_size);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['error' => $e->getMessage()], 400);
    }

    return new JsonResponse($payload);
  }

}
