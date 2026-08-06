<?php

namespace Drupal\Tests\ys_localist\Unit;

use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_localist\LocalistManager;
use Drupal\ys_localist\MetaFieldsManager;

/**
 * Unit tests for the Localist event description clean-up.
 *
 * See MetaFieldsManager::stripEmptyParagraphs() for why the description needs
 * cleaning at all (yalesites-org/YaleSites-Internal#986).
 *
 * The fixtures are shaped after real payloads from
 * events.yale.edu/api/2/events, sampled while diagnosing the issue: 11 of 15
 * descriptions carried an empty paragraph, and all 15 styled text with
 * <b>/<i>/<u> while none used <strong>/<em>.
 *
 * @coversDefaultClass \Drupal\ys_localist\MetaFieldsManager
 *
 * @group yalesites
 * @group ys_localist
 */
class MetaFieldsManagerTest extends UnitTestCase {

  /**
   * The manager under test.
   *
   * @var \Drupal\ys_localist\MetaFieldsManager
   */
  protected $metaFieldsManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->metaFieldsManager = new MetaFieldsManager(
      $this->createMock(DateFormatter::class),
      $this->createMock(EntityTypeManager::class),
      $this->createMock(LocalistManager::class),
    );
  }

  /**
   * Empty filler paragraphs Localist emits between real paragraphs.
   *
   * @return array
   *   Test cases: description in, description expected out.
   */
  public static function fillerParagraphCases(): array {
    return [
      'nbsp filler between paragraphs' => [
        "<p>First.</p>\n\n<p>&nbsp;</p>\n\n<p>Second.</p>",
        "<p>First.</p>\n\n\n\n<p>Second.</p>",
      ],
      'genuinely empty paragraph' => [
        '<p>First.</p><p></p><p>Second.</p>',
        '<p>First.</p><p>Second.</p>',
      ],
      'nothing to strip is left alone' => [
        "<p>First.</p>\n\n<p>Second.</p>",
        "<p>First.</p>\n\n<p>Second.</p>",
      ],
    ];
  }

  /**
   * Filler paragraphs are removed, real content is not.
   *
   * @dataProvider fillerParagraphCases
   * @covers ::stripEmptyParagraphs
   */
  public function testStripsFillerParagraphs(string $description, string $expected): void {
    $this->assertSame($expected, $this->metaFieldsManager->stripEmptyParagraphs($description));
  }

  /**
   * A missing description stays missing rather than becoming an empty string.
   *
   * @covers ::stripEmptyParagraphs
   */
  public function testLeavesMissingDescriptionAlone(): void {
    $this->assertNull($this->metaFieldsManager->stripEmptyParagraphs(NULL));
    $this->assertSame('', $this->metaFieldsManager->stripEmptyParagraphs(''));
  }

  /**
   * Localist's own formatting tags survive.
   *
   * Basic_html allows none of <b>, <i> or <u>, so this is what would break if
   * the clean-up were ever "simplified" into a text format: the styling on
   * essentially every imported event would silently flatten.
   *
   * @covers ::stripEmptyParagraphs
   */
  public function testKeepsLocalistFormattingTags(): void {
    $description = '<p><b>Bold</b> and <i>italic</i> and <u>underline</u>'
      . ' <span style="font-size:12pt"><span>sized</span></span>'
      . ' <a href="https://example.yale.edu/x">a link</a></p>';

    $this->assertSame($description, $this->metaFieldsManager->stripEmptyParagraphs($description));
  }

  /**
   * A soft line break stays exactly one line break.
   *
   * Localist serializes a soft break as the tag plus a literal newline. The
   * clean-up must not touch it: nothing on this render path converts newlines
   * to markup, so a break here is already correct and adding to it is the very
   * doubling the ticket complains about.
   *
   * @covers ::stripEmptyParagraphs
   */
  public function testKeepsSoftLineBreaksIntact(): void {
    $description = "<p>First line<br />\nSecond line</p>";
    $output = $this->metaFieldsManager->stripEmptyParagraphs($description);

    $this->assertSame($description, $output);
    $this->assertSame(1, preg_match_all('#<br\s*/?>#i', $output));
  }

  /**
   * Preformatted content is left alone, because whitespace matters there.
   *
   * @covers ::stripEmptyParagraphs
   */
  public function testLeavesPreformattedContentAlone(): void {
    $description = "<pre><p>&nbsp;</p></pre>";
    $this->assertSame($description, $this->metaFieldsManager->stripEmptyParagraphs($description));
  }

  /**
   * A real-shaped Localist description loses only its filler.
   *
   * @covers ::stripEmptyParagraphs
   */
  public function testCleansRealShapedDescription(): void {
    $description = '<p><b><span style="font-size:12pt"><span>&ldquo;Tracing the Rosebud Orchid&rdquo;&nbsp;'
      . "will be on view at Haas Arts Library from June 8 through Nov. 8.</span></span></b></p>\n\n"
      . "<p>&nbsp;</p>\n\n"
      . '<p><span style="font-size:12pt"><span>Artists have long looked to the natural world.</span></span></p>';

    $output = $this->metaFieldsManager->stripEmptyParagraphs($description);

    $this->assertStringNotContainsString('<p>&nbsp;</p>', $output);
    $this->assertStringContainsString('Tracing the Rosebud Orchid', $output);
    $this->assertStringContainsString('Artists have long looked to the natural world.', $output);
    $this->assertStringContainsString('<b>', $output, 'Bold styling must survive');
    // The clean-up must not touch attributes. Whether inline styling survives
    // all the way to the page is a separate matter - core runs the rendered
    // value through Xss::filterAdmin(), which drops style attributes - but that
    // is not this method's business to pre-empt.
    $this->assertStringContainsString('style="font-size:12pt"', $output);
  }

  /**
   * Only bare empty paragraphs are stripped, which is the shared definition.
   *
   * These variants are deliberately left alone: the point of delegating to
   * improve_line_breaks is that Localist descriptions and editor-authored rich
   * text agree on what an empty paragraph is, and widening the pattern here
   * would break that. None of these shapes appears in the sampled live
   * payloads - 51 empty paragraphs, all of them bare - so this documents the
   * boundary rather than papering over it. If Localist ever starts emitting
   * one of these, this test is where it becomes visible.
   *
   * @covers ::stripEmptyParagraphs
   */
  public function testLeavesNonBareEmptyParagraphsAlone(): void {
    $variants = [
      '<p style="text-align:center">&nbsp;</p>',
      '<p><span>&nbsp;</span></p>',
      '<p><br /></p>',
      '<p>&#160;</p>',
    ];

    foreach ($variants as $variant) {
      $this->assertSame($variant, $this->metaFieldsManager->stripEmptyParagraphs($variant));
    }
  }

  /**
   * A clean-up failure returns the description rather than discarding it.
   *
   * Invalid UTF-8 makes the delegate's preg_replace() return NULL, which it
   * concatenates as '' - so without the guard a single bad byte would wipe an
   * entire event description and return an empty string, not NULL, with no
   * error anywhere. Not reachable through the importer today (the Localist JSON
   * parser rejects invalid UTF-8), but silent total content loss is the wrong
   * failure mode to leave in place.
   *
   * @covers ::stripEmptyParagraphs
   */
  public function testKeepsTheDescriptionWhenCleanUpFails(): void {
    $invalidUtf8 = "<p>Yale\x92s event</p>\n<p>&nbsp;</p>\n<p>Second paragraph.</p>";

    $this->assertSame($invalidUtf8, $this->metaFieldsManager->stripEmptyParagraphs($invalidUtf8));
  }

}
