<?php

namespace Drupal\Tests\ys_markdown\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_markdown\PublicHtmlFilter;

/**
 * Tests the public Markdown HTML filter.
 *
 * @group ys_markdown
 * @coversDefaultClass \Drupal\ys_markdown\PublicHtmlFilter
 */
class PublicHtmlFilterTest extends UnitTestCase {

  /**
   * Filters a fragment.
   */
  protected function filter(string $html, ?string $title = NULL): string {
    return (new PublicHtmlFilter())->filter($html, $title);
  }

  /**
   * Page chrome is removed and prose kept.
   *
   * @covers ::filter
   */
  public function testChromeRemoved(): void {
    $out = $this->filter('<nav>MENU</nav><script>alert(1)</script><style>.a{}</style><noscript>NS</noscript><template>TPL</template><form>FORM</form><button>BTN</button><p>Keep me</p>');
    $this->assertStringContainsString('Keep me', $out);
    foreach (['MENU', 'alert', '.a{}', 'NS', 'TPL', 'FORM', 'BTN'] as $gone) {
      $this->assertStringNotContainsString($gone, $out);
    }
  }

  /**
   * The skip marker removes any element carrying it.
   *
   * @covers ::filter
   */
  public function testSkipMarker(): void {
    $out = $this->filter('<div data-markdown-skip="">Chrome text</div><div><p data-markdown-skip>Also chrome</p><p>Prose</p></div>');
    $this->assertStringNotContainsString('Chrome text', $out);
    $this->assertStringNotContainsString('Also chrome', $out);
    $this->assertStringContainsString('Prose', $out);
  }

  /**
   * Images with alt, captions and tables survive.
   *
   * @covers ::filter
   */
  public function testContentKept(): void {
    $out = $this->filter('<figure><img src="/a.png" alt="A lake"><figcaption>Lake caption</figcaption></figure><table><tr><td>Cell</td></tr></table>');
    $this->assertStringContainsString('alt="A lake"', $out);
    $this->assertStringContainsString('Lake caption', $out);
    $this->assertStringContainsString('<table>', $out);
    $this->assertStringContainsString('Cell', $out);
  }

  /**
   * An iframe becomes a text fallback linking to its source.
   *
   * @covers ::filter
   */
  public function testIframeFallback(): void {
    $out = $this->filter('<iframe src="https://example.com/v" title="A video"></iframe>');
    $this->assertStringNotContainsString('<iframe', $out);
    $this->assertStringContainsString('Embedded content: ', $out);
    $this->assertStringContainsString('<a href="https://example.com/v">A video</a>', $out);

    $out = $this->filter('<iframe src="https://example.com/v"></iframe>');
    $this->assertStringContainsString('>embed</a>', $out);

    $out = $this->filter('<iframe src="javascript:alert(1)" title="Bad"></iframe>');
    $this->assertStringContainsString('Embedded content: Bad', $out);
    $this->assertStringNotContainsString('href', $out);
  }

  /**
   * Unsafe links are unwrapped and empty wrappers dropped.
   *
   * @covers ::filter
   */
  public function testCopiedPasses(): void {
    $out = $this->filter('<p><a href="javascript:alert(1)">Click</a></p><div><span>&nbsp;</span></div><p>Text</p>');
    $this->assertStringNotContainsString('javascript', $out);
    $this->assertStringContainsString('Click', $out);
    $this->assertStringNotContainsString('<span', $out);
  }

  /**
   * Heading chrome, indentation and blank runs from a real page are cleaned.
   *
   * @covers ::filter
   */
  public function testRealPageShape(): void {
    $html = <<<'HTML'
<article>
    <h2>
      <a href="/home-page" rel="bookmark">
        <span> Home Page </span>
      </a>
    </h2>

    <div class="layout">
      <div class="region">
        <h1>
          Home Page
        </h1>
        <div>

        </div>
        <figure>
          <img src="/jester.jpg" alt="Portrait of Tom Foolery">
        </figure>
        <p>
          Lorem   ipsum
          dolor.
        </p>
        <pre>  keep
    this</pre>
      </div>
    </div>
</article>
HTML;
    $out = $this->filter($html, 'Home Page');
    $this->assertStringNotContainsString('Home Page', $out);
    $this->assertStringContainsString('alt="Portrait of Tom Foolery"', $out);
    $this->assertStringContainsString('<p>Lorem ipsum dolor.</p>', $out);
    $this->assertStringContainsString("<pre>  keep\n    this</pre>", $out);
  }

