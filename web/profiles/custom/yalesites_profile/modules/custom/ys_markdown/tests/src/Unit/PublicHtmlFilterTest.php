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

}
