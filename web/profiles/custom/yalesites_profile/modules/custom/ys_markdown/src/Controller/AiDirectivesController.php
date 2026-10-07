<?php

namespace Drupal\ys_markdown\Controller;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\metatag\MetatagManagerInterface;
use Drupal\metatag\MetatagToken;
use Drupal\node\NodeInterface;
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
   * Cache tag invalidated when a listed page stops being eligible.
   */
  const CACHE_TAG = 'ys_markdown:llms';

  /**
   * Nodes loaded per chunk when building llms.txt.
   */
  const CHUNK_SIZE = 50;

  /**
   * Freshness of llms.txt: half the usual hour, as page cache and edge stack.
   */
  const MAX_AGE = MarkdownBuilder::MAX_AGE / 2;

  /**
   * Longest description kept in llms.txt, before the trailing dots.
   */
  const DESCRIPTION_MAX_LENGTH = 200;

  /**
   * Default Metatag description template per bundle, filled on first use.
   *
   * @var string[]
   */
  protected array $defaultDescriptions = [];

  public function __construct(
    protected MarkdownEligibility $eligibility,
    protected MetatagManagerInterface $metatagManager,
    protected MetatagToken $metatagToken,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ys_markdown.eligibility'),
      $container->get('metatag.manager'),
      $container->get('metatag.token'),
    );
  }

  /**
   * Returns an llms.txt index of eligible pages, 404 when Markdown is off.
   */
  public function llms(): CacheableResponse {
    $siteConfig = $this->config('ys_core.site');
    $system = $this->config('system.site');
    $cacheability = (new CacheableMetadata())
      ->addCacheTags([
        self::CACHE_TAG,
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
    $tokenCacheability = new BubbleableMetadata();
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('status', 1)->sort('nid')->execute();
    foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $node) {
        if (!$this->eligibility->isEligible($node)) {
          continue;
        }
        $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
        $title = strtr($node->label(), ['[' => '\[', ']' => '\]']);
        $description = $this->description($node, $tokenCacheability);
        $lines[$node->bundle()][] = '- [' . $title . '](' . $url . ".md)" . ($description === '' ? '' : ': ' . $description) . "\n";
      }
      $storage->resetCache($chunk);
    }

    // One section per content type, ordered by label.
    $cacheability->addCacheableDependency($tokenCacheability)
      ->addCacheTags([
        'config:metatag.metatag_defaults.global',
        'config:metatag.metatag_defaults.node',
      ]);
    $types = $this->entityTypeManager()->getStorage('node_type')->loadMultiple(array_keys($lines));
    uasort($types, fn($a, $b) => strcasecmp($a->label(), $b->label()));
    foreach ($types as $id => $type) {
      $cacheability->addCacheableDependency($type)
        ->addCacheTags(['config:metatag.metatag_defaults.node__' . $id]);
      $markdown .= "## " . $type->label() . "\n\n" . implode('', $lines[$id]) . "\n";
    }

    $cacheability->setCacheMaxAge(self::MAX_AGE);
    $response = new CacheableResponse($markdown, 200, [
      'Content-Type' => 'text/markdown; charset=utf-8',
    ]);
    $response->addCacheableDependency($cacheability);
    // Ceiling set on the header itself: see ContentFeedController in
    // ys_beacon for why core's FinishResponseSubscriber must not override it.
    $response->setPublic();
    $response->setMaxAge(self::MAX_AGE);
    // Expires bounds core's page cache, which would otherwise never expire.
    $response->setExpires(new \DateTime('+' . self::MAX_AGE . ' seconds'));
    $response->setVary('Cookie', FALSE);
    return $response;
  }

  /**
   * Returns the page's resolved Metatag description as short plain text.
   */
  protected function description(NodeInterface $node, BubbleableMetadata $bubbleable): string {
    $bundle = $node->bundle();
    $this->defaultDescriptions[$bundle] ??= $this->metatagManager->defaultTagsFromEntity($node)['description'] ?? '';
    $template = $this->metatagManager->tagsFromEntity($node)['description'] ?? '';
    $template = $template ?: $this->defaultDescriptions[$bundle];
    if ($template === '[node:field_teaser_text]') {
      // Fast path: the token would resolve to the processed teaser.
      $text = $node->hasField('field_teaser_text') ? (string) $node->get('field_teaser_text')->processed : '';
    }
    else {
      $text = $this->metatagToken->replace($template, ['node' => $node], ['langcode' => $node->language()->getId()], $bubbleable);
    }
    $text = trim(preg_replace('/\s+/u', ' ', PlainTextOutput::renderFromHtml($text)));
    if (mb_strlen($text) > self::DESCRIPTION_MAX_LENGTH) {
      $text = Unicode::truncate($text, self::DESCRIPTION_MAX_LENGTH, TRUE) . '...';
    }
    return $text;
  }

}