  /**
   * Only headings equal to the title are removed.
   *
   * @covers ::filter
   */
  public function testOtherHeadingsKept(): void {
    $out = $this->filter('<h2>About</h2><h3> The   Title </h3><p>x</p>', 'The Title');
    $this->assertStringContainsString('About', $out);
    $this->assertStringNotContainsString('The Title', $out);
  }

  /**
   * A form is replaced by one fallback line, never dropped silently.
   *
   * @covers ::filter
   */
  public function testFormBecomesFallback(): void {
    $out = $this->filter('<p>Before</p><form><div><form>INNER</form>GRID</div></form><p>After</p>');
    $this->assertStringNotContainsString('GRID', $out);
    $this->assertStringNotContainsString('INNER', $out);
    $this->assertSame(1, substr_count($out, 'Interactive form: available on the web page.'));
    $this->assertStringContainsString('After', $out);
  }

  /**
   * Elements hidden from screen readers are dropped with their contents.
   *
   * @covers ::filter
   */
  public function testAriaHiddenRemoved(): void {
    $out = $this->filter('<ul><li>A</li><li aria-hidden="true" class="d">|</li><li>B</li></ul>');
    $this->assertStringNotContainsString('|', $out);
    $this->assertStringContainsString('B', $out);
  }

  /**
   * A data: link is removed with its text; other unsafe links are unwrapped.
   *
   * @covers ::filter
   */
  public function testDataLinkRemoved(): void {
    $out = $this->filter('<p>Hi <a href=" DATA:text/calendar;base64,AAAA" class="cta">Add to Calendar</a></p><p><a href="javascript:x()">Plain</a></p>');
    $this->assertStringNotContainsString('Add to Calendar', $out);
    $this->assertStringContainsString('Hi', $out);
    $this->assertStringContainsString('<p>Plain</p>', $out);
  }

  /**
   * Edge space of an inline element moves outside it instead of vanishing.
   *
   * @covers ::filter
   */
  public function testInlineEdgeSpaceKept(): void {
    $out = $this->filter('<p><span><strong>Lorem ipsum dolor </strong>sit amet</span><em><span> per, diam</span></em></p>');
    $this->assertStringContainsString('<strong>Lorem ipsum dolor</strong> sit amet', $out);
    $this->assertStringContainsString('</span> <em>', $out);
    $this->assertStringContainsString('<span>per, diam</span></em>', $out);
    $out = $this->filter('<p><em> x </em></p>');
    $this->assertStringContainsString('<p><em>x</em></p>', $out);
  }

  /**
   * A list that opens with a nested list becomes a line of text.
   *
   * @covers ::filter
   */
  public function testLeadingNestedListFlattened(): void {
    $out = $this->filter('<ul><li><ul class="t"><li>Arts &amp; Humanities</li><li>Science</li></ul><h3>Event</h3></li><li>Plain <ul><li>kept</li></ul></li></ul>');
    $this->assertStringContainsString('<p>Arts &amp; Humanities, Science</p>', $out);
    $this->assertStringContainsString('<li>kept</li>', $out);
  }

  /**
   * A media oEmbed proxy iframe links to the underlying video URL.
   *
   * @covers ::filter
   */
  public function testOembedIframeLinksToSource(): void {
    $src = 'https://site.test/media/oembed?url=https%3A//www.youtube.com/watch%3Fv%3DahDqeHiaO9M&max_width=0&hash=x';
    $out = $this->filter('<iframe src="' . $src . '" title="What Is Drupal?"></iframe>');
    $this->assertStringContainsString('href="https://www.youtube.com/watch?v=ahDqeHiaO9M"', $out);
    $this->assertStringContainsString('>What Is Drupal?</a>', $out);
    $this->assertStringNotContainsString('site.test', $out);

    $out = $this->filter('<iframe src="https://site.test/media/oembed?url=javascript%3Aalert(1)" title="Bad"></iframe>');
    $this->assertStringNotContainsString('href="javascript', $out);
    $this->assertStringNotContainsString('href', $out);
    $this->assertStringContainsString('Embedded content: Bad', $out);
  }

  /**
   * Builds a listing of the given number of items.
   */
  protected function listing(int $count, string $extra = ''): string {
    $items = '';
    for ($i = 1; $i <= $count; $i++) {
      $items .= "<li>Item $i</li>";
    }
    return '<div class="foo ys-view bar"><ul>' . $items . '</ul>' . $extra . '</div>';
  }

