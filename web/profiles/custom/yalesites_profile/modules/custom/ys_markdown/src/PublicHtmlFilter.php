<?php

namespace Drupal\ys_markdown;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;

/**
 * Prepares rendered node HTML for public Markdown conversion.
 *
 * Unlike the Beacon index filter, this keeps image alt text, captions and
 * embeds (as a text fallback), because the output is read by people and by
 * tools that rely on them. The final three passes are copied from
 * ys_beacon's IndexableHtmlFilter on purpose: that class is built for a
 * different audience and its removal list is not shared.
 */
class PublicHtmlFilter {

  /**
   * Elements removed with their contents.
   */
  const REMOVED_TAGS = ['script', 'style', 'noscript', 'template', 'nav', 'button', 'form'];

  /**
   * Attribute that marks an element as page chrome to leave out.
   */
  const SKIP_ATTRIBUTE = 'data-markdown-skip';

  /**
   * Elements kept even when they hold no text.
   */
  const TEXTLESS_ELEMENTS_KEPT = [
    'br', 'hr', 'img', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr',
  ];

  /**
   * Matches text a browser renders as nothing but blank space.
   */
  const BLANK_TEXT_PATTERN = '/^[\s\x{00A0}\x{1680}\x{2000}-\x{200B}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]*$/u';

  /**
   * Elements whose whitespace is significant and left alone.
   */
  const PRESERVE_WHITESPACE = ['pre', 'code', 'textarea'];

  /**
   * Filters rendered HTML for public Markdown output.
   *
   * @param string $html
   *   The rendered HTML.
   * @param string|null $title
   *   The node title. Every heading with this text is removed, because the
   *   Markdown document supplies its own title heading.
   */
  public function filter(string $html, ?string $title = NULL): string {
    if ($this->isBlank(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) {
      return '';
    }
    $document = Html::load($html);
    // Before the removal pass: an accordion renders its heading as a button,
    // and removing the button would throw the heading away.
    $this->unwrapDisclosureHeadingButtons($document);
    $this->removeChrome($document);
    $this->replaceIframes($document);
    $this->normalizeWhitespace($document);
    if ($title !== NULL) {
      $this->removeTitleHeadings($document, $title);
    }
    $this->removeTextlessElements($document);
    $this->unwrapUnsafeLinks($document);
    return trim(Html::serialize($document));
  }

  /**
   * Removes non-content elements, anything marked to skip, and comments.
   *
   * Comments (such as Twig debug output) are never content, and the
   * HTML-to-Markdown converter rejects input made of nothing but comments.
   */
  protected function removeChrome(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);
    $query = sprintf('//body//*[self::%s or @%s] | //body//comment()', implode(' or self::', self::REMOVED_TAGS), self::SKIP_ATTRIBUTE);
    foreach (iterator_to_array($xpath->query($query)) as $element) {
      $element->parentNode?->removeChild($element);
    }
  }

