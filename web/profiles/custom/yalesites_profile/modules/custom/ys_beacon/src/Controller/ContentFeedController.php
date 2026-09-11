<?php

namespace Drupal\ys_beacon\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\ys_beacon\BeaconAuthorization;
use Drupal\ys_beacon\Service\ContentFeedBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves the AI content feed for external pull consumers.
 *
 * Open to all users (any role); returns a paginated, structured JSON list of
 * the content the chatbot indexes. Access is unrestricted because
 * ContentFeedBuilder builds every item as the anonymous user, so the feed only
 * ever exposes content a logged-out visitor could read: published, anonymously
 * viewable, and not opted out via the ai_disable_indexing metatag.
 *
 * The feed is closed with a 403 on sites where a platform admin has not
 * authorized Beacon, so no AI-related activity runs there.
 *
 * Being public, unauthenticated, and expensive per request, the endpoint is
 * protected on two fronts: responses carry real cacheability so a repeated
 * request is answered by the page and edge caches instead of re-rendering every
 * node, and a per-client quota bounds what a caller can force the site to do on
 * the requests that do get through.
 */
class ContentFeedController extends ControllerBase {

  /**
   * The flood event name for the content feed quota.
   */
  public const FLOOD_EVENT = 'ys_beacon.content_feed';

  /**
   * Flood control: allowed requests per window, per client IP.
   *
   * Deliberately not the chat endpoint's 30-per-300s. This endpoint's real
   * consumer is a bulk crawler, and a full crawl is a burst of sequential
   * page requests rather than a human's interactive pace: at the maximum page
   * size of 200 entities, 120 requests walk 24,000 entities in a single pass,
   * which covers a full crawl of both supported types on the largest YaleSites
   * site with room left for re-crawls inside the same hour. A chat-shaped limit
   * would break normal use on any site past a few thousand nodes.
   *
   * The ceiling it puts on abuse is the point: roughly one second of rendering
   * per uncached request, so at most a couple of minutes of work per hour per
   * client rather than an unbounded amount. Cached repeats never reach this
   * check at all, which is the intended shape - the quota exists to bound the
   * expensive path, not to meter cache hits.
   */
  public const FLOOD_LIMIT = 120;

  /**
   * Flood control: window length in seconds.
   */
  public const FLOOD_WINDOW = 3600;

  public function __construct(
    protected ContentFeedBuilder $feedBuilder,
    protected BeaconAuthorization $beaconAuthorization,
    protected FloodInterface $flood,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ys_beacon.content_feed_builder'),
      $container->get('ys_beacon.authorization'),
      $container->get('flood'),
    );
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
   *   The feed payload, a 400 for an unsupported type, a 403 when Beacon is not
   *   authorized, or a 429 when the caller is past its quota.
   */
  public function feed(Request $request): JsonResponse {
    // Both outcomes below depend on the authorization flag, so both carry its
    // config tag: an unauthorized site's 403 must not outlive the moment a
    // platform admin authorizes Beacon, and a cached feed page must not be
    // served after authorization is withdrawn.
    $cacheability = (new CacheableMetadata())
      ->addCacheTags(['config:' . BeaconAuthorization::CONFIG_NAME]);

    // Authorization is checked before the quota, so a site that does not
    // participate keeps returning a plain 403 and is never throttled to a 429.
    if (!$this->beaconAuthorization->isAuthorized()) {
      $forbidden = new CacheableJsonResponse(['error' => 'The content feed is not enabled.'], 403);
      $forbidden->addCacheableDependency($cacheability);
      return $forbidden;
    }

    // A plain JsonResponse, so the refusal is never cached: a cached 429 would
    // outlive the window it was issued for and lock the caller out.
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT, self::FLOOD_WINDOW)) {
      $refused = new JsonResponse(['error' => 'Too many requests. Please try again shortly.'], 429);
      // The whole window, which is the worst case: a caller that spent its
      // quota in a burst waits until the oldest of those requests ages out.
      $refused->headers->set('Retry-After', (string) self::FLOOD_WINDOW);
      return $refused;
    }
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW);

    $type = (string) $request->query->get('type', 'node');
    $page = (int) $request->query->get('page', 1);
    $page_size = (int) $request->query->get('page_size', ContentFeedBuilder::DEFAULT_PAGE_SIZE);

    try {
      $feed_page = $this->feedBuilder->build($type, $page, $page_size);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['error' => $e->getMessage()], 400);
    }

    // The page already carries whatever the item renders declared (see
    // ContentFeedBuilder::collectableCacheContexts()). Added here are the two
    // things that vary the payload outside those renders: the query arguments
    // this controller reads itself, and the host, because every item carries an
    // absolute URL built from the current request
    // (EntityCitationResolver::url()). url.site is load-bearing rather than
    // decorative - a site reachable on both its Pantheon hostname and its
    // vanity domain would otherwise serve one host's absolute URLs to a caller
    // on the other, and those URLs become the chatbot's citations.
    $cacheability->addCacheableDependency($feed_page);
    $cacheability->addCacheContexts([
      'url.site',
      'url.query_args:type',
      'url.query_args:page',
      'url.query_args:page_size',
    ]);

    $response = new CacheableJsonResponse($feed_page->payload);
    $response->addCacheableDependency($cacheability);

    // Stamp the feed's own freshness ceiling onto the header. Core's
    // FinishResponseSubscriber would otherwise overwrite Cache-Control with the
    // site-wide page max-age - 24 hours on YaleSites - because it consults only
    // that config value and never the response's own max-age. Setting the
    // header here makes its isCacheControlCustomized() check true, so it leaves
    // the value alone. It cannot leak a public header onto an authenticated
    // response: when the request is not cacheable that subscriber forces
    // Cache-Control back to private unconditionally.
    //
    // What this gives up, deliberately: core's setResponseCacheable() also
    // emits Last-Modified and ETag, so this response supports no 304
    // revalidation. That costs a bulk crawler little, since it re-reads each
    // page in full anyway. Drupal's own page cache is unaffected either way -
    // it ignores max-age entirely and stores at Cache::PERMANENT keyed by
    // tags (PageCache::storeResponse()), so only the edge and the client see
    // this value.
    $max_age = $cacheability->getCacheMaxAge();
    if ($max_age > 0) {
      $response->setPublic();
      $response->setMaxAge($max_age);
      // Core sets this next to its own Cache-Control, so set it here too: a
      // shared proxy must not reuse this entry for a request that carries a
      // session cookie.
      $response->setVary('Cookie', FALSE);
    }

    return $response;
  }

}
