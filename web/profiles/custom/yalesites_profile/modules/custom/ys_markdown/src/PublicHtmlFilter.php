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
   * Inline elements whose edge space moves outside them instead of vanishing.
   */
  const INLINE_ELEMENTS = [
    'a', 'abbr', 'b', 'cite', 'em', 'i', 'mark', 'q', 's', 'small', 'span',
    'strong', 'sub', 'sup', 'time', 'u',
  ];

  /**
   * Most list items kept per listing; the rest are on the web page.
   */
  const LISTING_ITEM_CAP = 50;

  /**
   * Line that replaces a form.
   */
  const FORM_FALLBACK = 'Interactive form: available on the web page.';

  /**
   * Line appended to a listing that was cut or has more pages.
   */
  const MORE_ITEMS_LINE = 'More items are listed on the web page.';

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
    // Before nav removal, which would take the pager with it.
    $this->capListings($document);
    // Before the generic removal, so a form leaves a trace.
    $this->replaceForms($document);
    $this->removeChrome($document);
    $this->replaceIframes($document);
    $this->normalizeWhitespace($document);
    $this->flattenLeadingNestedLists($document);
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
   * Content hidden from screen readers (aria-hidden="true") is decorative, so
   * it is dropped too. Comments (such as Twig debug output) are never
   * content, and the HTML-to-Markdown converter rejects input made of nothing
   * but comments.
   */
  protected function removeChrome(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);
    $query = sprintf(
      '//body//*[self::%s or @%s or @aria-hidden="true" or (self::form and %s)] | //body//comment()',
      implode(' or self::', self::REMOVED_TAGS),
      self::SKIP_ATTRIBUTE,
      self::classTokenXpath('views-exposed-form')
    );
    foreach (iterator_to_array($xpath->query($query)) as $element) {
      $element->parentNode?->removeChild($element);
    }
  }

  /**
   * Replaces each outermost form with a one-line fallback.
   *
   * Views exposed filter forms are left for removeChrome(), which drops them
   * silently as chrome.
   */
  protected function replaceForms(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);
    $query = '//body//form[not(ancestor::form) and not(' . self::classTokenXpath('views-exposed-form') . ')]';
    foreach (iterator_to_array($xpath->query($query)) as $form) {
      $form->parentNode?->replaceChild($document->createElement('p', self::FORM_FALLBACK), $form);
    }
  }

  /**
   * Returns an XPath test for an element carrying a class token.
   */
  protected static function classTokenXpath(string $class): string {
    return "contains(concat(' ', normalize-space(@class), ' '), ' $class ')";
  }

  /**
   * Keeps the first items of each listing and notes when more exist.
   *
   * A listing is a Views block wrapper with the class ys-view. A pager means
   * more pages exist even when the visible list is short.
   */
  protected function capListings(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);
    foreach (iterator_to_array($xpath->query('//body//*[' . self::classTokenXpath('ys-view') . ']')) as $view) {
      $more = $xpath->query('.//*[' . self::classTokenXpath('pager') . ']', $view)->length > 0;
      foreach (iterator_to_array($xpath->query('.//*[(self::ul or self::ol) and not(ancestor::ul or ancestor::ol)]', $view)) as $list) {
        foreach (iterator_to_array($xpath->query('./li[position() > ' . self::LISTING_ITEM_CAP . ']', $list)) as $item) {
          $list->removeChild($item);
          $more = TRUE;
        }
      }
      if ($more) {
        $view->appendChild($document->createElement('p', self::MORE_ITEMS_LINE));
      }
    }
  }

  /**
   * Replaces a list that opens an item with its items' text, comma-joined.
   *
   * Otherwise "- - Category" is the Markdown of a card whose first content,
   * possibly inside wrapper elements, is a category list. The list opens its
   * nearest item when everything before it, at every level up to that item,
   * is blank.
   */
  protected function flattenLeadingNestedLists(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);
    foreach (iterator_to_array($xpath->query('//body//li//*[self::ul or self::ol]')) as $list) {
      // A list nested in an already-replaced list keeps a parent, so test for
      // the body instead of a NULL parentNode.
      if ($xpath->query('ancestor::body', $list)->length === 0 || !$this->opensItem($list)) {
        continue;
      }
      $labels = [];
      foreach ($xpath->query('./li', $list) as $item) {
        $labels[] = trim($item->textContent);
      }
      $list->parentNode->replaceChild($document->createElement('p', htmlspecialchars(implode(', ', array_filter($labels)))), $list);
    }
  }

  /**
   * Whether nothing but blank content precedes a list inside its nearest li.
   */
  protected function opensItem(\DOMElement $list): bool {
    for ($node = $list; $node->nodeName !== 'li'; $node = $node->parentNode) {
      for ($sibling = $node->previousSibling; $sibling; $sibling = $sibling->previousSibling) {
        if (!$this->isBlank($sibling->textContent)) {
          return FALSE;
        }
        if ($sibling instanceof \DOMElement && ($sibling->nodeName === 'img' || $sibling->getElementsByTagName('img')->length > 0)) {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  /**
   * Replaces each iframe with a paragraph naming and linking the embed.
   */
  protected function replaceIframes(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);
    foreach (iterator_to_array($xpath->query('//body//iframe')) as $iframe) {
      $label = trim($iframe->getAttribute('title')) ?: 'embed';
      $src = trim($iframe->getAttribute('src'));
      $src = $this->unwrapOembedProxy($src);
      $paragraph = $document->createElement('p', 'Embedded content: ');
      if ($this->isSafeHttpUrl($src)) {
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
   * Returns the video URL behind a media oEmbed proxy URL, or the URL itself.
   *
   * The proxy is a local endpoint of no use to a reader. The caller checks
   * the returned URL for safety.
   */
  protected function unwrapOembedProxy(string $src): string {
    $parts = parse_url($src);
    if (!str_ends_with($parts['path'] ?? '', '/media/oembed')) {
      return $src;
    }
    parse_str($parts['query'] ?? '', $query);
    $url = $query['url'] ?? NULL;
    return is_string($url) && $url !== '' ? $url : $src;
  }

  /**
   * Whether a URL is http(s) and free of dangerous protocols.
   */
  protected function isSafeHttpUrl(string $url): bool {
    return preg_match('#^https?://#i', $url) === 1 && UrlHelper::stripDangerousProtocols($url) === $url;
  }

  /**
   * Collapses template indentation and trims element edges.
   *
   * Whitespace in text nodes becomes a single space outside pre, code and
   * textarea, then leading and trailing space is trimmed inside every block
   * element so no line starts with the indentation of the Twig source. An
   * inline element's edge space moves outside it, so words stay separated.
   * Children are handled before parents so moved space is trimmed in turn.
   */
  protected function normalizeWhitespace(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);
    $outside = 'not(ancestor::pre or ancestor::code or ancestor::textarea)';
    foreach (iterator_to_array($xpath->query("//body//text()[$outside]")) as $text) {
      $text->nodeValue = preg_replace('/[ \t\r\n\f]+/', ' ', $text->nodeValue);
    }
    $elements = iterator_to_array($xpath->query("//body//*[$outside and not(self::pre or self::code or self::textarea)]"));
    foreach (array_reverse($elements) as $element) {
      $this->trimEdge($element, TRUE);
      $this->trimEdge($element, FALSE);
    }
  }

  /**
   * Trims whitespace from the start or end of an element's content.
   *
   * For an inline element, trimmed space is put back as one space just
   * outside the element, unless a space is already there.
   */
  protected function trimEdge(\DOMElement $element, bool $start): void {
    $trimmedAny = FALSE;
    while ($node = $start ? $element->firstChild : $element->lastChild) {
      if ($node->nodeType !== XML_TEXT_NODE) {
        break;
      }
      $trimmed = $start ? ltrim($node->nodeValue) : rtrim($node->nodeValue);
      $trimmedAny = $trimmedAny || $trimmed !== $node->nodeValue;
      if ($trimmed === '') {
        $element->removeChild($node);
        continue;
      }
      $node->nodeValue = $trimmed;
      break;
    }
    if (!$trimmedAny || !in_array($element->nodeName, self::INLINE_ELEMENTS, TRUE)) {
      return;
    }
    $neighbour = $start ? $element->previousSibling : $element->nextSibling;
    if ($neighbour?->nodeType === XML_TEXT_NODE && $neighbour->nodeValue !== '' && ctype_space($start ? substr($neighbour->nodeValue, -1) : $neighbour->nodeValue[0])) {
      return;
    }
    $space = $element->ownerDocument->createTextNode(' ');
    $element->parentNode?->insertBefore($space, $start ? $element : $element->nextSibling);
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
   * Links to data: URLs are removed with their text. Copied from
   * IndexableHtmlFilter::unwrapUnsafeLinks(), plus kept links and every
   * image are made safe to write as Markdown.
   */
  protected function unwrapUnsafeLinks(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);
    foreach (iterator_to_array($xpath->query('//body//img')) as $image) {
      $this->escapeForMarkdown($image, 'src');
    }
    foreach (iterator_to_array($xpath->query('//body//a')) as $link) {
      $href = trim($link->getAttribute('href'));
      if (stripos($href, 'data:') === 0) {
        // Control text such as "Add to Calendar" means nothing without it.
        $link->parentNode?->removeChild($link);
        continue;
      }
      if ($href === '') {
        continue;
      }
      if (UrlHelper::stripDangerousProtocols($href) === $href) {
        $this->escapeForMarkdown($link, 'href');
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
   * Escapes a link or image's attributes so they cannot close its Markdown.
   *
   * The converter writes attributes verbatim as [text](url "title") and
   * ![alt](url "title"), so a ")" or space in the URL, a quote in the title,
   * or a bracket in the alt text would end the link early and let the rest
   * read as a new, live link. Characters that end a URL are percent-encoded,
   * and the title and alt text are backslash-escaped. Every "&" becomes
   * "&amp;" because MarkdownBuilder decodes entities after conversion, which
   * would otherwise turn "&#41;" back into ")".
   */
  protected function escapeForMarkdown(\DOMElement $element, string $urlAttribute): void {
    $url = preg_replace_callback(
      '/[\x00-\x20\x7F()<>\\\\]/',
      static fn (array $match): string => rawurlencode($match[0]),
      trim($element->getAttribute($urlAttribute))
    );
    $element->setAttribute($urlAttribute, str_replace('&', '&amp;', $url));
    foreach (['title' => '\\"', 'alt' => '\\[]'] as $name => $special) {
      if ($element->hasAttribute($name)) {
        $value = (string) preg_replace('/\s+/u', ' ', $element->getAttribute($name));
        $element->setAttribute($name, str_replace('&', '&amp;', addcslashes($value, $special)));
      }
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
