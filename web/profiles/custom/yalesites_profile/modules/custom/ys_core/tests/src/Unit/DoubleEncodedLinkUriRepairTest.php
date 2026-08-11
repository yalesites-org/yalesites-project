<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests which stored link URIs are treated as double-encoded, and which not.
 *
 * Issue #1494: links saved before the Linkit widget started decoding URIs on
 * save (#683) kept their percent-encoding, so Drupal encoded the path a second
 * time when building the href and the link 404'd. The repair helper has to
 * recognise exactly those values without touching anything else.
 *
 * @group ys_core
 * @group yalesites
 */
class DoubleEncodedLinkUriRepairTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The helper lives in ys_core.install, which defines functions only.
    require_once __DIR__ . '/../../../ys_core.install';
  }

  /**
   * URIs that are stored double-encoded, with the value they repair to.
   */
  public static function repairableUriProvider(): array {
    return [
      'the reported document link' => [
        'internal:/sites/default/files/2025-11/YaleSites%20Profile%20Import%20Template.xlsx',
        'internal:/sites/default/files/2025-11/YaleSites Profile Import Template.xlsx',
      ],
      'base scheme is affected the same way' => [
        'base:sites/default/files/My%20Report.pdf',
        'base:sites/default/files/My Report.pdf',
      ],
      'other encoded characters in a file name' => [
        'internal:/sites/default/files/Budget%20%28final%29.pdf',
        'internal:/sites/default/files/Budget (final).pdf',
      ],
      // A plus is what the Linkit autocomplete percent-encodes a file name's
      // plus to, and rawurlencode() encodes it again into "%252B". Repairing it
      // to a literal plus is correct: core encodes that back to "%2B".
      'an encoded plus, the pre-#683 form of a "+" file name' => [
        'internal:/sites/default/files/2026-08/a%2Bb.pdf',
        'internal:/sites/default/files/2026-08/a+b.pdf',
      ],
      'a query string is preserved byte for byte' => [
        'internal:/sites/default/files/My%20Doc.pdf?token=a%26b',
        'internal:/sites/default/files/My Doc.pdf?token=a%26b',
      ],
      'a fragment is preserved byte for byte' => [
        'internal:/sites/default/files/My%20Doc.pdf#page%2010',
        'internal:/sites/default/files/My Doc.pdf#page%2010',
      ],
    ];
  }

  /**
   * A double-encoded URI is decoded once, in its path only.
   *
   * @dataProvider repairableUriProvider
   */
  public function testRepairsDoubleEncodedUris(string $stored, string $expected): void {
    $this->assertSame($expected, ys_core_repair_double_encoded_link_uri($stored));
  }

  /**
   * Repairing an already-repaired URI reports nothing left to do.
   *
   * @dataProvider repairableUriProvider
   */
  public function testRepairIsIdempotent(string $stored, string $expected): void {
    $this->assertNull(ys_core_repair_double_encoded_link_uri($expected), 'A repaired URI is not decoded a second time.');
  }

  /**
   * URIs that must be left exactly as they are.
   */
  public static function untouchedUriProvider(): array {
    return [
      // Saved after #683: the path already holds a literal space.
      'an already-correct internal link' => ['internal:/sites/default/files/My File.pdf'],
      'an internal path with nothing encoded' => ['internal:/node/12'],
      // External URIs are returned untouched by the unrouted URL assembler, so
      // their percent-escapes never double-encode.
      'an external link' => ['https://example.com/a%20b.pdf'],
      'a mailto link' => ['mailto:someone@example.com?subject=Hello%20there'],
      'an entity reference URI' => ['entity:node/123'],
      'a route URI' => ['route:<front>'],
      // A percent survives decoding, so the value is ambiguous: decoding it
      // again is what a second run of the update hook would do.
      'an already multiply-encoded path' => ['internal:/sites/default/files/My%2520Doc.pdf'],
      'a file name containing a literal percent' => ['internal:/sites/default/files/100%25%20off.pdf'],
      // rawurldecode() leaves "+" alone, so a file named with one is safe.
      'a file name containing a plus' => ['internal:/sites/default/files/a+b.pdf'],
      // Mixing a literal space with an escape is not what core would emit, so
      // the value is not a cleanly double-encoded one.
      'a path mixing a literal space with an escape' => ['internal:/sites/default/files/Caf%C3%A9 Menu.pdf'],
      // Lower-case escapes are not what rawurlencode() produces either.
      'a path with lower-case hex escapes' => ['internal:/sites/default/files/Caf%c3%a9.pdf'],
      'an empty string' => [''],
    ];
  }

  /**
   * A URI that is not cleanly double-encoded is left alone.
   *
   * @dataProvider untouchedUriProvider
   */
  public function testLeavesOtherUrisAlone(string $stored): void {
    $this->assertNull(ys_core_repair_double_encoded_link_uri($stored));
  }

}
