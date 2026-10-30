<?php

namespace Drupal\ys_markdown;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\NodeInterface;
use Drupal\ys_markdown\Converter\SeparatedDivConverter;
use Drupal\ys_beacon\Service\ContentFeedBuilder;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders a node as the anonymous user and converts it to Markdown.
 *
 * The render, cache-metadata and anonymous-switch pattern is copied from
 * ys_beacon's ContentFeedBuilder on purpose; see its docblocks for the full
 * reasoning behind the max-age and the context filtering.
 */
class MarkdownBuilder {

  /**
   * How long a built page stays fresh, in seconds.
   *
   * A node whose layout embeds a listing view bubbles max-age 0 because that
   * view is time-varying on the page itself. Propagating that would make the
   * Markdown copy permanently uncacheable, so the ceiling is set here instead.
   * Content changes are still caught at once by cache tags.
   */
  const MAX_AGE = 3600;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RendererInterface $renderer,
    protected AccountSwitcherInterface $accountSwitcher,
    protected RequestStack $requestStack,
    protected PublicHtmlFilter $htmlFilter,
  ) {
  }

  /**
   * Builds the Markdown document for a node.
   *
   * @return array
   *   An array with 'markdown' (string) and 'cacheability'
   *   (\Drupal\Core\Cache\CacheableMetadata).
   */
  public function build(NodeInterface $node): array {
    $cacheability = CacheableMetadata::createFromObject($node);
    $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE);
    $cacheability->addCacheableDependency($url);

    $html = $this->renderAsAnonymous($node, $cacheability);
    $title = trim($node->label() ?? '');
    $body = self::convert($this->htmlFilter->filter($html, $title));

    $markdown = '# ' . $title . "\n\n"
      . 'Source: ' . $url->getGeneratedUrl() . "\n"
      . 'Last updated: ' . gmdate('Y-m-d', (int) $node->getChangedTime()) . "\n"
      . ($body === '' ? '' : "\n" . $body . "\n");

    // Set rather than merged: see MAX_AGE.
    $cacheability->setCacheMaxAge(self::MAX_AGE);
    return ['markdown' => $markdown, 'cacheability' => $cacheability];
  }

  /**
   * Renders the node's default display as the anonymous user.
   *
   * The Markdown never varies by query string: the request in effect during the
   * render has an empty query, so an embedded listing renders its first page,
   * and the query-string cache contexts are dropped from the result.
   */
  protected function renderAsAnonymous(NodeInterface $node, CacheableMetadata $cacheability): string {
    $current = $this->requestStack->getCurrentRequest();
    $request = $current->duplicate([]);
    $request->server->set('QUERY_STRING', '');
    $request->server->set('REQUEST_URI', strtok((string) $current->server->get('REQUEST_URI'), '?') ?: '/');
    $this->requestStack->push($request);
    $this->accountSwitcher->switchTo(new AnonymousUserSession());
    try {
      $build = $this->entityTypeManager->getViewBuilder('node')->view($node, 'default');
      $html = (string) $this->renderer->renderInIsolation($build);
      $rendered = CacheableMetadata::createFromRenderArray($build);
      $cacheability->addCacheTags($rendered->getCacheTags());
      $cacheability->addCacheContexts(self::collectableCacheContexts($rendered->getCacheContexts()));
    }
    finally {
      $this->accountSwitcher->switchBack();
      $this->requestStack->pop();
    }
    return $html;
  }

  /**
   * Drops the contexts that must not key a shared, public response.
   *
   * Extends ContentFeedBuilder::collectableCacheContexts() by also dropping
   * query-string contexts, because this output ignores the query string.
   *
   * @param string[] $contexts
   *   The contexts bubbled by the render.
   *
   * @return string[]
   *   The contexts to keep.
   */
  public static function collectableCacheContexts(array $contexts): array {
    return array_values(array_filter(
      ContentFeedBuilder::collectableCacheContexts($contexts),
      static fn (string $context): bool => !str_starts_with($context, 'url.query_args'),
    ));
  }

  /**
   * Cleans converted Markdown of indentation and blank-line runs.
   *
   * Leading indentation would read as an indented code block, so it is removed
   * everywhere except inside fenced code.
   */
  public static function tidy(string $markdown): string {
    $inFence = FALSE;
    $lines = [];
    foreach (preg_split('/\R/', $markdown) as $line) {
      $line = rtrim($line);
      if (preg_match('/^\s*```/', $line)) {
        $line = $inFence ? '```' : ltrim($line);
        $inFence = !$inFence;
      }
      elseif (!$inFence) {
        $line = ltrim($line);
      }
      $lines[] = $line;
    }
    return trim((string) preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)));
  }

  /**
   * Converts filtered HTML to Markdown.
   */
  public static function convert(string $html): string {
    $converter = new HtmlConverter([
      'strip_tags' => TRUE,
      'strip_placeholder_links' => TRUE,
      'header_style' => 'atx',
    ]);
    $converter->getEnvironment()->addConverter(new TableConverter());
    $converter->getEnvironment()->addConverter(new SeparatedDivConverter());
    return self::tidy(self::decodeEntities($converter->convert($html)));
  }

  /**
   * Decodes HTML entities the converter leaves encoded.
   *
   * Based on ys_beacon's MarkdownConverter::decodeEntities(), which is not
   * callable without its service dependencies, but it differs on "<" and ">".
   * Those become literal characters, except where they could start markup:
   * ">" at the start of a line (after any blockquote or list markers) and "<"
   * before a letter, "/", "!" or "?". There the entity is kept, because a
   * character reference is literal whatever precedes it (a backslash escape
   * is not, since the converter leaves a backslash in text unescaped). The
   * numeric forms of "<" and ">" are treated the same, so decoding never emits
   * a raw tag. Code needs no special case: the converter already writes
   * literal "<" and ">" in code, so no such entity is left there to touch.
   */
  protected static function decodeEntities(string $markdown): string {
    $guarded = preg_replace(['/&(?:lt|#0*60|#x0*3c);/i', '/&(?:gt|#0*62|#x0*3e);/i'], ["\x01", "\x02"], $markdown);
    $decoded = html_entity_decode($guarded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $decoded = preg_replace('/(^|\n)((?: *>)* *(?:(?:[-*+]|\d+[.)]) +)?)\x02/', '$1$2&gt;', $decoded);
    $decoded = preg_replace('/\x01(?=[A-Za-z\/!?])/', '&lt;', $decoded);
    return strtr($decoded, ["\x01" => '<', "\x02" => '>']);
  }

}