  /**
   * Replaces each iframe with a paragraph naming and linking the embed.
   */
  protected function replaceIframes(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);
    foreach (iterator_to_array($xpath->query('//body//iframe')) as $iframe) {
      $label = trim($iframe->getAttribute('title')) ?: 'embed';
      $src = trim($iframe->getAttribute('src'));
      $paragraph = $document->createElement('p', 'Embedded content: ');
      if (preg_match('#^https?://#i', $src) && UrlHelper::stripDangerousProtocols($src) === $src) {
        $link = $document->createElement('a');
        $link->setAttribute('href', $src);
        $link->appendChild($document->createTextNode($label));
        $paragraph->appendChild($link);
      }
      else {
        $paragraph->appendChild($document->createTextNode($label));
      }
      $iframe->parentNode?->replaceChild($paragraph, $iframe);
    }
  }

  /**
   * Collapses template indentation and trims element edges.
   *
   * Whitespace in text nodes becomes a single space outside pre, code and
   * textarea, then leading and trailing space is trimmed inside every element
   * so no line starts with the indentation of the Twig source.
   */
  protected function normalizeWhitespace(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);
    $outside = 'not(ancestor::pre or ancestor::code or ancestor::textarea)';
    foreach (iterator_to_array($xpath->query("//body//text()[$outside]")) as $text) {
      $text->nodeValue = preg_replace('/[ \t\r\n\f]+/', ' ', $text->nodeValue);
    }
    foreach (iterator_to_array($xpath->query("//body//*[$outside and not(self::pre or self::code or self::textarea)]")) as $element) {
      $this->trimEdge($element, TRUE);
      $this->trimEdge($element, FALSE);
    }
  }

  /**
   * Trims whitespace from the start or end of an element's content.
   */
  protected function trimEdge(\DOMElement $element, bool $start): void {
    while ($node = $start ? $element->firstChild : $element->lastChild) {
      if ($node->nodeType !== XML_TEXT_NODE) {
        return;
      }
      $trimmed = $start ? ltrim($node->nodeValue) : rtrim($node->nodeValue);
      if ($trimmed === '') {
        $element->removeChild($node);
        continue;
      }
      $node->nodeValue = $trimmed;
      return;
    }
  }

  /**
   * Removes every heading whose text equals the title.
   */
  protected function removeTitleHeadings(\DOMDocument $document, string $title): void {
    $normalize = static fn (string $text): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    $title = $normalize($title);
    $xpath = new \DOMXPath($document);
    foreach (iterator_to_array($xpath->query('//body//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]')) as $heading) {
      if ($normalize($heading->textContent) === $title) {
        $heading->parentNode?->removeChild($heading);
      }
    }
  }

  /**
   * Removes elements that hold no text and none of the kept elements.
   *
   * Copied from IndexableHtmlFilter::removeTextlessElements(), plus img so a
   * picture with alt text is not mistaken for an empty wrapper.
   */
  protected function removeTextlessElements(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);
    $keeps = implode('|', array_map(
      static fn(string $tag): string => './/' . $tag,
      self::TEXTLESS_ELEMENTS_KEPT
    ));
    foreach (iterator_to_array($xpath->query('//body//*')) as $element) {
      if (in_array($element->nodeName, self::TEXTLESS_ELEMENTS_KEPT, TRUE)
        || !$this->isBlank($element->textContent)
        || $xpath->query($keeps, $element)->length > 0) {
        continue;
      }
      $element->parentNode?->removeChild($element);
    }
  }

  /**
   * Whether text is blank once spacer characters are taken into account.
   */
  protected function isBlank(string $text): bool {
    return preg_match(self::BLANK_TEXT_PATTERN, $text) === 1;
  }

  /**
   * Replaces links with a dangerous target by their own text.
   *
   * Copied from IndexableHtmlFilter::unwrapUnsafeLinks().
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
      $this->unwrapElement($link, $parent);
    }
  }

  /**
   * Unwraps buttons that are the entire text of a heading.
   *
   * Copied from IndexableHtmlFilter::unwrapDisclosureHeadingButtons().
   */
  protected function unwrapDisclosureHeadingButtons(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);
    foreach (iterator_to_array($xpath->query('//body//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]/button')) as $button) {
      $heading = $button->parentNode;
      if ($heading === NULL) {
        continue;
      }
      $ownsAllText = TRUE;
      foreach ($heading->childNodes as $sibling) {
        if ($sibling !== $button && !$this->isBlank($sibling->textContent)) {
          $ownsAllText = FALSE;
        }
      }
      if ($ownsAllText) {
        $this->unwrapElement($button, $heading);
      }
    }
  }

  /**
   * Replaces an element with its own children, keeping their order.
   */
  protected function unwrapElement(\DOMNode $element, \DOMNode $parent): void {
    while ($element->firstChild) {
      $parent->insertBefore($element->firstChild, $element);
    }
    $parent->removeChild($element);
  }

}
