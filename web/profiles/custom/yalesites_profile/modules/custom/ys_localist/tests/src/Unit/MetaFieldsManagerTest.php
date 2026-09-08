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
 * Most fixtures here are synthetic. testStripsFillerFromRealFixture() is the
 * exception: it pins the actual stored value of field_event_description on
 * node 109, read directly off the pr-1426 Pantheon multidev. A census of all
 * 41 event nodes there (38 with a description) found 14 empty filler
 * paragraphs across 6 nodes, in exactly two shapes - 7 bare <p>&nbsp;</p> and
 * 7 <p style="text-align:start">&nbsp;</p> - and zero of the nested-span or
 * bare-<br>-only shapes covered in testLeavesNonBareEmptyParagraphsAlone.
 * That attributed shape is exactly what the original contrib-delegating
 * implementation missed: its regex only ever matched a bare <p>.
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
      'attributed nbsp filler between paragraphs' => [
        "<p>First.</p>\n\n<p style=\"text-align:start\">&nbsp;</p>\n\n<p>Second.</p>",
        "<p>First.</p>\n\n\n\n<p>Second.</p>",
      ],
      'numeric character reference filler' => [
        '<p>First.</p><p>&#160;</p><p>Second.</p>',
        '<p>First.</p><p>Second.</p>',
      ],
      'literal non-breaking space filler' => [
        "<p>First.</p><p>\u{00A0}</p><p>Second.</p>",
        '<p>First.</p><p>Second.</p>',
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
   * Preformatted content is not protected; measured to not matter today.
   *
   * The contrib implementation this replaced skipped over <pre>, <code>,
   * <script> and <iframe> content specifically so a filler-shaped <p> quoted
   * inside one of those tags would not be touched. A plain regex has no such
   * awareness and strips it anywhere it appears - including here. That
   * trade-off was accepted after measuring all 38 real event descriptions on
   * the pr-1426 multidev: none contain any of those four tags, so nothing
   * real is affected today. If that ever changes, this test is where it
   * becomes visible.
   *
   * @covers ::stripEmptyParagraphs
   */
  public function testDoesNotProtectPreformattedContent(): void {
    $description = '<pre><p>&nbsp;</p></pre>';
    $this->assertSame('<pre></pre>', $this->metaFieldsManager->stripEmptyParagraphs($description));
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
   * The exact stored description of a real event, byte for byte.
   *
   * Read from field_event_description on node 109 of the pr-1426 Pantheon
   * multidev. Both empty paragraphs here carry a text-align style - the
   * shape the original contrib-delegating implementation never matched,
   * because getEventData() sees this value before anything downstream
   * strips the style attribute at render time.
   *
   * @covers ::stripEmptyParagraphs
   */
  public function testStripsFillerFromRealFixture(): void {
    $p1 = '<p style="text-align:start"><span style="font-size:11pt"><span><span><span><span style="font-style:normal"><span><span><span style="text-decoration:none"><b><span>DCP | 1995 | Directed by Todd Haynes | USA | 119 minutes | English </span></b></span></span></span></span></span></span></span></span></p>';
    $p2 = '<p style="text-align:start"><span style="font-size:11pt"><span><span><span><span style="font-style:normal"><span><span><span style="text-decoration:none"><span><span><span>Free admission. No registration required.</span></span></span></span></span></span></span></span></span></span></span></p>';
    $filler = '<p style="text-align:start">&nbsp;</p>';
    $p4 = '<p style="text-align:start"><span style="font-size:11pt"><span><span><span><span style="font-style:normal"><span><span><span style="text-decoration:none"><span><span>A mysterious illness alienates Carol White (Julianne Moore) from her suburban enclave in the<i> </i>San Fernando Valley. Is the culprit industrial pollution or perhaps her own psyche? After medical professionals offer no clarity about her worsening symptoms, she seeks solace and protection through alternative means. SAFE exemplifies an ambivalence found throughout Todd Haynes&rsquo; filmography, especially in his most recent feature, MAY DECEMBER (2023), which also stars Julianne Moore. In a 1996 issue of BFI&rsquo;s <i>Sight and Sound</i>, Haynes clarifies that the film alludes to the AIDS epidemic, but themes of ecological disaster and the allure of New Age healing will strike viewers today as prescient. SAFE sidesteps heavy-handed messages, instead prompting us to consider how illness and industrial pollution are bound up with identity and belonging, within and beyond the suburbs of California.</span></span></span></span></span></span></span></span></span></span></p>';
    $p6 = '<p><b style="font-style:normal; text-align:start; text-decoration:none"><span style="font-size:11pt"><span><span><span>Cinema of Contamination </span></span></span></span></b><span style="font-size:11pt; text-align:start"><span style="font-style:normal"><span><span><span style="text-decoration:none"><span><span><span>stages contact, confrontations, and entanglements with enigmatic toxins and landscapes. Spanning suburban California, the Andean mountain range, a forbidden zone, and a poisonous forest, the series examines how to make meaning within ecological collapse and in the aftermath of dictatorship and war.</span></span></span></span></span></span></span></span></p>';

    $description = $p1 . "\n\n" . $p2 . "\n\n" . $filler . "\n\n" . $p4 . "\n\n" . $filler . "\n\n" . $p6 . "\n";
    $expected = $p1 . "\n\n" . $p2 . "\n\n\n\n" . $p4 . "\n\n\n\n" . $p6 . "\n";

    // Pin the fixture to the exact byte count read off the multidev so a
    // transcription slip here is loud, not silently self-consistent.
    $this->assertSame(2510, strlen($description), 'Fixture must match the real stored value byte for byte.');

    $output = $this->metaFieldsManager->stripEmptyParagraphs($description);

    $this->assertSame($expected, $output);
    $this->assertLessThan(strlen($description), strlen($output));

    // Confirm no tag other than the two filler <p> elements was touched.
    $tags = ['<p', '<b', '<i', '<span', '<a ', '<br'];
    foreach ($tags as $tag) {
      $before = substr_count($description, $tag);
      $after = substr_count($output, $tag);
      if ($tag === '<p') {
        $this->assertSame($before - 2, $after, "Expected exactly 2 fewer '$tag' after stripping.");
      }
      else {
        $this->assertSame($before, $after, "'$tag' count must not change.");
      }
    }
  }

  /**
   * Nested or non-whitespace filler is left alone; only blank content is.
   *
   * "Blank" means whitespace and the three ways of writing a non-breaking
   * space (@see stripEmptyParagraphs()) - not any element, however trivial.
   * A <span> wrapping the filler, or a lone <br>, do not count, even though
   * both render as an empty-looking paragraph. Real data on the pr-1426
   * multidev never contains either shape (zero nested-filler paragraphs
   * across all 38 real descriptions), so this documents an intentional
   * boundary rather than a gap being papered over.
   *
   * @covers ::stripEmptyParagraphs
   */
  public function testLeavesNonBareEmptyParagraphsAlone(): void {
    $variants = [
      '<p><span>&nbsp;</span></p>',
      '<p><br /></p>',
    ];

    foreach ($variants as $variant) {
      $this->assertSame($variant, $this->metaFieldsManager->stripEmptyParagraphs($variant));
    }
  }

  /**
   * Invalid UTF-8 elsewhere in the description no longer loses content.
   *
   * The contrib delegate this replaced matched with the /u modifier and
   * appended its result to the description, so a single invalid byte made
   * preg_replace() return NULL, which concatenated as '' and silently wiped
   * the entire description. This implementation never uses /u - the pattern
   * needs no Unicode-aware matching, since its one non-ASCII case (a literal
   * U+00A0) is matched by its UTF-8 bytes directly - so preg_replace() has no
   * such failure mode, and the guard that worked around it was removed.
   *
   * @covers ::stripEmptyParagraphs
   */
  public function testStripsFillerEvenWithInvalidUtf8Bytes(): void {
    $invalidUtf8 = "<p>Yale\x92s event</p>\n<p>&nbsp;</p>\n<p>Second paragraph.</p>";
    $expected = "<p>Yale\x92s event</p>\n\n<p>Second paragraph.</p>";

    $this->assertSame($expected, $this->metaFieldsManager->stripEmptyParagraphs($invalidUtf8));
  }

}
