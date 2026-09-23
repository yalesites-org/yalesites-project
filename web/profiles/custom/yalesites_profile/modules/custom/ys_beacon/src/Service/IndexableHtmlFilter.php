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
 * Deleting is most of what this service does, for the three reasons that
 * conversion cannot handle itself:
 *
 * - Some elements are not prose but their text still survives conversion. A
 *   <script> or <style> body is indexed verbatim as content; a <nav> becomes a
 *   Markdown list of every menu link; an <img> becomes an image tag; a <figure>
 *   runs its caption straight into the following paragraph.
 * - A javascript: link target would otherwise be stored as a working Markdown
 *   link, and chat answers are rendered as Markdown.
 * - A heading that is nothing but a link back to the page being indexed is the
 *   node template's own title, present only because an item is rendered
 *   standalone. See SELF-TITLE HEADINGS below.
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
 *
 * On top of that deletion the service makes exactly two rewrites, and they are
 * why it can no longer be described as only deleting.
 *
 * DISCLOSURE-HEADING BUTTONS. <button> is in REMOVED_TAGS because nearly every
 * button on a page is furniture whose label would pollute the index. The
 * media-grid alone holds four: the thumbnail wrapper ("Open this image in a
 * modal"), the caption toggle, the modal pager ("View item 1", "View item 2",
 * ...) and the prev/next/close controls. Two of those carry real text, so "the
 * button has text content" is not a usable signal for keeping one - that rule
 * would index all of them.
 *
 * The signal that does separate the cases is structural: a button that is the
 * entire content of a heading element is the disclosure-heading pattern, and
 * the text in it is an authored heading rather than a control label. That is
 * the accordion item, and it is the only case on the platform today. Such a
 * button is unwrapped, so the heading keeps its text and the button element
 * goes; every other button is still removed with its contents. The unwrap runs
 * before the removal pass, so the decorative icon that sat inside the button is
 * caught by the ordinary svg rule instead of leaking into the heading.
 *
 * The rule is stated in markup semantics rather than CSS classes so that a
 * future component adopting the same pattern inherits the behaviour without a
 * change here. Do not relax it to "the button has text".
 *
 * ANCHORED HEADINGS. An id on a heading is HTML's own way of saying "this
 * heading is a linkable landmark on this page", so when the caller supplies the
 * page's absolute URL the heading's content is wrapped in a link to
 * "<page-url>#<id>". Accordion items and gallery captions take those ids from
 * their paragraph entity, which is what lets a chat answer cite the one item an
 * answer came from rather than the page as a whole.
 *
 * The link has to be an <a> element and the target has to be absolute. A
 * literal "[text](url)" written into a text node would be escaped by
 * TextConverter into "\[text\](url)"; a bare "#id" would resolve against the
 * chat page rather than the source page; and an empty href would be dropped by
 * strip_placeholder_links. A heading that already contains a link is left
 * alone, and with no page URL nothing is linked at all.
 *
 * SELF-TITLE HEADINGS. Search API's rendered_item field renders an item on its
 * own in view mode 'default', not as a page. Core's node.html.twig takes its
 * `{% if label and not page %}` branch in that situation and emits
 * `<h2><a href="{{ url }}" rel="bookmark">{{ label }}</a></h2>` above the
 * node's fields, because template_preprocess_node() only sets `page` for view
 * mode 'full' on the node's own route. A visitor therefore never sees that
 * heading, and it duplicates the title the display's own meta block renders as
 * an <h1> - so an indexed chunk carried the page title twice before the
 * contextual "Title:" line even added its own copy
 * (YaleSites-Internal#1665).
 *
 * The rule is that a heading whose entire content is a link back to the page
 * being indexed is that title, and it is removed. Both halves are load-bearing
 * and neither is sufficient alone: "the whole heading is one link" also
 * describes an editor's heading-sized link into real content, and "links to
 * this page" also describes an ordinary in-page reference in prose. Only
 * together do they mean "this page announcing itself", which is chrome.
 *
 * "Entire content" is measured in text, exactly as the disclosure-button rule
 * measures it, so a heading holding words of its own is kept. Removing the
 * heading rather than unwrapping its link is the point: unwrapping would leave
 * the duplicated title behind as plain text, which is the thing being fixed.
 * Nothing is lost by the removal even for a display with no meta block, since
 * the title also reaches every chunk as contextual content.
 *
 * Like the anchored-heading rewrite this needs the caller's page URL, and with
 * no page URL nothing is removed - the filter is reachable on the search-query
 * path too, where guessing would risk deleting an authored heading.
 *
 * Being an inference from markup, the rule has one false positive worth
 * knowing about: a page that lists itself. Every card and list-item title on
 * the platform is rendered by yds-heading.twig as a heading wrapping a single
 * link, so a Content list or Related content block that happens to include the
 * node it sits on produces a heading that is entirely a link back to this
 * page, and that card loses its heading from the index. The rest of the card
 * still reaches the index where it has any - a card with no teaser or metadata
 * contributes nothing at all - and the removed text is by definition the
 * page's own title, which the chunk carries anyway. That, plus the fact that
 * the two-conjunct test is the narrowest honest way to state the rule, is why
 * this is accepted rather than worked around.
 *
 * Fixing this where it is emitted was considered and rejected. Rendering the
 * item in view mode 'full' does not help: template_preprocess_node() gates
 * `page` on node_is_page() as well as the view mode, and indexing runs with no
 * node route, so the branch still fires - and the bundles' *--full templates
 * drop indexable fields (event dates, author) that the index needs. A
 * hook_preprocess_node() in ys_beacon could only key on the view mode, which
 * would make an optional per-site module silently change rendering for
 * node_index, secure_index and every "Rendered entity - Default" view; and a
 * node--default template override lives in the atomic repo, so it would ship
 * on a different release cycle and depend on the site's default theme. Only
 * this index runs this processor, so only Beacon's citation-facing Markdown is
 * affected - which is the intent.
 */
class IndexableHtmlFilter {

  /**
   * Elements deleted along with their contents.
   *
   * Every one of these survives conversion in some form when left in place -
   * as leaked script text, an image tag, a menu rendered as a list, or a
   * caption run into the next paragraph.
   *
   * One entry is conditional: anything also listed in
   * UNWRAPPED_AS_HEADING_CONTENT is kept, minus its own element, in the one
   * position where it holds authored prose. Read that constant before
   * changing this one.
   */
  const REMOVED_TAGS = [
    'audio', 'button', 'canvas', 'embed', 'figure', 'form', 'iframe', 'img',
    'input', 'nav', 'noscript', 'object', 'picture', 'script', 'select',
    'source', 'style', 'svg', 'template', 'textarea', 'track', 'video',
  ];

  /**
   * Heading elements, in the order HTML defines them.
   *
   * Every heading rule keys off this list: a button is content only when it is
   * the whole of one, an id is a linkable landmark only when it is on one, and
   * a self-referential title link is chrome only when it is the whole of one.
   */
  const HEADING_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

  /**
   * Elements in REMOVED_TAGS that survive as the entire content of a heading.
   *
   * The disclosure-heading exception, stated as data next to the rule it
   * qualifies so that REMOVED_TAGS cannot be read as unconditional. Such an
   * element is unwrapped rather than removed: its text is the authored
   * heading. See the class docblock for why the test is structural and must
   * not be relaxed to "the element has text content".
   */
  const UNWRAPPED_AS_HEADING_CONTENT = ['button'];

  /**
   * Ids that may be used as a deep-link target.
   *
   * The linking rule is deliberately general - any heading carrying an id -
   * and basic_html lets an editor put an arbitrary id on an h2-h6 through
   * CKEditor's anchor plugin. That value ends up as the destination of a
   * Markdown link rendered in a chat answer, where a stray ')' closes the
   * destination early, a '[' opens a new link, and a bidi control character
   * makes the visible target disagree with the real one. Serialization
   * already prevents an attribute breakout, so this is defence in depth
   * against the Markdown layer rather than against markup injection.
   *
   * Restricting to a plain HTML id token costs nothing real: the ids this
   * feature generates are "accordion-item-<n>" and "gallery-item-<n>", and
   * an editor's odd anchor simply goes unlinked rather than breaking a
   * citation.
   */
  const LINKABLE_ID_PATTERN = '/^[A-Za-z0-9_:.-]+$/';

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
   * Matches text a browser renders as nothing but blank space.
   *
   * PHP's trim() charlist covers only ASCII whitespace, so a non-breaking
   * space read as text and kept elements alive that hold no content at all.
   * The codepoints listed here are the ones that reach rendered Drupal output
   * as spacers - `&nbsp;` above all - together with the zero-width characters
   * that occupy no space whatsoever. PCRE's `\s` is left in for the ASCII set
   * and the rest are named explicitly, because `\s` does not match these
   * without the Unicode-properties flag.
   */
  const BLANK_TEXT_PATTERN = '/^[\s\x{00A0}\x{1680}\x{2000}-\x{200B}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]*$/u';

  /**
   * Filters rendered HTML down to what belongs in the index.
   *
   * @param string $html
   *   The rendered HTML for one indexed item.
   * @param string|null $pageUrl
   *   The absolute URL of the page this HTML was rendered from, when the
   *   caller knows it. Two rules need it: headings carrying an id are linked
   *   to their own fragment of that URL, and a heading that is nothing but a
   *   link back to that URL is dropped as the node template's own title.
   *   Without it, headings are kept unlinked - half a link is worse to a
   *   reader than none - and none are dropped, since there is then nothing to
   *   recognise a self-link against.
   *
   * @return string
   *   The same HTML with non-content elements and unusable links removed, and
   *   with the two rewrites described on this class applied.
   */
  public function filter(string $html, ?string $pageUrl = NULL): string {
    // Entities are decoded first so that markup consisting only of `&nbsp;`
    // spacers is recognised as blank here rather than surviving as text.
    if ($this->isBlank(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) {
      return '';
    }

    $document = Html::load($html);
    // Before the removal pass, so the icon left behind inside the heading is
    // taken by the ordinary svg rule rather than surviving as heading text.
    $this->unwrapDisclosureHeadingButtons($document);
    $this->removeNonContentElements($document);
    $this->removeTextlessElements($document);
    // After the removal passes, so an emptied heading is never given a link,
    // and before the unsafe-link pass, so a link built here is held to the
    // same protocol check as one that came in with the markup.
    $page = trim($pageUrl ?? '');
    if ($page !== '') {
      // Deletions before rewrites, matching the order of the passes above.
      $this->removeSelfTitleHeadings($document, $page);
      $this->linkAnchoredHeadings($document, $page);
    }
    $this->unwrapUnsafeLinks($document);

    return trim(Html::serialize($document));
  }

  /**
   * Unwraps buttons that are the entire content of a heading.
   *
   * The disclosure-heading pattern: the accordion renders an item's authored
   * heading as the label of its toggle button, so removing the button with its
   * contents - which is right for every other button on the platform - threw
   * the heading away and left an empty <h3> for the textless pass to drop.
   *
   * "Entire content" is measured in text: a sibling that contributes no text,
   * such as a decorative icon, does not disqualify the button, but any prose
   * of the heading's own does. A heading holding its own words alongside a
   * button is not a disclosure heading, and that button is a control.
   *
   * See the class docblock for why this cannot be relaxed to "the button has
   * text content".
   *
   * @param \DOMDocument $document
   *   The document to mutate in place.
   */
  protected function unwrapDisclosureHeadingButtons(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);
    $query = sprintf(
      '//body//*[%s]/*[%s]',
      $this->anyOf(self::HEADING_TAGS),
      $this->anyOf(self::UNWRAPPED_AS_HEADING_CONTENT)
    );

    foreach (iterator_to_array($xpath->query($query)) as $button) {
      $heading = $button->parentNode;
      if ($heading === NULL || !$this->holdsAllTextOf($button, $heading)) {
        continue;
      }
      $this->unwrapElement($button, $heading);
    }
  }

  /**
   * Replaces an element with its own children, keeping their order.
   *
   * @param \DOMNode $element
   *   The element to remove.
   * @param \DOMNode $parent
   *   Its parent, which adopts the children in its place.
   */
  protected function unwrapElement(\DOMNode $element, \DOMNode $parent): void {
    while ($element->firstChild) {
      $parent->insertBefore($element->firstChild, $element);
    }
    $parent->removeChild($element);
  }

  /**
   * Builds an XPath predicate matching any of a set of element names.
   *
   * A single predicate rather than a union of absolute paths: libxml walks a
   * union once per branch, and this runs over every indexed value on a site.
   *
   * @param string[] $tags
   *   The element names to match.
   *
   * @return string
   *   An XPath predicate body, without its surrounding brackets.
   */
  protected function anyOf(array $tags): string {
    return 'self::' . implode(' or self::', $tags);
  }

  /**
   * Whether an element accounts for all of its parent's text.
   *
   * @param \DOMNode $element
   *   The candidate element.
   * @param \DOMNode $parent
   *   Its parent.
   *
   * @return bool
   *   TRUE when no sibling contributes text a reader would see.
   */
  protected function holdsAllTextOf(\DOMNode $element, \DOMNode $parent): bool {
    foreach ($parent->childNodes as $sibling) {
      if ($sibling !== $element && !$this->isBlank($sibling->textContent)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Removes headings that are only a link back to the page being indexed.
   *
   * The node template's own title, which exists in the indexed render and
   * nowhere a visitor looks. See the class docblock for why both halves of the
   * test are needed and why the heading is removed rather than unwrapped.
   *
   * @param \DOMDocument $document
   *   The document to mutate in place.
   * @param string $pageUrl
   *   The absolute URL of the page, already known to be non-empty.
   */
  protected function removeSelfTitleHeadings(\DOMDocument $document, string $pageUrl): void {
    $xpath = new \DOMXPath($document);
    $query = '//body//*[' . $this->anyOf(self::HEADING_TAGS) . ']/a[@href]';

    foreach (iterator_to_array($xpath->query($query)) as $link) {
      $heading = $link->parentNode;
      if ($this->holdsAllTextOf($link, $heading)
        && $this->targetsPage($link->getAttribute('href'), $pageUrl)) {
        $heading->parentNode?->removeChild($heading);
      }
    }
  }

  /**
   * Whether a link target names the page being indexed.
   *
   * The target is compared against the page both as a whole URL and against
   * the page's path alone, because Drupal's URL generation emits either form.
   * No branch is needed to reject the other shapes, because neither
   * comparison can accept them: a link to another host differs from the page
   * in its origin, and a document-relative target has no leading slash while
   * a path taken from the page URL always does.
   *
   * Two targets are refused outright. A bare fragment does name this page, but
   * a heading wrapping one is the anchored-heading pattern rather than a
   * title. A protocol-relative target is refused because trimming a trailing
   * slash would otherwise reduce a bare "//" onto the site root.
   *
   * @param string $href
   *   The link's href attribute.
   * @param string $pageUrl
   *   The absolute URL of the page.
   *
   * @return bool
   *   TRUE when the href resolves to the page itself.
   */
  protected function targetsPage(string $href, string $pageUrl): bool {
    $target = trim($this->withoutFragment($href));
    if ($target === '' || str_starts_with($target, '//')) {
      return FALSE;
    }

    // Trailing slashes are dropped throughout so that "/a/b/" and "/a/b" are
    // recognised as the same page.
    $page = $this->withoutFragment($pageUrl);
    $target = rtrim($target, '/');

    if ($target === rtrim($page, '/')) {
      return TRUE;
    }

    // The page's path is only a safe thing to compare against while the page
    // URL carries no query of its own, because parse_url() would discard that
    // query and make a link to the bare path - a different view of the page -
    // look like the page itself. No caller produces such a URL today
    // (EntityCitationResolver returns a canonical entity URL or a file URL),
    // but this rule deletes content, so it does not lean on that.
    if (parse_url($page, PHP_URL_QUERY) !== NULL) {
      return FALSE;
    }

    return $target === rtrim((string) parse_url($page, PHP_URL_PATH), '/');
  }

  /**
   * Drops any fragment from a URL.
   *
   * A fragment identifies a place on a page rather than a different page, so
   * it plays no part in deciding which page a URL names.
   *
   * @param string $url
   *   The URL to strip.
   *
   * @return string
   *   The same URL without its fragment.
   */
  protected function withoutFragment(string $url): string {
    return explode('#', $url, 2)[0];
  }

  /**
   * Links every heading carrying an id to its own fragment of the page.
   *
   * A real <a> element rather than Markdown text, and an absolute target
   * rather than a bare fragment - see the class docblock for what each of
   * those guards against downstream.
   *
   * A heading that already holds a link is skipped: nesting one link inside
   * another produces markup the converter cannot render, and the author's own
   * link is the more specific destination anyway.
   *
   * @param \DOMDocument $document
   *   The document to mutate in place.
   * @param string $pageUrl
   *   The absolute URL of the page, already known to be non-empty.
   */
  protected function linkAnchoredHeadings(\DOMDocument $document, string $pageUrl): void {
    $xpath = new \DOMXPath($document);
    $query = '//body//*[' . $this->anyOf(self::HEADING_TAGS) . '][@id]';
    // A canonical entity URL carries no fragment, but appending a second one
    // to a URL that did would produce a target no browser resolves.
    $base = $this->withoutFragment($pageUrl);

    foreach (iterator_to_array($xpath->query($query)) as $heading) {
      $id = trim($heading->getAttribute('id'));
      if (preg_match(self::LINKABLE_ID_PATTERN, $id) !== 1
        || $this->isBlank($heading->textContent)
        || $xpath->query('.//a', $heading)->length > 0) {
        continue;
      }

      $link = $document->createElement('a');
      $link->setAttribute('href', $base . '#' . $id);
      while ($heading->firstChild) {
        $link->appendChild($heading->firstChild);
      }
      $this->trimEdgeText($link);
      $heading->appendChild($link);
    }
  }

  /**
   * Strips whitespace from the very start and end of an element's text.
   *
   * Rendered markup indents a heading's text inside its wrapper, and that
   * indentation would otherwise land inside the link text as
   * "[ Heading ](url)" - a working link that reads as a typo in an answer.
   * Only the outermost edges are touched, so no space between words moves.
   *
   * Adjacent text nodes are merged first: unwrapping the disclosure button
   * leaves the heading's indentation and its text as separate neighbouring
   * nodes, and trimming only the outermost of those would empty the indent
   * node and leave the text node's own leading space behind.
   *
   * @param \DOMNode $element
   *   The element to trim, which by this point holds all of the heading.
   */
  protected function trimEdgeText(\DOMNode $element): void {
    $element->normalize();

    if ($element->firstChild instanceof \DOMText) {
      $element->firstChild->nodeValue = ltrim($element->firstChild->nodeValue);
    }
    if ($element->lastChild instanceof \DOMText) {
      $element->lastChild->nodeValue = rtrim($element->lastChild->nodeValue);
    }
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
   * "Holds no text" has to count a non-breaking space as blank, which is why
   * this uses isBlank() rather than trim(). An element holding only `&nbsp;` is
   * a spacer, and letting it survive corrupted the markdown outright rather
   * than merely wasting budget: each spacer converts to a single space, and
   * TextConverter only discards a collapsed space when the next sibling is a
   * block - so between inline spacers the spaces are kept and accumulate ahead
   * of the following block. Two spacers put that block at four columns, the
   * point where CommonMark reads the line as an indented code block. In the
   * citation panel that rendered a heading as a bordered monospace box, its
   * setext underline degraded to a horizontal rule.
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
        || !$this->isBlank($element->textContent)
        || $xpath->query($keeps, $element)->length > 0) {
        continue;
      }
      $element->parentNode?->removeChild($element);
    }
  }

  /**
   * Whether text is blank once spacer characters are taken into account.
   *
   * @param string $text
   *   The text to test.
   *
   * @return bool
   *   TRUE when the text holds nothing a reader would see.
   */
  protected function isBlank(string $text): bool {
    return preg_match(self::BLANK_TEXT_PATTERN, $text) === 1;
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
      $this->unwrapElement($link, $parent);
    }
  }

}
