<?php

namespace Drupal\ys_markdown;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\node\NodeInterface;
use Drupal\ys_beacon\Service\BeaconIndexability;
use Drupal\ys_core\AiReadabilitySettings;
use Drupal\ys_core\EventSubscriber\ExternalSourceRedirectSubscriber;

/**
 * Decides whether a node may be served as public Markdown.
 */
class MarkdownEligibility {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected BeaconIndexability $indexability,
    protected AliasManagerInterface $aliasManager,
    protected PathMatcherInterface $pathMatcher,
  ) {
  }

  /**
   * Whether the node's Markdown version may be served to anyone.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string|null $requestPath
   *   The path the visitor asked for, without ".md", when known.
   */
  public function isEligible(NodeInterface $node, ?string $requestPath = NULL): bool {
    return AiReadabilitySettings::isEnabled($this->configFactory->get('ys_core.site'), AiReadabilitySettings::MARKDOWN_ENABLED)
      && $node->isDefaultRevision()
      && $this->indexability->isIndexable($node)
      && !$this->redirectsToExternalSource($node)
      && !$this->isForcedLogin($node, $requestPath);
  }

  /**
   * What an eligibility answer for the node depends on.
   */
  public function getCacheability(NodeInterface $node): CacheableMetadata {
    $cacheability = CacheableMetadata::createFromObject($node);
    $cacheability->addCacheTags(['config:ys_core.site', 'config:cas.settings']);
    $cacheability->addCacheContexts(['url.path']);
    return $cacheability;
  }

  /**
   * Whether the canonical page sends visitors to an external URL.
   *
   * Mirrors ExternalSourceRedirectSubscriber, which skips resource nodes.
   */
  protected function redirectsToExternalSource(NodeInterface $node): bool {
    return $node->bundle() !== 'resource'
      && $node->hasField(ExternalSourceRedirectSubscriber::SOURCE_FIELD)
      && !$node->get(ExternalSourceRedirectSubscriber::SOURCE_FIELD)->isEmpty();
  }

  /**
   * Whether CAS forces a login on any path this node answers to.
   *
   * Follows the request_path condition CAS builds from its settings: an empty
   * list matches everything, and "negate" flips the result.
   */
  protected function isForcedLogin(NodeInterface $node, ?string $requestPath): bool {
    $cas = $this->configFactory->get('cas.settings');
    if (!$cas->get('forced_login.enabled')) {
      return FALSE;
    }
    $paths = $cas->get('forced_login.paths') ?? [];
    $pages = mb_strtolower($paths['pages'] ?? '');
    $internal = '/node/' . $node->id();
    $matches = $pages === '' || array_filter(
      array_filter([$internal, $this->aliasManager->getAliasByPath($internal), $requestPath]),
      fn (string $candidate): bool => $this->pathMatcher->matchPath(mb_strtolower($candidate), $pages),
    ) !== [];
    return $matches !== !empty($paths['negate']);
  }

}