  /**
   * A listing keeps its first items and says that more exist.
   *
   * @covers ::filter
   */
  public function testListingCapAddsMoreLine(): void {
    $out = $this->filter($this->listing(PublicHtmlFilter::LISTING_ITEM_CAP + 5));
    $this->assertStringContainsString('Item 50<', $out);
    $this->assertStringNotContainsString('Item 51<', $out);
    $this->assertSame(1, substr_count($out, 'More items are listed on the web page.'));
  }

  /**
   * A pager means more pages exist, even when the list is short.
   *
   * @covers ::filter
   */
  public function testListingPagerAddsMoreLine(): void {
    $out = $this->filter($this->listing(3, '<nav class="pager"><a href="?page=1">Next</a></nav>'));
    $this->assertStringContainsString('Item 3<', $out);
    $this->assertSame(1, substr_count($out, 'More items are listed on the web page.'));
    $this->assertStringNotContainsString('Next', $out);
  }

  /**
   * A short listing without a pager gets no sentence.
   *
   * @covers ::filter
   */
  public function testShortListingHasNoMoreLine(): void {
    $out = $this->filter($this->listing(3));
    $this->assertStringContainsString('Item 3<', $out);
    $this->assertStringNotContainsString('More items', $out);
    $out = $this->filter('<ul>' . str_repeat('<li>x</li>', 60) . '</ul>');
    $this->assertSame(60, substr_count($out, '<li>'));
  }

  /**
   * A category list inside a wrapper at the start of a card is flattened.
   *
   * @covers ::filter
   */
  public function testWrappedLeadingListFlattened(): void {
    $out = $this->filter('<ul><li class="reference-card"><div class="reference-card__content"><ul class="taxonomy-list taxonomy-list--categories"><li>Arts &amp; Humanities</li><li aria-hidden="true">|</li></ul><h3>Event</h3></div><div class="reference-card__image"><img src="/a.png" alt="x"></div></li></ul>');
    $this->assertStringContainsString('<p>Arts &amp; Humanities</p>', $out);
    $this->assertStringNotContainsString('taxonomy-list', $out);
    $out = $this->filter('<ul><li><div><img src="/a.png" alt="x"></div><div><ul><li>Kept</li></ul></div></li></ul>');
    $this->assertStringContainsString('<li>Kept</li>', $out);
  }

  /**
   * Exposed filter forms vanish silently; other forms leave the fallback.
   *
   * @covers ::filter
   */
  public function testExposedFormSilentOtherFormsFallback(): void {
    $out = $this->filter('<form class="views-exposed-form ys-filter-form">FILTER</form><p>Keep</p>');
    $this->assertStringNotContainsString('FILTER', $out);
    $this->assertStringNotContainsString('Interactive form', $out);
    foreach (['webform-submission-form', 'event-calendar-filter-form'] as $class) {
      $out = $this->filter('<form class="' . $class . '">X</form><p>Keep</p>');
      $this->assertStringContainsString('Interactive form: available on the web page.', $out);
    }
  }

  /**
   * An image with alt text survives inside an aria-hidden link.
   *
   * @covers ::filter
   */
  public function testAriaHiddenImageWithAltKept(): void {
    $out = $this->filter('<div><a class="img-link" href="/home" tabindex="-1" aria-hidden="true"><div><img src="/a.jpg" alt="A pool"></div></a></div>');
    $this->assertStringContainsString('<img src="/a.jpg" alt="A pool">', $out);
    $this->assertStringNotContainsString('<a', $out);
  }

  /**
   * An image stays dropped when chrome sits between it and the hidden element.
   *
   * @covers ::filter
   */
  public function testAriaHiddenImageInsideChromeDropped(): void {
    $out = $this->filter('<p>Keep</p><div aria-hidden="true"><div data-markdown-skip><img src="/a.jpg" alt="Logo"></div></div><div aria-hidden="true"><button><img src="/b.jpg" alt="Icon"></button></div>');
    $this->assertStringNotContainsString('<img', $out);
    $this->assertStringContainsString('Keep', $out);
  }

