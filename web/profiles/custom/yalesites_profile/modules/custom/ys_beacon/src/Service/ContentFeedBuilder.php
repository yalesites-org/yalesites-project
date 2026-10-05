<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\media\MediaInterface;

/**
 * Builds the AI content feed: a paginated, structured list of indexed content.
 *
 * Equivalent of the legacy ai_engine_feed `/api/ai/v1/content` endpoint, but
 * filtered by exactly the same indexability rules the push-based pipeline uses
 * (BeaconIndexability), so a pull consumer sees the same content the chatbot
 * indexes - published, anonymously viewable, and not opted out via the
 * ai_disable_indexing metatag.
 */
class ContentFeedBuilder {

  /**
   * Entity types the feed can serve, mapped to their published-status field.
   */
  protected const SUPPORTED_TYPES = [
    'node' => 'status',
    'media' => 'status',
  ];

  /**
   * The default and maximum number of entities scanned per page.
   */
  public const DEFAULT_PAGE_SIZE = 50;
  public const MAX_PAGE_SIZE = 200;

  /**
   * How long a built page stays fresh, in seconds.
   *
   * Content changes are caught by cache tags and invalidate the page at once,
   * so this ceiling only bounds staleness from things a tag cannot express.
   * It is set deliberately rather than inherited from the items' own renders:
   * a node whose layout embeds a listing view reports max-age 0, because that
   * view is time-varying on the page it appears on. Propagating that would
   * leave the feed permanently uncacheable - one such node anywhere in a page
   * of up to 200 makes the whole response uncacheable, which is the state this
   * endpoint is being fixed out of.
   *
   * The feed is a periodically-crawled export of what each page says, not the
   * page itself, so an hour-old rendering of an embedded listing is the right
   * trade for making a repeated crawl free. Consumers re-crawl on a much longer
   * cycle than this anyway.
   *
   * This ceiling is also the backstop for tag-based invalidation at the edge.
   * pantheon_advanced_page_cache serialises a response's tags into a single
   * Surrogate-Key header and truncates it at 25000 bytes, and a page of up to
   * MAX_PAGE_SIZE fully-rendered nodes can carry more tags than that. Cache
   * tags keep insertion order (Cache::mergeTags() is array_unique + array_merge
   * with no sort), and truncation keeps the head of the string, so the list tag
   * added first in ::build() is the one that survives - which is why it is
   * added before the item loop rather than after it. Tags for individual
   * entities can still be cut, so an hour is the guaranteed staleness bound at
   * the edge when a purge is missed, and that is why it is set here rather than
   * left to the site-wide 24-hour page max-age.
   */
  public const MAX_AGE = 3600;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected BeaconIndexability $indexability,
    protected AiMetadataManager $aiMetadataManager,
    protected RendererInterface $renderer,
    protected AccountSwitcherInterface $accountSwitcher,
    protected EntityCitationResolver $citationResolver,
  ) {
  }

  /**
   * Builds one page of the content feed.
   *
   * @param string $type
   *   The entity type to feed ('node' or 'media').
   * @param int $page
   *   The 1-based page number.
   * @param int $pageSize
   *   The page window size (clamped to MAX_PAGE_SIZE).
   *
   * @return \Drupal\ys_beacon\Service\ContentFeedPage
   *   The page: the feed payload (data items, pagination, and totals) plus the
   *   cacheability of everything it was built from. The totals count published
   *   candidates before per-item indexability filtering, so they are an upper
   *   bound - a page may contain fewer items than total_records implies.
   *
   * @throws \InvalidArgumentException
   *   When the entity type is not supported.
   */
  public function build(string $type, int $page = 1, int $pageSize = self::DEFAULT_PAGE_SIZE): ContentFeedPage {
    if (!isset(self::SUPPORTED_TYPES[$type])) {
      throw new \InvalidArgumentException(sprintf('Unsupported feed type "%s".', $type));
    }
    $page = max(1, $page);
    $pageSize = max(1, min($pageSize, self::MAX_PAGE_SIZE));
    $status_field = self::SUPPORTED_TYPES[$type];
    $storage = $this->entityTypeManager->getStorage($type);
    $definition = $this->entityTypeManager->getDefinition($type);
    $id_key = $definition->getKey('id');

    // The list cache tag covers everything the page-level query depends on:
    // content created, deleted, or published/unpublished changes which entities
    // appear and what the totals are. Per-entity tags are added below so edits
    // to something already on the page busts it too.
    $cacheability = (new CacheableMetadata())
      ->addCacheTags($definition->getListCacheTags());

    // A count of published candidates only: the per-item indexability filter
    // below needs a per-entity load and access check, which is infeasible
    // across the whole set just to produce a total, so total_records/
    // total_pages are an upper bound rather than the exact served count.
    $total = (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition($status_field, 1)
      ->count()
      ->execute();

    // Page over published entities by id; the per-item indexability filter
    // (anonymous view access, ai_disable_indexing) then removes any that must
    // not be exposed, so a page may yield fewer than $pageSize items.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition($status_field, 1)
      ->sort($id_key)
      ->range(($page - 1) * $pageSize, $pageSize)
      ->execute();

    $data = [];
    // Build the whole page as the anonymous user in a single account switch
    // rather than one per item: the feed only ever exposes content a
    // logged-out visitor can see.
    $this->accountSwitcher->switchTo(new AnonymousUserSession());
    try {
      foreach ($storage->loadMultiple($ids) as $entity) {
        if ($entity instanceof ContentEntityInterface && $this->indexability->isIndexable($entity)) {
          $data[] = $this->buildItem($entity, $cacheability);
          // Load-bearing for media, whose items are not rendered and so bubble
          // nothing; a node's own tag already arrives via its render defaults.
          $cacheability->addCacheableDependency($entity);
        }
      }
    }
    finally {
      $this->accountSwitcher->switchBack();
    }

    // Set rather than merged: see the MAX_AGE docblock for why an item's own
    // max-age is not what governs this JSON snapshot.
    $cacheability->setCacheMaxAge(self::MAX_AGE);

    return new ContentFeedPage([
      'data' => $data,
      'pagination' => [
        'type' => $type,
        'page' => $page,
        'page_size' => $pageSize,
        'total_records' => $total,
        'total_pages' => (int) ceil($total / $pageSize),
      ],
    ], $cacheability);
  }

  /**
   * Builds the structured feed item for one entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The indexable entity.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the item's rendered body.
   *
   * @return array
   *   The structured item.
   */
  protected function buildItem(ContentEntityInterface $entity, CacheableMetadata $cacheability): array {
    $ai = $this->aiMetadataManager->getAiMetadata($entity);
    $created = $entity->hasField('created') ? (int) $entity->get('created')->value : 0;
    $changed = method_exists($entity, 'getChangedTime') ? (int) $entity->getChangedTime() : 0;

    return [
      'id' => $entity->getEntityTypeId() . '/' . $entity->id(),
      'type' => $entity->getEntityTypeId(),
      'bundle' => $entity->bundle(),
      'uuid' => $entity->uuid(),
      'title' => $this->citationResolver->title($entity),
      'url' => $this->citationResolver->url($entity),
      'langcode' => $entity->language()->getId(),
      'created' => $created ? gmdate('c', $created) : NULL,
      'changed' => $changed ? gmdate('c', $changed) : NULL,
      'ai_description' => $ai['ai_description'] ?? '',
      'ai_tags' => $ai['ai_tags'] ?? '',
      'content' => $entity instanceof MediaInterface ? '' : $this->renderContent($entity, $cacheability),
    ];
  }

  /**
   * Filters an item render's cache contexts down to the ones that still apply.
   *
   * The rule is deliberately a subtraction rather than an allowlist, because
   * the cache key has to be a superset of everything that varies the bytes.
   * Anything the render declared is kept unless the forced anonymous account
   * switch has genuinely made it constant: items are rendered as the anonymous
   * user no matter who asks, so descendants of the user and session contexts
   * describe a variation that cannot occur here and would only fragment the
   * cache.
   *
   * Matching follows the cache-context hierarchy rather than raw characters -
   * contexts nest with '.' and take parameters with ':' (user.permissions,
   * user.node_grants:view) - so a future contrib context merely spelled like
   * one of these is not swallowed by accident.
   *
   * Every other context is load-bearing, and dropping them was a real bug. A
   * node whose layout embeds a Content List block renders a view with exposed
   * filters, and an exposed filter reads the *current* request's query string,
   * so core bubbles url / url.query_args for it. Discarding those let a caller
   * change the rendered body with a query argument that was not in the cache
   * key, poisoning the entry the next caller would be served.
   *
   * @param string[] $contexts
   *   The cache contexts bubbled by an item render.
   *
   * @return string[]
   *   The contexts to carry onto the feed page.
   */
  public static function collectableCacheContexts(array $contexts): array {
    return array_values(array_filter(
      $contexts,
      static fn (string $context): bool => !in_array($context, ['user', 'session'], TRUE)
        && preg_match('/^(user|session)[.:]/', $context) !== 1,
    ));
  }

  /**
   * Renders an entity's default view, returning plain text.
   *
   * The caller (build()) switches to the anonymous user for the whole page, so
   * the feed body matches what the chatbot indexes and never leaks content only
   * privileged users can see. Isolation still keeps the render's cache metadata
   * out of the JSON, but it is collected rather than discarded:
   * renderInIsolation() takes the build by reference and, being a root render,
   * bubbles what it depended on into $build['#cache'].
   *
   * Tags and contexts are both collected; see ::collectableCacheContexts() for
   * which contexts survive and why. Max-age is set on the page as a whole; see
   * ContentFeedBuilder::MAX_AGE.
   */
  protected function renderContent(ContentEntityInterface $entity, CacheableMetadata $cacheability): string {
    try {
      $build = $this->entityTypeManager
        ->getViewBuilder($entity->getEntityTypeId())
        ->view($entity, 'default');
      $html = (string) $this->renderer->renderInIsolation($build);
      $rendered = CacheableMetadata::createFromRenderArray($build);
      $cacheability->addCacheTags($rendered->getCacheTags());
      $cacheability->addCacheContexts(
        self::collectableCacheContexts($rendered->getCacheContexts())
      );
    }
    catch (\Throwable $e) {
      $html = '';
    }
    return trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));
  }

}
