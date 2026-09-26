<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\IndexableHtmlFilter;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Tests the filter that prepares rendered HTML for the Beacon index.
 *
 * The filter only earns its place by what comes out of ai_search's converter
 * afterwards, so most of these assert on the converted Markdown rather than on
 * the intermediate HTML. The converter is built here exactly as
 * EmbeddingStrategyPluginBase builds it - see productionConverter() - because
 * its options are what decide the outcome, and a differently configured
 * converter would quietly pin the wrong behaviour.
 *
 * @group ys_beacon
 */
class IndexableHtmlFilterTest extends UnitTestCase {

  /**
   * The absolute URL of the page the fixtures are pretending to come from.
   *
   * Deep links have to be absolute, so the tests need a page to be absolute
   * against.
   */
  const PAGE_URL = 'https://example.yale.edu/about/faq';

  /**
   * The filter under test.
   *
   * @var \Drupal\ys_beacon\Service\IndexableHtmlFilter
   */
  protected IndexableHtmlFilter $filter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->filter = new IndexableHtmlFilter();
  }

  /**
   * Builds the converter ai_search actually uses at index time.
   *
   * EmbeddingStrategyPluginBase constructs an HtmlConverter and then mutates
   * that same instance in its constructor: strip_tags and
   * strip_placeholder_links on, plus a TableConverter the library does not
   * register by default. Those three lines are why tables become pipe tables
   * and why unknown wrappers unwrap on their own, so a bare HtmlConverter here
   * would test something production never runs.
   */
  private function productionConverter(): HtmlConverter {
    $converter = new HtmlConverter();
    $converter->getConfig()->setOption('strip_tags', TRUE);
    $converter->getConfig()->setOption('strip_placeholder_links', TRUE);
    $converter->getEnvironment()->addConverter(new TableConverter());

    return $converter;
  }

  /**
   * Filters HTML and converts it the way the indexer does.
   */
  private function toMarkdown(string $html): string {
    return trim($this->productionConverter()->convert($this->filter->filter($html)));
  }

  /**
   * Text that survives conversion but is not content is removed.
   *
   * Each of these reaches the indexed chunk when left in place: script and
   * style bodies as verbatim text, an image as an image tag, a menu as a list
   * of links, and a figure caption run into the next paragraph.
   */
  public function testRemovesMarkupThatIsNotContent(): void {
    $markdown = $this->toMarkdown(
      '<nav><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav>'
      . '<p>Real content.</p>'
      . '<figure><img src="/hero.jpg" alt="Sterling Library"><figcaption>Photo by Yale Photography</figcaption></figure>'
      . '<script>var tracking = 1;</script><style>.a{color:red}</style>'
    );

    $this->assertStringContainsString('Real content.', $markdown);
    $this->assertStringNotContainsString('tracking', $markdown);
    $this->assertStringNotContainsString('color:red', $markdown);
    $this->assertStringNotContainsString('hero.jpg', $markdown);
    $this->assertStringNotContainsString('Sterling Library', $markdown);
    $this->assertStringNotContainsString('Photo by Yale Photography', $markdown);
    $this->assertStringNotContainsString('Home', $markdown);
    $this->assertStringNotContainsString('About', $markdown);
  }

  /**
   * A dangerous link target never reaches the index; its text does.
   *
   * Chat answers are rendered as Markdown, so the converter storing
   * "[Click](javascript:alert(1))" would hand a visitor a live link.
   */
  public function testNeutralisesDangerousLinkTargets(): void {
    $markdown = $this->toMarkdown('<p>Before <a href="javascript:alert(1)">Click</a> after.</p>');

    $this->assertStringNotContainsString('javascript:', $markdown);
    $this->assertStringContainsString('Click', $markdown);
    $this->assertStringContainsString('Before', $markdown);
  }

  /**
   * Ordinary links keep their target.
   */
  public function testKeepsOrdinaryLinks(): void {
    $markdown = $this->toMarkdown(
      '<p>See the <a href="https://gsas.yale.edu/apply">application portal</a>.</p>'
    );

    $this->assertStringContainsString('[application portal](https://gsas.yale.edu/apply)', $markdown);
  }

  /**
   * Inline markup inside a word does not split that word.
   *
   * Regression guard. An earlier revision unwrapped non-structural elements
   * itself and inserted a separating space, which turned
   * "un<span>believable</span>" into "un believable" in real prose. The
   * converter's own strip_tags handles this correctly, so the filter must
   * leave inline wrappers alone.
   */
  public function testInlineMarkupDoesNotSplitWords(): void {
    $this->assertStringContainsString(
      'unbelievable',
      $this->toMarkdown('<p>This is un<span class="highlight">believable</span> really.</p>')
    );
    $this->assertStringContainsString(
      'HelloWorldFoo',
      $this->toMarkdown('<p>Hello<span>World</span>Foo</p>')
    );
  }

  /**
   * Adjacent blocks stay separated once their wrappers are gone.
   *
   * This is the defect the issue reported: with all tags stripped, adjacent
   * blocks were concatenated with no separator at all.
   */
  public function testAdjacentBlocksStaySeparated(): void {
    $markdown = $this->toMarkdown(
      '<article><div><p>Block A</p></div><div><p>Block B</p></div></article>'
    );

    $this->assertStringNotContainsString('Block ABlock B', $markdown);
    $this->assertMatchesRegularExpression('/Block A\s*\n\s*\n\s*Block B/', $markdown);
  }

  /**
   * A table becomes a Markdown pipe table.
   *
   * The ai_search module registers the library's TableConverter, which the
   * default environment omits. Before this change the cells collapsed into
   * "DeadlineTermJan 15Fall".
   */
  public function testTableBecomesMarkdownTable(): void {
    $markdown = $this->toMarkdown(
      '<table><thead><tr><th>Deadline</th><th>Term</th></tr></thead>'
      . '<tbody><tr><td>Jan 15</td><td>Fall</td></tr></tbody></table>'
    );

    $this->assertStringContainsString('| Deadline | Term |', $markdown);
    $this->assertStringContainsString('| Jan 15 | Fall |', $markdown);
    $this->assertStringNotContainsString('DeadlineTerm', $markdown);
    $this->assertStringNotContainsString('Jan 15Fall', $markdown);
  }

  /**
   * The end-to-end result: real Markdown reaches the model.
   */
  public function testProducesMarkdownForRealContent(): void {
    $markdown = $this->toMarkdown(
      '<article><h2>Admissions Requirements</h2>'
      . '<p>Submit the <strong>full packet</strong> by the <em>posted</em> deadline.</p>'
      . '<ul><li>Transcript</li><li>Two letters of recommendation</li></ul></article>'
    );

    $this->assertStringContainsString('Admissions Requirements', $markdown);
    $this->assertStringContainsString('**full packet**', $markdown);
    $this->assertStringContainsString('*posted*', $markdown);
    $this->assertMatchesRegularExpression('/^- Transcript$/m', $markdown);
    $this->assertMatchesRegularExpression('/^- Two letters of recommendation$/m', $markdown);
    // A real Markdown heading, atx or setext, rather than bare text.
    $this->assertMatchesRegularExpression('/(^#+ Admissions Requirements$)|(^-{3,}$)/m', $markdown);
  }

  /**
   * Our Markdown is never backslash-escaped.
   *
   * Guards the trap this change had to avoid: emitting Markdown from the filter
   * would send it through the converter a second time, and TextConverter would
   * escape it into "\*\*bold\*\*".
   */
  public function testMarkdownIsNotBackslashEscaped(): void {
    $markdown = $this->toMarkdown('<p>Submit the <strong>full packet</strong> now.</p>');

    $this->assertStringContainsString('**full packet**', $markdown);
    $this->assertStringNotContainsString('\\*\\*', $markdown);
  }

  /**
   * Twig theme debug comments never reach the index.
   */
  public function testThemeDebugCommentsDoNotReachTheIndex(): void {
    $markdown = $this->toMarkdown(
      "<!-- THEME DEBUG -->\n<!-- FILE NAME SUGGESTIONS: node.html.twig -->\n<p>Body copy.</p>"
    );

    $this->assertSame('Body copy.', $markdown);
  }

  /**
   * Blocks holding no text are dropped.
   *
   * Both shapes here are taken from real rendered output. Drupal renders the
   * page-title heading empty on a node whose title is displayed elsewhere, and
   * the converter still emits its setext underline, which put a bare "=" line
   * into indexed content. The empty paragraphs are pure blank-line noise
   * charged against the chunk size.
   */
  public function testDropsBlocksHoldingNoText(): void {
    $markdown = $this->toMarkdown(
      "<h1 class=\"page-title__heading\">\n    </h1><p></p><p>   </p><p>Real copy.</p>"
    );

    $this->assertSame('Real copy.', $markdown);
  }

  /**
   * Empty wrappers do not become runs of blank lines.
   *
   * With strip_tags on, each surviving empty wrapper becomes a blank line, and
   * real pages nest enough of them to put twenty consecutive blank lines
   * between two paragraphs - all of it charged against the chunk size.
   */
  public function testEmptyWrappersDoNotBecomeBlankLineRuns(): void {
    $markdown = $this->toMarkdown(
      '<p>First.</p>'
      . '<div></div><div>   </div><section><div><span></span></div></section><div></div>'
      . '<p>Second.</p>'
    );

    $this->assertStringContainsString('First.', $markdown);
    $this->assertStringContainsString('Second.', $markdown);
    $this->assertDoesNotMatchRegularExpression('/\n{3,}/', $markdown);
  }

  /**
   * A wrapped horizontal rule survives.
   *
   * A divider component renders as a bare <hr> inside a field wrapper, so the
   * wrapper holds no text and the rule is the only content in it. The keep-list
   * is the single source of truth for what counts as content when textless, so
   * it has to protect an ancestor as well as the element itself.
   */
  public function testKeepsWrappedHorizontalRule(): void {
    $markdown = $this->toMarkdown('<p>Above.</p><div><hr></div><p>Below.</p>');

    $this->assertStringContainsString('Above.', $markdown);
    $this->assertStringContainsString('Below.', $markdown);
    $this->assertMatchesRegularExpression('/^-{3,}$/m', $markdown);
  }

  /**
   * A table with no text still keeps its cells.
   */
  public function testKeepsTableStructure(): void {
    $markdown = $this->toMarkdown(
      '<table><thead><tr><th>Deadline</th><th>Term</th></tr></thead>'
      . '<tbody><tr><td>Jan 15</td><td></td></tr></tbody></table>'
    );

    $this->assertStringContainsString('| Deadline | Term |', $markdown);
    $this->assertStringContainsString('| Jan 15 |', $markdown);
  }

  /**
   * An empty list item does not become a stray bullet.
   */
  public function testDropsEmptyListItems(): void {
    $markdown = $this->toMarkdown('<ul><li></li><li>Only item</li></ul>');

    $this->assertMatchesRegularExpression('/^- Only item$/m', $markdown);
    $this->assertSame(1, preg_match_all('/^- /m', $markdown));
  }

  /**
   * Non-breaking-space spacers do not indent the block that follows them.
   *
   * An element holding only a non-breaking space is a spacer, not content, but
   * PHP's trim() charlist does not include U+00A0, so it used to read as text
   * and survive. Each survivor converts to a single space that TextConverter
   * keeps, because it only drops a collapsed space when the next sibling is a
   * block - and the next sibling here is another inline spacer. The spaces
   * therefore accumulate ahead of the following block: two spacers reach four
   * columns, which is the threshold at which CommonMark reads the line as an
   * indented code block. That is what rendered a heading as a bordered
   * monospace box in the citation panel, with its setext underline degraded to
   * a horizontal rule.
   *
   * @dataProvider spacerCountProvider
   */
  public function testNonBreakingSpaceSpacersDoNotIndentTheNextBlock(int $spacers): void {
    // Two things this fixture needs in order to be able to fail at all.
    // Pretty-printed, the way rendered Drupal output arrives: the newline and
    // indentation between elements are what the surviving spacers add their
    // spaces to, so without that whitespace there is nothing to accumulate.
    // And a paragraph ahead of the heading, because toMarkdown() trims the
    // result - an indent on the very first line would be trimmed away.
    $markdown = $this->toMarkdown(
      "<p>Intro paragraph.</p>\n<div>\n"
      . str_repeat("  <span>&nbsp;</span>\n", $spacers)
      . "       <h2>Spotlight - Landscape Heading</h2>\n</div>"
    );

    $this->assertSame(
      0,
      $this->leadingColumns($markdown),
      "{$spacers} non-breaking-space spacer(s) must not indent the heading that follows them."
    );
    // Still a real setext heading, not a code block trailed by a rule.
    $this->assertMatchesRegularExpression(
      '/^Spotlight - Landscape Heading\n-+$/m',
      $markdown
    );
  }

  /**
   * Spacer counts either side of the four-column threshold.
   */
  public static function spacerCountProvider(): array {
    return [
      'one spacer' => [1],
      'two spacers, the threshold' => [2],
      'four spacers' => [4],
      'seven spacers, as reported' => [7],
    ];
  }

  /**
   * A non-breaking space between words is content and is kept.
   *
   * Only an element whose entire text is blank is furniture. A non-breaking
   * space doing its actual job - holding a value and its unit together - sits
   * in an element with other text, so it must survive.
   */
  public function testKeepsNonBreakingSpaceUsedAsContent(): void {
    $markdown = $this->toMarkdown('<p>The deadline is 15&nbsp;January for all applicants.</p>');

    $this->assertStringContainsString('15', $markdown);
    $this->assertStringContainsString('January', $markdown);
    $this->assertStringContainsString('all applicants', $markdown);
  }

  /**
   * Removing a spacer does not weld the words on either side together.
   *
   * The counterpart to testInlineMarkupDoesNotSplitWords: a spacer between two
   * words is removed, so the separation has to come from the whitespace that
   * remains rather than from the spacer itself.
   */
  public function testRemovingSpacersDoesNotWeldWordsTogether(): void {
    $markdown = $this->toMarkdown('<p>Yale <span>&nbsp;</span> University</p>');

    $this->assertStringContainsString('Yale', $markdown);
    $this->assertStringContainsString('University', $markdown);
    $this->assertStringNotContainsString('YaleUniversity', $markdown);
  }

  /**
   * Empty and whitespace-only input is handled without error.
   */
  public function testHandlesEmptyInput(): void {
    $this->assertSame('', $this->filter->filter(''));
    $this->assertSame('', $this->filter->filter('   '));
    $this->assertSame('', $this->filter->filter("&nbsp;\xc2\xa0"));
  }

  /**
   * One accordion item, shaped the way the component library renders it.
   *
   * Both halves of the accordion problem hang off this shape: the authored
   * heading text sits inside a disclosure button, and the decorative
   * angle-down icon sits inside that same button.
   *
   * @param string $heading
   *   The authored heading text.
   * @param string $id
   *   The stable anchor id, or an empty string to omit it.
   *
   * @return string
   *   The rendered markup for one accordion item.
   */
  private function accordionItem(string $heading, string $id = 'accordion-item-6'): string {
    return '<div data-accordion-expanded="false" class="accordion-item">'
      . '<h3 class="accordion-item__heading"' . ($id === '' ? '' : ' id="' . $id . '"') . '>'
      . '<button aria-expanded="true" class="accordion-item__toggle">' . $heading
      . '<svg class="accordion-item__icon" aria-hidden="true" role="img">'
      . '<use xlink:href="/themes/contrib/atomic/icons.svg#angle-down"></use>'
      . '</svg></button></h3>'
      . '<div class="accordion-item__content"><p>You submit a request form.</p></div>'
      . '</div>';
  }

  /**
   * One gallery modal caption, shaped the way the library renders it.
   *
   * The caption toggle is a sibling of the caption prose rather than its
   * parent, which is why gallery captions already reach the index and must
   * keep doing so.
   *
   * @param string $heading
   *   The authored caption heading.
   * @param string $id
   *   The stable anchor id, or an empty string to omit it.
   *
   * @return string
   *   The rendered markup for one gallery modal caption.
   */
  private function galleryItem(string $heading, string $id = 'gallery-item-12'): string {
    return '<div class="media-grid-modal__content" data-media-grid-modal-item="1">'
      . '<button class="media-grid-modal__toggle-caption" aria-expanded="false">'
      . '<svg class="media-grid-modal__icon"><use xlink:href="/i.svg#circle-plus"></use></svg>'
      . '</button>'
      . '<div class="media-grid-modal__content-wrapper">'
      . '<h2 class="media-grid-modal__heading"' . ($id === '' ? '' : ' id="' . $id . '"') . '>'
      . $heading . '</h2>'
      . '<div class="media-grid-modal__text"><p>Built in 1931 by James Gamble Rogers.</p></div>'
      . '</div></div>';
  }

  /**
   * Filters HTML for a known page and converts it the way the indexer does.
   *
   * @param string $html
   *   The rendered HTML to filter.
   *
   * @return string
   *   The converted Markdown.
   */
  private function toMarkdownForPage(string $html): string {
    return trim($this->productionConverter()->convert(
      $this->filter->filter($html, self::PAGE_URL)
    ));
  }

  /**
   * The accordion item heading reaches the index at all.
   *
   * The bug this guards: button is in REMOVED_TAGS and that pass deletes an
   * element together with its contents, so the authored heading went with the
   * disclosure button and the emptied heading was then dropped as textless.
   * Editors build FAQs out of accordions, so the question half of every
   * question-and-answer pair was missing from the index.
   */
  public function testAccordionItemHeadingReachesTheIndex(): void {
    $markdown = $this->toMarkdown($this->accordionItem('How do I request a new site?'));

    $this->assertStringContainsString('How do I request a new site?', $markdown);
    $this->assertStringContainsString('You submit a request form.', $markdown);
  }

  /**
   * The heading is emitted as a heading, not as a bare line of text.
   *
   * Chunking splits on heading boundaries, so an item's body stays scoped to
   * its own question only if the question is still structurally a heading.
   */
  public function testAccordionItemHeadingIsEmittedAsMarkdownHeading(): void {
    $markdown = $this->toMarkdown($this->accordionItem('How do I request a new site?'));

    $this->assertStringContainsString('### How do I request a new site?', $markdown);
  }

  /**
   * The heading precedes its own body in document order.
   */
  public function testAccordionHeadingPrecedesItsBody(): void {
    $markdown = $this->toMarkdown($this->accordionItem('How do I request a new site?'));

    $this->assertLessThan(
      strpos($markdown, 'You submit a request form.'),
      strpos($markdown, 'How do I request a new site?')
    );
  }

  /**
   * The decorative icon inside the disclosure button does not leak.
   *
   * Keeping the button's text means the icon is momentarily exposed as a
   * child of the heading, so the svg rule has to catch it there.
   */
  public function testDisclosureIconDoesNotLeakIntoTheIndex(): void {
    $markdown = $this->toMarkdown($this->accordionItem('How do I request a new site?'));

    $this->assertStringNotContainsString('angle-down', $markdown);
    $this->assertStringNotContainsString('xlink', $markdown);
    $this->assertStringNotContainsString('icons.svg', $markdown);
  }

  /**
   * Every button that is page furniture is still removed with its contents.
   *
   * @param string $html
   *   Markup holding one furniture button.
   * @param string $needle
   *   Text or an icon reference that must not survive.
   *
   * @dataProvider furnitureButtonProvider
   */
  public function testFurnitureButtonsAreRemovedWithTheirContents(string $html, string $needle): void {
    $markdown = $this->toMarkdown('<p>Real content.</p>' . $html);

    $this->assertStringContainsString('Real content.', $markdown);
    $this->assertStringNotContainsString($needle, $markdown);
  }

  /**
   * Every known furniture button, with the text that must not reach the index.
   *
   * This is the recorded decision for each button case on the platform. A
   * component that adds a new button should be added here rather than
   * discovered later in an indexed chunk.
   *
   * @return array<string, array{string, string}>
   *   Markup and the needle that must be absent, keyed by case name.
   */
  public function furnitureButtonProvider(): array {
    return [
      'accordion expand all' => [
        '<div class="accordion__controls"><button class="accordion__toggle-all" aria-expanded="false">Expand All</button></div>',
        'Expand All',
      ],
      'accordion collapse all' => [
        '<div class="accordion__controls"><button class="accordion__toggle-all" aria-expanded="true">Collapse All</button></div>',
        'Collapse All',
      ],
      'media grid thumbnail wrapper' => [
        '<li class="media-grid__item"><button class="media-grid__image"><img src="/a.jpg" alt=""><span class="visually-hidden">Open this image in a modal</span></button></li>',
        'Open this image in a modal',
      ],
      'media grid caption toggle' => [
        '<button class="media-grid-modal__toggle-caption"><svg><use xlink:href="/i.svg#circle-plus"></use></svg></button>',
        'circle-plus',
      ],
      'media grid modal pager item' => [
        '<button class="media-grid-modal__pager-item"><span class="visually-hidden">View item </span>1</button>',
        'View item',
      ],
      'media grid modal previous' => [
        '<button class="media-grid-modal__control"><span class="visually-hidden">Previous item</span></button>',
        'Previous item',
      ],
      'media grid modal next' => [
        '<button class="media-grid-modal__control"><span class="visually-hidden">Next item</span></button>',
        'Next item',
      ],
      'media grid modal close' => [
        '<button class="media-grid-modal__control"><span class="visually-hidden">Close Gallery</span></button>',
        'Close Gallery',
      ],
      'mobile menu toggle' => [
        '<button class="mobile-menu__toggle" aria-expanded="false">Menu</button>',
        'Menu',
      ],
      'in this section toggle' => [
        '<button class="in-this-section__toggle" aria-expanded="false">In This Section</button>',
        'In This Section',
      ],
      'modal close' => [
        '<button class="modal__close"><span class="visually-hidden">Close dialog</span></button>',
        'Close dialog',
      ],
      'text copy' => [
        '<button class="text-copy__button">Copy to clipboard</button>',
        'Copy to clipboard',
      ],
      'alert dismiss' => [
        '<button class="alert__dismiss"><span class="visually-hidden">Dismiss alert</span></button>',
        'Dismiss alert',
      ],
    ];
  }

  /**
   * Holding text is never on its own enough to make a button content.
   *
   * This is the test the classification rule exists to pass. Three of the
   * buttons here hold real text, and a rule keyed on "the button has text
   * content" would pull all of it into the index. The signal that separates
   * the accordion from these is structural: the button is the entire content
   * of a heading element.
   */
  public function testTextContentAloneNeverQualifiesButtonAsContent(): void {
    $markdown = $this->toMarkdown(
      '<li class="media-grid__item"><button class="media-grid__image"><span class="visually-hidden">Open this image in a modal</span></button></li>'
      . '<button class="media-grid-modal__pager-item"><span class="visually-hidden">View item </span>1</button>'
      . '<div class="accordion__controls"><button class="accordion__toggle-all">Expand All</button></div>'
      . '<p>Real content.</p>'
    );

    $this->assertStringContainsString('Real content.', $markdown);
    $this->assertStringNotContainsString('Open this image in a modal', $markdown);
    $this->assertStringNotContainsString('View item', $markdown);
    $this->assertStringNotContainsString('Expand All', $markdown);
  }

  /**
   * A button sharing a heading with other text is still furniture.
   *
   * The rule is that the button is the *entire* content of the heading. A
   * heading that also holds its own prose is not a disclosure heading, and
   * the button in it has no claim to be authored content.
   */
  public function testButtonSharingHeadingWithOtherTextIsStillRemoved(): void {
    $markdown = $this->toMarkdown('<h3>Kept prose <button class="text-copy__button">Copy heading</button></h3>');

    $this->assertStringContainsString('Kept prose', $markdown);
    $this->assertStringNotContainsString('Copy heading', $markdown);
  }

  /**
   * The deep link is a real anchor element, never a literal Markdown string.
   *
   * TextConverter escapes `[`, `]`, `*`, `_` and `\` in text nodes, so a
   * hand-written "[Heading](url)" would be stored as "\[Heading\](url)". Only
   * an <a> element reaches LinkConverter and comes out as a working link.
   */
  public function testDeepLinkIsEmittedAsAnchorElement(): void {
    $html = $this->filter->filter($this->accordionItem('How do I request a new site?'), self::PAGE_URL);

    $this->assertStringContainsString(
      '<a href="' . self::PAGE_URL . '#accordion-item-6">How do I request a new site?</a>',
      $html
    );
    $this->assertStringNotContainsString('[How do I request a new site?](', $html);
  }

  /**
   * The accordion heading is indexed as an absolute deep link.
   *
   * Absolute rather than a bare fragment: a relative "#id" in a chat answer
   * would resolve against the chat page, not the source page.
   *
   * Asserted against the converter ai_search really builds, so that a contrib
   * bump changing that configuration fails here. Nothing else in this repo
   * would notice.
   */
  public function testAccordionHeadingIsIndexedAsAbsoluteDeepLink(): void {
    $markdown = $this->toMarkdownForPage($this->accordionItem('How do I request a new site?'));

    $this->assertStringContainsString(
      '[How do I request a new site?](' . self::PAGE_URL . '#accordion-item-6)',
      $markdown
    );
  }

  /**
   * The gallery caption heading is indexed as an absolute deep link.
   *
   * Same converter configuration and the same reason as the accordion case.
   */
  public function testGalleryCaptionHeadingIsIndexedAsAbsoluteDeepLink(): void {
    $markdown = $this->toMarkdownForPage($this->galleryItem('Sterling Memorial Library'));

    $this->assertStringContainsString(
      '[Sterling Memorial Library](' . self::PAGE_URL . '#gallery-item-12)',
      $markdown
    );
  }

  /**
   * Whitespace around the heading text is not carried into the link text.
   *
   * Real rendered markup indents the heading's text inside the disclosure
   * button, so without trimming this every accordion link is emitted as
   * "[ Heading ](url)". It resolves, but it reads as a typo in a chat answer.
   */
  public function testDeepLinkTextIsTrimmed(): void {
    $markdown = $this->toMarkdownForPage(
      '<h3 id="accordion-item-6">'
      . "\n      <button class=\"accordion-item__toggle\">\n        Padded heading\n      </button>\n    "
      . '</h3>'
    );

    $this->assertStringContainsString(
      '[Padded heading](' . self::PAGE_URL . '#accordion-item-6)',
      $markdown
    );
    $this->assertStringNotContainsString('[ Padded heading', $markdown);
  }

  /**
   * Gallery caption prose still reaches the index exactly as before.
   *
   * Gallery captions were never the broken half; this guards against the
   * accordion fix regressing them.
   */
  public function testGalleryCaptionProseStillReachesTheIndex(): void {
    $markdown = $this->toMarkdownForPage($this->galleryItem('Sterling Memorial Library'));

    $this->assertStringContainsString('Built in 1931 by James Gamble Rogers.', $markdown);
  }

  /**
   * A deep link is never backslash-escaped into inert text.
   */
  public function testDeepLinkIsNotBackslashEscaped(): void {
    $markdown = $this->toMarkdownForPage(
      $this->accordionItem('How do I request a new site?') . $this->galleryItem('Sterling Memorial Library')
    );

    $this->assertStringNotContainsString('\\[', $markdown);
    $this->assertStringNotContainsString('\\]', $markdown);
  }

  /**
   * No deep link target is ever a bare fragment.
   */
  public function testDeepLinkTargetIsNeverBareFragment(): void {
    $markdown = $this->toMarkdownForPage(
      $this->accordionItem('How do I request a new site?') . $this->galleryItem('Sterling Memorial Library')
    );

    $this->assertStringNotContainsString('](#', $markdown);
    $this->assertStringContainsString('](https://', $markdown);
  }

  /**
   * Markdown punctuation in a heading is escaped inside the link text.
   *
   * Pins what correct escaping looks like: the brackets that delimit the link
   * are structural and stay bare, while the author's own brackets, asterisks
   * and underscores are escaped so they render literally. A later change that
   * escaped the delimiters, or stopped escaping the author's punctuation,
   * would break the link or mangle the text, and this is what catches it.
   */
  public function testDeepLinkTextKeepsItsOwnMarkdownPunctuation(): void {
    $markdown = $this->toMarkdownForPage($this->accordionItem('Costs [2026] and *fees*'));

    $this->assertStringContainsString(
      '[Costs \\[2026\\] and \\*fees\\*](' . self::PAGE_URL . '#accordion-item-6)',
      $markdown
    );
  }

  /**
   * The deep link survives strip_placeholder_links.
   *
   * That option drops a link whose target is empty, so this holds only while
   * the href is a non-empty absolute URL. It is the reason the filter emits
   * nothing at all rather than an empty-href anchor when it has no page URL.
   */
  public function testDeepLinkSurvivesStripPlaceholderLinks(): void {
    $markdown = $this->toMarkdownForPage($this->accordionItem('How do I request a new site?'));

    $this->assertStringContainsString('](' . self::PAGE_URL . '#accordion-item-6)', $markdown);
  }

  /**
   * Without a page URL the heading is kept but not linked.
   *
   * The filter runs over values that have no entity behind them, and half a
   * link is worse than none.
   */
  public function testHeadingIsNotLinkedWhenNoPageUrlIsGiven(): void {
    $markdown = $this->toMarkdown($this->accordionItem('How do I request a new site?'));

    $this->assertStringContainsString('### How do I request a new site?', $markdown);
    $this->assertStringNotContainsString('](', $markdown);
  }

  /**
   * A heading with no id is kept but not linked.
   */
  public function testHeadingWithoutIdIsNotLinked(): void {
    $markdown = $this->toMarkdownForPage($this->accordionItem('How do I request a new site?', ''));

    $this->assertStringContainsString('How do I request a new site?', $markdown);
    $this->assertStringNotContainsString('#accordion-item', $markdown);
  }

  /**
   * An id that is not a plain token is kept but never linked.
   *
   * The linking rule is deliberately general - any heading with an id - and
   * basic_html lets an editor put an arbitrary id on an h2-h6 via the anchor
   * plugin. That value would otherwise flow straight into a Markdown link
   * destination that ends up rendered in a chat answer, where a stray ')' or
   * '[' breaks the link syntax and a bidi control character can make the
   * visible target disagree with the real one. Machine-generated ids
   * (accordion-item-6, gallery-item-12) are unaffected.
   *
   * @param string $id
   *   An id an editor could author.
   *
   * @dataProvider unsafeIdProvider
   */
  public function testHeadingWithUnsafeIdIsKeptButNotLinked(string $id): void {
    $markdown = $this->toMarkdownForPage('<h2 id="' . $id . '">Costs and fees</h2>');

    $this->assertStringContainsString('Costs and fees', $markdown);
    $this->assertStringNotContainsString('](', $markdown);
  }

  /**
   * Ids that must never reach a link destination.
   *
   * @return array<string, array{string}>
   *   One editor-authorable id per case.
   */
  public function unsafeIdProvider(): array {
    return [
      'closing parenthesis ends the markdown destination' => ['faq)x'],
      'opening bracket starts a new markdown link' => ['faq[x'],
      'space breaks the destination' => ['my anchor'],
      'angle bracket' => ['faq<x'],
      'right-to-left override can spoof the visible target' => ["faq\u{202E}x"],
    ];
  }

  /**
   * A page with neither component indexes byte for byte as it did before.
   */
  public function testContentWithNeitherComponentIsUnaffectedByThePageUrl(): void {
    $html = '<h2>Admissions</h2><p>Apply by January 2.</p><ul><li>One</li><li>Two</li></ul>'
      . '<table><tr><th>Term</th><td>Fall</td></tr></table>';

    $this->assertSame(
      $this->filter->filter($html),
      $this->filter->filter($html, self::PAGE_URL)
    );
  }

  /**
   * Pagination stays out of the index now that the button rule is relaxed.
   *
   * Pager links are anchors inside a nav, and nav is separately removed, so
   * relaxing the button rule cannot let them back in. Asserted rather than
   * reasoned about, because the two rules sit next to each other.
   */
  public function testPaginationIsStillExcluded(): void {
    $markdown = $this->toMarkdownForPage(
      '<p>Real content.</p>'
      . '<nav role="navigation" aria-label="Pagination"><ul class="pager__items">'
      . '<li><a href="?page=1">Page 2</a></li><li><a href="?page=2">Page 3</a></li>'
      . '</ul></nav>'
    );

    $this->assertStringContainsString('Real content.', $markdown);
    $this->assertStringNotContainsString('Page 2', $markdown);
    $this->assertStringNotContainsString('?page=1', $markdown);
  }

  /**
   * The node template's own title link is not indexed as content.
   *
   * A visitor never sees that heading, and it duplicates the title the
   * display's own meta block renders. See SELF-TITLE HEADINGS on the service
   * for why it reaches the indexed render at all.
   */
  public function testNodeTemplateTitleLinkIsNotIndexed(): void {
    $markdown = $this->toMarkdownForPage($this->standaloneNodeRender());

    $this->assertStringNotContainsString('](/about/faq)', $markdown);
    // Exactly once, not merely present: the removal has to take the invisible
    // bookmark heading and leave the meta block's h1, which is the heading a
    // reader of the citation is meant to see.
    $this->assertSame(
      1,
      substr_count($markdown, 'Empty Testing Page'),
      'The page title should reach the chunk exactly once.'
    );
    $this->assertStringContainsString('Body copy.', $markdown);
  }

  /**
   * An absolute self-link is recognised as readily as a root-relative one.
   *
   * Whether the rendered href is absolute or root-relative depends on how the
   * URL was generated, and both forms name the same page.
   */
  public function testAbsoluteSelfTitleLinkIsAlsoRemoved(): void {
    $markdown = $this->toMarkdownForPage(
      '<h2><a href="' . self::PAGE_URL . '" rel="bookmark">Empty Testing Page</a></h2>'
      . '<p>Body copy.</p>'
    );

    $this->assertStringNotContainsString('Empty Testing Page', $markdown);
    $this->assertStringContainsString('Body copy.', $markdown);
  }

  /**
   * A fragment on the self-link does not stop it being recognised.
   */
  public function testSelfTitleLinkWithFragmentIsRemoved(): void {
    $markdown = $this->toMarkdownForPage(
      '<h2><a href="/about/faq#main" rel="bookmark">Empty Testing Page</a></h2>'
      . '<p>Body copy.</p>'
    );

    $this->assertStringNotContainsString('Empty Testing Page', $markdown);
  }

  /**
   * A heading linking somewhere else is content and is kept.
   *
   * The rule keys off the link pointing back at the page being indexed. A
   * heading that is entirely a link to a different page is an editor's own
   * navigation into real content and has to survive.
   */
  public function testHeadingLinkingToAnotherPageIsKept(): void {
    $markdown = $this->toMarkdownForPage(
      '<h2><a href="/admissions">How to apply</a></h2><p>Body copy.</p>'
    );

    $this->assertStringContainsString('How to apply', $markdown);
    $this->assertStringContainsString('](/admissions)', $markdown);
  }

  /**
   * A heading mixing prose with a self-link keeps both.
   *
   * "Entire content" is measured in text, the same way the disclosure-button
   * rule measures it: a heading holding words of its own is authored content,
   * not the node template's title link.
   */
  public function testHeadingMixingProseWithSelfLinkIsKept(): void {
    $markdown = $this->toMarkdownForPage(
      '<h2>See <a href="/about/faq" rel="bookmark">this page</a> for details</h2>'
    );

    $this->assertStringContainsString('See', $markdown);
    $this->assertStringContainsString('for details', $markdown);
    // The link itself has to survive, not merely its text: the heading is kept
    // whole, so the destination is still there to follow.
    $this->assertStringContainsString('[this page](/about/faq)', $markdown);
  }

  /**
   * A self-link outside a heading is untouched.
   *
   * Only the heading form is the node template's title. A body paragraph
   * linking to its own page is an editor's choice and none of this rule's
   * business.
   */
  public function testSelfLinkInProseIsKept(): void {
    $markdown = $this->toMarkdownForPage(
      '<p>Bookmark <a href="/about/faq">this page</a>.</p>'
    );

    $this->assertStringContainsString('this page', $markdown);
    $this->assertStringContainsString('](/about/faq)', $markdown);
  }

  /**
   * Without a page URL there is nothing to recognise a self-link against.
   *
   * The filter is also reachable on the search-query path, where no item URL
   * exists. Guessing there would risk deleting an authored heading, so the
   * heading is kept exactly as it was before this rule existed.
   */
  public function testSelfTitleLinkIsKeptWhenNoPageUrlIsGiven(): void {
    $markdown = $this->toMarkdown($this->standaloneNodeRender());

    $this->assertStringContainsString('](/about/faq)', $markdown);
  }

  /**
   * Targets that name no page, or not this one, are refused.
   *
   * These are the shapes the comparison has to turn down. A bare fragment does
   * name this page, but a heading wrapping one is the anchored-heading pattern
   * rather than a title; the rest either name another page or are not a form
   * Drupal's URL generation emits.
   *
   * @dataProvider nonSelfLinkTargetProvider
   */
  public function testHeadingLinkingElsewhereIsKept(string $href): void {
    $markdown = $this->toMarkdownForPage(
      '<h2><a href="' . $href . '">Keep me</a></h2><p>Body copy.</p>'
    );

    $this->assertStringContainsString('Keep me', $markdown);
  }

  /**
   * Link targets that must not be read as "this page".
   */
  public static function nonSelfLinkTargetProvider(): array {
    return [
      'bare fragment is the anchored-heading pattern' => ['#main'],
      'protocol-relative target' => ['//example.yale.edu/about/faq'],
      'protocol-relative bare root' => ['//'],
      'document-relative target' => ['faq'],
      'another host, same path' => ['https://other.example.com/about/faq'],
      'this path with more beneath it' => ['/about/faq/extra'],
    ];
  }

  /**
   * On a page whose path is "/", the refused targets are still refused.
   *
   * This is the case the early-return guard exists for, and the only one where
   * it does any work: against a page path of "/" both a bare fragment and a
   * bare "//" trim down to the empty string, so without the guard they would
   * compare equal to the page and an authored heading would be deleted.
   *
   * @dataProvider rootPageRefusedTargetProvider
   */
  public function testRefusedTargetsAreStillRefusedOnRootPage(string $href): void {
    $markdown = trim($this->productionConverter()->convert(
      $this->filter->filter(
        '<h2><a href="' . $href . '">Keep me</a></h2><p>Body copy.</p>',
        'https://example.yale.edu/'
      )
    ));

    $this->assertStringContainsString('Keep me', $markdown);
  }

  /**
   * Targets that must be refused even when the page itself is the site root.
   */
  public static function rootPageRefusedTargetProvider(): array {
    return [
      'bare fragment' => ['#main'],
      'protocol-relative bare root' => ['//'],
      'protocol-relative host' => ['//example.yale.edu/'],
    ];
  }

  /**
   * The site root's own title link is still recognised on the front page.
   *
   * The counterpart to the test above: the guard must not be so broad that a
   * genuine self-link on a root-path page stops being one.
   */
  public function testRootPageSelfTitleLinkIsRemoved(): void {
    $markdown = trim($this->productionConverter()->convert(
      $this->filter->filter(
        '<h2><a href="/" rel="bookmark">Welcome</a></h2><p>Body copy.</p>',
        'https://example.yale.edu/'
      )
    ));

    $this->assertStringNotContainsString('Welcome', $markdown);
    $this->assertStringContainsString('Body copy.', $markdown);
  }

  /**
   * A trailing slash on either side does not hide a self-link.
   *
   * Rendered hrefs and generated page URLs disagree about the trailing slash
   * often enough that the comparison normalises it away; without that, half
   * the duplicates this rule exists for would survive.
   *
   * @dataProvider trailingSlashProvider
   */
  public function testTrailingSlashesDoNotAffectRecognition(string $href, string $pageUrl): void {
    $markdown = trim($this->productionConverter()->convert(
      $this->filter->filter(
        '<h2><a href="' . $href . '" rel="bookmark">Empty Testing Page</a></h2><p>Body copy.</p>',
        $pageUrl
      )
    ));

    $this->assertStringNotContainsString('Empty Testing Page', $markdown);
  }

  /**
   * Trailing-slash mismatches between an href and the page URL.
   */
  public static function trailingSlashProvider(): array {
    return [
      'slash on the href only' => ['/about/faq/', self::PAGE_URL],
      'slash on the page only' => ['/about/faq', self::PAGE_URL . '/'],
      'slash on both' => ['/about/faq/', self::PAGE_URL . '/'],
    ];
  }

  /**
   * A query on the target makes it a different page, so it is kept.
   *
   * This is what stops the comparison being "simplified" into a paths-only
   * one: a pager link on a listing page is a heading-sized link to this same
   * path with a query, and it is content.
   */
  public function testSelfPathWithQueryIsKept(): void {
    $markdown = $this->toMarkdownForPage(
      '<h2><a href="/about/faq?page=2">Page 2</a></h2><p>Body copy.</p>'
    );

    $this->assertStringContainsString('Page 2', $markdown);
    $this->assertStringContainsString('](/about/faq?page=2)', $markdown);
  }

  /**
   * A query on the PAGE does not make a bare-path link the page itself.
   *
   * Comparing against the page's path alone would delete a heading linking to
   * a genuinely different view of the page, because parse_url() discards the
   * query the page URL itself carried.
   */
  public function testBarePathIsNotSelfLinkOfQueriedPage(): void {
    $markdown = trim($this->productionConverter()->convert(
      $this->filter->filter(
        '<h2><a href="/about/faq">Unpaged</a></h2><p>Body copy.</p>',
        self::PAGE_URL . '?page=2'
      )
    ));

    $this->assertStringContainsString('Unpaged', $markdown);
  }

  /**
   * The standalone node render Search API indexes, in miniature.
   *
   * The bookmark heading core emits above the node's fields, then the meta
   * block's own h1 - the two copies of the title that made a chunk repeat it.
   * Core's node.html.twig indents the anchor inside the heading, which is why
   * the whitespace here is not tidied away: the "entire content" test has to
   * hold with the heading's real text nodes present.
   *
   * @return string
   *   Rendered HTML shaped like the indexed render of a page node.
   */
  private function standaloneNodeRender(): string {
    return '<article>'
      . "\n  <h2>\n    " . '<a href="/about/faq" rel="bookmark">Empty Testing Page</a>' . "\n  </h2>\n"
      . '  <div><h1 class="page-title__heading">Empty Testing Page</h1>'
      . '<p>Body copy.</p></div>'
      . '</article>';
  }

  /**
   * Widest block-start indent in the markdown, measured in columns.
   *
   * Columns rather than characters, because CommonMark expands a tab to the
   * next four-column stop.
   */
  private function leadingColumns(string $markdown): int {
    $widest = 0;

    foreach (explode("\n", $markdown) as $line) {
      if (preg_match('/^([ \t]*)\S/', $line, $matches) !== 1 || $matches[1] === '') {
        continue;
      }
      $columns = 0;
      foreach (str_split($matches[1]) as $character) {
        $columns += $character === "\t" ? 4 - ($columns % 4) : 1;
      }
      $widest = max($widest, $columns);
    }

    return $widest;
  }

}
