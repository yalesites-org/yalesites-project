<?php

namespace Drupal\Tests\ys_markdown\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_markdown\MarkdownBuilder;
use Drupal\ys_markdown\PublicHtmlFilter;

/**
 * Tests the Markdown shape of filtered HTML.
 *
 * @group ys_markdown
 */
class PublicMarkdownConversionTest extends UnitTestCase {

  /**
   * Filters then converts a fragment the way MarkdownBuilder does.
   */
  protected function markdown(string $html): string {
    $filtered = (new PublicHtmlFilter())->filter($html);
    return MarkdownBuilder::convert($filtered);
  }

  /**
   * Inline emphasis keeps the space that separates it from its neighbours.
   */
  public function testEmphasisKeepsSpaces(): void {
    $md = $this->markdown('<p><span><strong>Lorem ipsum dolor </strong>sit amet</span><em><span> per, diam</span></em></p>');
    $this->assertStringContainsString('**Lorem ipsum dolor** sit amet', $md);
    $this->assertStringContainsString(' *per, diam*', $md);
  }

  /**
   * A card whose first child is a category list has no "- - " line.
   */
  public function testNestedLeadingListHasNoDoubleBullet(): void {
    $md = $this->markdown('<ul><li><ul class="taxonomy-list"><li>Arts &amp; Humanities</li></ul><h3>Event</h3></li></ul>');
    $this->assertStringNotContainsString('- - ', $md);
    $this->assertStringContainsString('Arts & Humanities', $md);
  }

  /**
   * A capped listing ends with the "more" line as its own paragraph.
   */
  public function testCappedListingEndsWithMoreLine(): void {
    $items = str_repeat('<li>Row</li>', PublicHtmlFilter::LISTING_ITEM_CAP + 1);
    $md = $this->markdown('<div class="ys-view"><ul>' . $items . '</ul></div>');
    $this->assertSame(PublicHtmlFilter::LISTING_ITEM_CAP, substr_count($md, '- Row'));
    $this->assertStringEndsWith("\n\nMore items are listed on the web page.", $md);
  }

  /**
   * A card whose category list sits in a wrapper has no "- - " line.
   */
  public function testWrappedLeadingListHasNoDoubleBullet(): void {
    $md = $this->markdown('<ul><li class="c"><div class="c__content"><ul class="taxonomy-list"><li>Arts &amp; Humanities</li><li aria-hidden="true">|</li></ul><h3>Event</h3></div></li></ul>');
    $this->assertStringNotContainsString('- - ', $md);
    $this->assertStringContainsString('Arts & Humanities', $md);
  }

  /**
   * A decoded ")" in an oEmbed video URL does not start a second link.
   */
  public function testOembedUrlCannotBreakOutOfLink(): void {
    $md = $this->markdown('<iframe title="Video" src="/media/oembed?url=https%3A%2F%2Fwww.youtube.com%2Fwatch%3Fv%3Dx%29%5Bclick%5D%28javascript%3Aalert%281%29%29"></iframe>');
    $this->assertStringNotContainsString('](javascript', $md);
    $this->assertSame(1, substr_count($md, ']('));
    $this->assertSame('Embedded content: [Video](https://www.youtube.com/watch?v=x%29[click]%28javascript:alert%281%29%29)', $md);
  }

  /**
   * Characters that end a link destination in an href are percent-encoded.
   */
  public function testEditorHrefCannotBreakOutOfLink(): void {
    $md = $this->markdown('<p><a href="https://example.com/a)[x](javascript:alert(1))">t</a> <a href="https://example.com/a b<c>\\">u</a></p>');
    $this->assertStringNotContainsString('](javascript', $md);
    $this->assertSame('[t](https://example.com/a%29[x]%28javascript:alert%281%29%29) [u](https://example.com/a%20b%3Cc%3E%5C)', $md);
  }

  /**
   * An entity in an href is not decoded into a ")" after conversion.
   */
  public function testHrefEntityIsNotDecodedIntoParenthesis(): void {
    $md = $this->markdown('<p><a href="https://example.com/a&amp;#41;[x](javascript:alert(1))">t</a></p>');
    $this->assertStringNotContainsString('](javascript', $md);
    $this->assertStringContainsString('(https://example.com/a&#41;[x]%28javascript', $md);
  }

  /**
   * Link and image titles and alt text cannot close their Markdown early.
   */
  public function testTitleAndAltCannotBreakOut(): void {
    $md = $this->markdown('<p><a href="https://example.com" title="a&quot;) [x](javascript:alert(1)) &quot;">t</a></p><p><img src="/i.png?a=(1)" alt="a](https://e.com) [x](javascript:alert(1)) ![b" title="q&quot;) [y](javascript:alert(2))"></p>');
    $this->assertSame('[t](https://example.com "a\\") [x](javascript:alert(1)) \\"")' . "\n\n" . '![a\\](https://e.com) \\[x\\](javascript:alert(1)) !\\[b](/i.png?a=%281%29 "q\\") [y](javascript:alert(2))")', $md);
  }

  /**
   * Ordinary links and images convert unchanged.
   */
  public function testOrdinaryLinksUnchanged(): void {
    $md = $this->markdown('<p><a href="https://example.com/path?a=1&amp;b=2#top">Link</a> <a href="/about" title="About us">About</a></p><p><img src="/a.png" alt="A lake"></p>');
    $this->assertSame('[Link](https://example.com/path?a=1&b=2#top) [About](/about "About us")' . "\n\n" . '![A lake](/a.png)', $md);
  }

}