  /**
   * Decorative images in aria-hidden elements stay dropped.
   *
   * @covers ::filter
   */
  public function testAriaHiddenDecorativeImageDropped(): void {
    $out = $this->filter('<p>Keep</p><a href="/x" aria-hidden="true"><img src="/a.jpg" alt=""></a><span aria-hidden="true"><img src="/b.jpg"><img src="/c.jpg" alt="  "></span>');
    $this->assertStringNotContainsString('<img', $out);
    $this->assertStringContainsString('Keep', $out);
  }

  /**
   * Returns a trimmed events calendar form with one event.
   */
  protected function calendar(string $extra = ''): string {
    return '<form class="event-calendar-filter-form"><div class="views-exposed-form">FILTER</div>'
      . '<ul><li class="calendar__day calendar__day--events" data-day="Thu">'
      . '<time datetime="2026-10-08"><span aria-hidden="true">Thu</span><span class="sr-only">08 October, 2026</span></time>'
      . '<ul class="calendar__day-events"><li class="calendar-event"><span class="calendar-event__category">Arts</span>'
      . '<div class="calendar-event__title"><a href="/events/dinner">Dinner with Dad</a></div><time>6:00pm - 8:00pm</time></li>'
      . $extra . '</ul><button>1 more events</button></li></ul>'
      . '<ul hidden class="modal__calendar-events"></ul></form>';
  }

  /**
   * An events calendar keeps the fallback and lists its events.
   *
   * @covers ::filter
   */
  public function testCalendarEventsListed(): void {
    $extra = '<li class="calendar-event"><div class="calendar-event__title">Plain talk</div></li>';
    $out = $this->filter($this->calendar($extra));
    $this->assertStringContainsString('Interactive form: available on the web page.', $out);
    $this->assertStringContainsString('<li><a href="/events/dinner">Dinner with Dad</a>, October 8, 2026, 6:00pm - 8:00pm</li>', $out);
    $this->assertStringContainsString('<li>Plain talk, October 8, 2026</li>', $out);
    $this->assertStringNotContainsString('FILTER', $out);
    $this->assertStringNotContainsString('1 more events', $out);
    $this->assertLessThan(strpos($out, 'Dinner with Dad'), strpos($out, 'Interactive form'));
  }

  /**
   * A calendar event without a usable date omits it; no events changes nothing.
   *
   * @covers ::filter
   */
  public function testCalendarWithoutDateOrEvents(): void {
    $out = $this->filter(str_replace('datetime="2026-10-08"', 'datetime="junk"', $this->calendar()));
    $this->assertStringContainsString('<li><a href="/events/dinner">Dinner with Dad</a>, 6:00pm - 8:00pm</li>', $out);
    $out = $this->filter('<form class="event-calendar-filter-form">X</form><p>Keep</p>');
    $this->assertSame('<p>Interactive form: available on the web page.</p><p>Keep</p>', $out);
  }

  /**
   * Listing roots are capped once each, including nested ones.
   *
   * @covers ::filter
   */
  public function testListingRoots(): void {
    $items = str_repeat('<li>x</li>', PublicHtmlFilter::LISTING_ITEM_CAP + 3);
    foreach (['ys-resource-view', 'card-collection'] as $class) {
      $out = $this->filter('<div class="' . $class . '"><ul class="card-collection__cards">' . $items . '</ul></div>');
      $this->assertSame(PublicHtmlFilter::LISTING_ITEM_CAP, substr_count($out, '<li>'), $class);
      $this->assertSame(1, substr_count($out, 'More items are listed'), $class);
    }
    $out = $this->filter('<div class="ys-view"><div class="card-collection"><ul>' . $items . '</ul></div></div>');
    $this->assertSame(PublicHtmlFilter::LISTING_ITEM_CAP, substr_count($out, '<li>'));
    $this->assertSame(1, substr_count($out, 'More items are listed'));
  }

  /**
   * A form-type embed without a usable source becomes the form line.
   *
   * @covers ::filter
   */
  public function testFormEmbedIframe(): void {
    $out = $this->filter('<iframe class="embed__iframe" title="microsoft form" src="" data-embed-type="form"></iframe>');
    $this->assertStringContainsString('<p>Interactive form: available on the web page.</p>', $out);
    $this->assertStringNotContainsString('Embedded content', $out);
    $out = $this->filter('<iframe title="Report" src="https://app.powerbi.com/x" data-embed-type="form"></iframe>');
    $this->assertStringContainsString('Embedded content: <a href="https://app.powerbi.com/x">Report</a>', $out);
  }

}
