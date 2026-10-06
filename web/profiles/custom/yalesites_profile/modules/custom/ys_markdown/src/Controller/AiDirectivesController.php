<?php

namespace Drupal\ys_markdown\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\ys_core\AiReadabilitySettings;
use Drupal\ys_markdown\MarkdownBuilder;
use Drupal\ys_markdown\MarkdownEligibility;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Serves /llms.txt, gated by the AI readability settings.
 */
class AiDirectivesController extends ControllerBase {

  /**
   * AI training crawlers blocked when the "block AI crawlers" setting is on.
   *
   * AI search bots (OAI-SearchBot, Claude-SearchBot, PerplexityBot) are left
   * out on purpose: they fetch pages to cite them, which sites want.
   */
  const AI_TRAINING_CRAWLERS = [
    'GPTBot',
    'ClaudeBot',
    'CCBot',
    'Google-Extended',
    'Applebot-Extended',
    'Meta-ExternalAgent',
  ];

  /**
   * Nodes loaded per chunk when building llms.txt.
   */
  const CHUNK_SIZE = 50;

  public function __construct(protected MarkdownEligibility $eligibility) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('ys_markdown.eligibility'));
  }

  /**
   * Returns an llms.txt index of eligible pages, 404 when Markdown is off.
   */
  public function llms(): CacheableResponse {
    $siteConfig = $this->config('ys_core.site');
    $system = $this->config('system.site');
    $cacheability = (new CacheableMetadata())
      ->addCacheTags([
        'node_list',
        'config:cas.settings',
        // Eligibility checks anonymous view access.
        'config:user.role.anonymous',
      ])
      ->addCacheContexts(['url.site'])
      ->addCacheableDependency($siteConfig)
      ->addCacheableDependency($system);
    if (!AiReadabilitySettings::isEnabled($siteConfig, AiReadabilitySettings::MARKDOWN_ENABLED)) {
      // Return rather than throw: core's fast 404 discards an exception's
      // cacheability for .txt paths, so the 404 would never be invalidated.
      $response = new CacheableResponse("Not found\n", 404, [
        'Content-Type' => 'text/plain; charset=utf-8',
      ]);
      $response->addCacheableDependency($cacheability);
      return $response;
    }

    $markdown = '# ' . $system->get('name') . "\n\n";
    if ($system->get('slogan')) {
      $markdown .= '> ' . $system->get('slogan') . "\n\n";
    }

    $storage = $this->entityTypeManager()->getStorage('node');
    // ponytail: loads every published node on a cache miss; move to a paged
    // or queued build if large sites time out.
    $lines = [];
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('status', 1)->sort('nid')->execute();
    foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $node) {
        if (!$this->eligibility->isEligible($node)) {
          continue;
        }
        $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
        $title = strtr($node->label(), ['[' => '\[', ']' => '\]']);
        $lines[$node->bundle()][] = '- [' . $title . '](' . $url . ".md)\n";
      }
      $storage->resetCache($chunk);
    }

    // One section per content type, ordered by label.
    $types = $this->entityTypeManager()->getStorage('node_type')->loadMultiple(array_keys($lines));
    uasort($types, fn($a, $b) => strcasecmp($a->label(), $b->label()));
    foreach ($types as $id => $type) {
      $cacheability->addCacheableDependency($type);
      $markdown .= "## " . $type->label() . "\n\n" . implode('', $lines[$id]) . "\n";
    }

    $cacheability->setCacheMaxAge(MarkdownBuilder::MAX_AGE);
    $response = new CacheableResponse($markdown, 200, [
      'Content-Type' => 'text/markdown; charset=utf-8',
    ]);
    $response->addCacheableDependency($cacheability);
    // Hourly ceiling set on the header itself: see ContentFeedController in
    // ys_beacon for why core's FinishResponseSubscriber must not override it.
    $response->setPublic();
    $response->setMaxAge(MarkdownBuilder::MAX_AGE);
    $response->setVary('Cookie', FALSE);
    return $response;
  }

}
