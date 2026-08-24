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
   * Empty and whitespace-only input is handled without error.
   */
  public function testHandlesEmptyInput(): void {
    $this->assertSame('', $this->filter->filter(''));
    $this->assertSame('', $this->filter->filter('   '));
  }

}
