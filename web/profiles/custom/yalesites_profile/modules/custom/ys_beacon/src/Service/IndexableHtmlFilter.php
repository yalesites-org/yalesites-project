<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;

/**
 * Removes the parts of rendered HTML that do not belong in the Beacon index.
 *
 * The Beacon index stores what the language model reads, and ai_search already
 * turns each indexed field into Markdown on the way into the vector database:
 * EmbeddingStrategyPluginBase builds a League\HTMLToMarkdown\HtmlConverter,
 * switches on strip_tags and strip_placeholder_links, and registers a
 * TableConverter, and EmbeddingBase::getValue() then runs it over every value.
 * That conversion used to accomplish nothing, because Search API's html_filter
 * processor flattened the rendered HTML first and left it nothing to convert -
 * which is how headings, lists and emphasis went missing, and how adjacent list
 * items and table cells ended up concatenated into single words.
 *
 * So this service converts nothing and rewrites nothing. It only deletes, for
 * the two reasons that conversion cannot handle itself:
 *
 * - Some elements are not prose but their text still survives conversion. A
 *   <script> or <style> body is indexed verbatim as content; a <nav> becomes a
 *   Markdown list of every menu link; an <img> becomes an image tag; a <figure>
 *   runs its caption straight into the following paragraph.
 * - A javascript: link target would otherwise be stored as a working Markdown
 *   link, and chat answers are rendered as Markdown.
 *
 * Everything else is deliberately left alone, because the configured converter
 * already does it better than a hand-rolled pass would: strip_tags unwraps
 * unknown wrappers (div, article, section, span) while keeping block spacing,
 * empty headings are dropped without leaving a stray setext underline,
 * comments (including Twig theme debug) are removed, presentational attributes
 * never reach the output, and strip_placeholder_links drops empty link targets.
 *
 * Reducing the markup any further here would actively cause harm. An earlier
 * revision unwrapped non-structural elements itself and inserted a separating
 * space, which split words abutting inline markup - "un<span>believable</span>"
 * came out as "un believable". Emitting Markdown here rather than HTML would be
 * worse still: the value goes through the converter afterwards regardless, and
 * TextConverter escapes Markdown punctuation, turning "**bold**" into
 * "\*\*bold\*\*" and "[text](url)" into "\[text\](url)".
 */
class IndexableHtmlFilter {

  /**
   * Elements deleted along with their contents.
   *
   * Every one of these survives conversion in some form when left in place -
   * as leaked script text, an image tag, a menu rendered as a list, or a
   * caption run into the next paragraph.
   */
  const REMOVED_TAGS = [
    'audio', 'button', 'canvas', 'embed', 'figure', 'form', 'iframe', 'img',
    'input', 'nav', 'noscript', 'object', 'picture', 'script', 'select',
    'source', 'style', 'svg', 'template', 'textarea', 'track', 'video',
  ];

  /**
   * Elements kept even when they hold no text.
   *
   * A line break and a table cell both mean something empty: the break is the
   * content, and a blank cell still carries the shape of its row. Everything
   * else with no text in it is page furniture.
   */
  const TEXTLESS_ELEMENTS_KEPT = [
    'br', 'hr', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr',
  ];

  /**
   * Filters rendered HTML down to what belongs in the index.
   *
   * @param string $html
   *   The rendered HTML for one indexed item.
   *
   * @return string
   *   The same HTML with non-content elements and unusable links removed.
   */
  public function filter(string $html): string {
    if (trim($html) === '') {
      return '';
    }

    $document = Html::load($html);
    $this->removeNonContentElements($document);
    $this->removeTextlessElements($document);
    $this->unwrapUnsafeLinks($document);

    return trim(Html::serialize($document));
  }

  /**
   * Deletes non-content elements along with their contents.
   *
   * @param \DOMDocument $document
   *   The document to mutate in place.
   */
  protected function removeNonContentElements(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);
    $query = implode('|', array_map(
      static fn(string $tag): string => '//body//' . $tag,
      self::REMOVED_TAGS
    ));

    foreach (iterator_to_array($xpath->query($query)) as $element) {
      $element->parentNode?->removeChild($element);
    }
  }

  /**
   * Deletes elements that hold no text at all.
   *
   * Rendered Drupal output is full of these - empty field wrappers, and the
   * page-title heading on a node that shows its title elsewhere. Each one still
   * costs something after conversion: strip_tags turns an empty wrapper into a
   * blank line, and an empty heading still gets its setext underline, which put
   * a bare "=" line and runs of twenty blank lines into indexed content. All of
   * it is charged against the chunk size.
   *
   * Elements are removed, never unwrapped, so unlike an earlier revision this
   * cannot disturb text that abuts inline markup.
   *
   * One pass is enough: XPath returns matches in document order, so an ancestor
   * is judged before its descendants, and textContent already aggregates the
   * text of the whole subtree. Nothing can become empty as a result of this
   * pass, because anything removed contributed no text to begin with.
   *
   * @param \DOMDocument $document
   *   The document to mutate in place.
   */
  protected function removeTextlessElements(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);
    // The keep-list has to protect an ancestor as well as the element itself:
    // a divider renders as a bare <hr> inside a field wrapper, so the wrapper
    // holds no text and the rule is the only content in it. Derived from the
    // one list rather than restated, so the two cannot drift apart.
    $keeps = implode('|', array_map(
      static fn(string $tag): string => './/' . $tag,
      self::TEXTLESS_ELEMENTS_KEPT
    ));

    foreach (iterator_to_array($xpath->query('//body//*')) as $element) {
      if (in_array($element->nodeName, self::TEXTLESS_ELEMENTS_KEPT, TRUE)
        || trim($element->textContent) !== ''
        || $xpath->query($keeps, $element)->length > 0) {
        continue;
      }
      $element->parentNode?->removeChild($element);
    }
  }

  /**
   * Replaces links with a dangerous target by their own text.
   *
   * The converter keeps a link target verbatim, and a chat answer is rendered
   * as Markdown, so a javascript: target left here could come back to a visitor
   * as a live link. The link text is prose and is kept.
   *
   * @param \DOMDocument $document
   *   The document to mutate in place.
   */
  protected function unwrapUnsafeLinks(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);

    foreach (iterator_to_array($xpath->query('//body//a')) as $link) {
      $href = trim($link->getAttribute('href'));
      if ($href === '' || UrlHelper::stripDangerousProtocols($href) === $href) {
        continue;
      }

      $parent = $link->parentNode;
      if ($parent === NULL) {
        continue;
      }
      while ($link->firstChild) {
        $parent->insertBefore($link->firstChild, $link);
      }
      $parent->removeChild($link);
    }
  }

}
