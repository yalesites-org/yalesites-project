<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\MarkdownConverter;

/**
 * Tests HTML <-> Markdown conversion for the system instructions editor.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\MarkdownConverter
 */
class MarkdownConverterTest extends UnitTestCase {

  /**
   * The converter under test.
   *
   * @var \Drupal\ys_beacon\Service\MarkdownConverter
   */
  protected MarkdownConverter $converter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->converter = new MarkdownConverter();
  }

  /**
   * Editor HTML becomes the expected Markdown on save.
   *
   * @covers ::toMarkdown
   */
  public function testHtmlToMarkdown(): void {
    $this->assertSame(
      'Hello **world** and *you*.',
      $this->converter->toMarkdown('<p>Hello <strong>world</strong> and <em>you</em>.</p>')
    );
    $this->assertSame(
      'See [x](https://example.com) now.',
      $this->converter->toMarkdown('<p>See <a href="https://example.com">x</a> now.</p>')
    );
    $this->assertSame("A\n\nB", $this->converter->toMarkdown('<p>A</p><p>B</p>'));
  }

  /**
   * Stored Markdown becomes restricted HTML for the editor on load.
   *
   * @covers ::toHtml
   */
  public function testMarkdownToHtml(): void {
    $this->assertSame(
      '<p>Hello <strong>world</strong></p>',
      $this->converter->toHtml('Hello **world**')
    );
    $html = $this->converter->toHtml('A paragraph with a [link](https://example.com).');
    $this->assertStringContainsString('<a href="https://example.com">link</a>', $html);
    $this->assertStringStartsWith('<p>', $html);
  }

  /**
   * Empty and whitespace-only input converts to an empty string both ways.
   *
   * @covers ::toHtml
   * @covers ::toMarkdown
   */
  public function testEmptyInput(): void {
    $this->assertSame('', $this->converter->toHtml(''));
    $this->assertSame('', $this->converter->toHtml("   \n  "));
    $this->assertSame('', $this->converter->toMarkdown(''));
    $this->assertSame('', $this->converter->toMarkdown('<p></p>'));
    $this->assertSame('', $this->converter->toMarkdown('   '));
  }

  /**
   * Tables are stripped in both directions; only their text survives.
   *
   * The restricted_html format forbids tables, so they must never appear in the
   * editor HTML or in the stored Markdown.
   *
   * @covers ::toMarkdown
   * @covers ::toHtml
   */
  public function testTablesAreStripped(): void {
    // HTML tables submitted via a crafted POST lose their markup, keep text.
    $markdown = $this->converter->toMarkdown('<table><tr><td>a</td><td>b</td></tr></table>');
    $this->assertStringNotContainsString('<table', $markdown);
    $this->assertStringNotContainsString('|', $markdown);
    $this->assertStringContainsString('a', $markdown);
    $this->assertStringContainsString('b', $markdown);

    // Pipe-style Markdown tables are not rendered as tables on load.
    $html = $this->converter->toHtml("| a | b |\n|---|---|\n| 1 | 2 |");
    $this->assertStringNotContainsString('<table', $html);
    $this->assertStringNotContainsString('<td', $html);
  }

  /**
   * Headings and lists are downgraded so no text is lost on load.
   *
   * The restricted_html format has no headings or lists; headings become bold
   * paragraphs and each list item becomes its own paragraph (rather than being
   * run together, which would lose the item boundaries).
   *
   * @covers ::toHtml
   */
  public function testHeadingsAndListsDowngradeWithoutDataLoss(): void {
    $html = $this->converter->toHtml("# Title\n\n- one\n- two");

    $this->assertStringNotContainsString('<h1', $html);
    $this->assertStringNotContainsString('<ul', $html);
    $this->assertStringNotContainsString('<li', $html);
    // The heading text is preserved as a bold paragraph.
    $this->assertStringContainsString('<p><strong>Title</strong></p>', $html);
    // Each list item survives as its own paragraph.
    $this->assertStringContainsString('<p>one</p>', $html);
    $this->assertStringContainsString('<p>two</p>', $html);
  }

  /**
   * Subscript and superscript survive the round-trip as inline HTML.
   *
   * @covers ::toMarkdown
   * @covers ::toHtml
   */
  public function testSubSupPreserved(): void {
    $markdown = $this->converter->toMarkdown('<p>H<sub>2</sub>O and x<sup>2</sup></p>');
    $this->assertStringContainsString('<sub>2</sub>', $markdown);
    $this->assertStringContainsString('<sup>2</sup>', $markdown);

    $html = $this->converter->toHtml($markdown);
    $this->assertStringContainsString('<sub>2</sub>', $html);
    $this->assertStringContainsString('<sup>2</sup>', $html);
  }

  /**
   * Scripts and other unsafe markup are removed on save.
   *
   * @covers ::toMarkdown
   */
  public function testUnsafeMarkupIsRemoved(): void {
    $markdown = $this->converter->toMarkdown('<p>ok</p><script>alert(1)</script>');
    $this->assertStringNotContainsString('<script', $markdown);
    $this->assertStringContainsString('ok', $markdown);
  }

  /**
   * Special characters survive without leaking HTML entities into Markdown.
   *
   * An ampersand must be stored as "&" (not "&amp;"); literal angle-bracket
   * text must survive the round-trip without being lost.
   *
   * @covers ::toMarkdown
   * @covers ::toHtml
   */
  public function testSpecialCharactersRoundTrip(): void {
    // Ampersands are stored decoded.
    $markdown = $this->converter->toMarkdown('<p><strong>Identity &amp; Purpose</strong></p>');
    $this->assertSame('**Identity & Purpose**', $markdown);
    $this->assertStringNotContainsString('&amp;', $markdown);

    // Literal angle-bracket text is not lost across a save -> reload -> save.
    $first = $this->converter->toMarkdown('<p>Use &lt;name&gt; as a placeholder.</p>');
    $reloaded = $this->converter->toHtml($first);
    $second = $this->converter->toMarkdown($reloaded);
    $this->assertStringContainsString('name', $first);
    $this->assertSame($first, $second);
    $this->assertStringContainsString('name', $second);
  }

  /**
   * Save -> reload -> save produces equivalent Markdown (round-trip stable).
   *
   * @covers ::toMarkdown
   * @covers ::toHtml
   */
  public function testRoundTripIsStable(): void {
    $editor_html = '<p>Intro <strong>bold</strong> and a <a href="https://example.com">link</a>.</p>'
      . '<p>Second para with <em>emphasis</em> and H<sub>2</sub>O.</p>';

    $first_save = $this->converter->toMarkdown($editor_html);
    $reloaded = $this->converter->toHtml($first_save);
    $second_save = $this->converter->toMarkdown($reloaded);

    $this->assertSame($first_save, $second_save);
  }

  /**
   * Legacy heading/list Markdown normalizes once, then stays stable.
   *
   * Mirrors the seeded default instructions (headings + bold list items): the
   * first editor round-trip downgrades the structure, and every round-trip
   * after that is stable.
   *
   * @covers ::toMarkdown
   * @covers ::toHtml
   */
  public function testSeedContentNormalizesThenStable(): void {
    $seed = "# YaleSites AI\n\n## Identity\nYou are an assistant.\n\n- **Empathetic**: care\n- **Concise**: brief";

    $normalized = $this->converter->toMarkdown($this->converter->toHtml($seed));
    // All text survives the downgrade.
    $this->assertStringContainsString('YaleSites AI', $normalized);
    $this->assertStringContainsString('You are an assistant.', $normalized);
    $this->assertStringContainsString('Empathetic', $normalized);
    $this->assertStringContainsString('Concise', $normalized);
    // No heading or list Markdown remains.
    $this->assertStringNotContainsString('# ', $normalized);
    $this->assertStringNotContainsString("\n- ", $normalized);

    $again = $this->converter->toMarkdown($this->converter->toHtml($normalized));
    $this->assertSame($normalized, $again);
  }

}
