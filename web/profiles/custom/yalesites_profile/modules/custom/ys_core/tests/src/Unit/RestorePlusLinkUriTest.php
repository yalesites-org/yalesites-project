<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests which stored link URIs are candidates for having a "+" restored.
 *
 * Issue #1494 follow-up: the forward fix for #683
 * (patches/contrib/linkit/3436733-5.patch) decoded the URI with urldecode(),
 * which reads "+" as an encoded space. A link to a file named "a+b.pdf" was
 * therefore saved as "a b.pdf" and 404s, because the href core builds asks for
 * a file whose name really does contain a space.
 *
 * A space in a file name is ordinary and legitimate, so recognising a candidate
 * is only half the decision - the caller confirms against the filesystem which
 * of the two names actually exists. This covers the half that is pure: which
 * URIs are even shaped like a candidate, and what the two file names are.
 *
 * @group ys_core
 * @group yalesites
 */
class RestorePlusLinkUriTest extends UnitTestCase {

  /**
   * The public files directory, as PublicStream::basePath() reports it.
   */
  const BASE_PATH = 'sites/default/files';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The helper lives in ys_core.install, which defines functions only.
    require_once __DIR__ . '/../../../ys_core.install';
  }

  /**
   * URIs shaped like a candidate, with the candidate they produce.
   */
  public static function candidateUriProvider(): array {
    return [
      'a single eaten plus' => [
        'internal:/sites/default/files/2026-08/a b.pdf',
        [
          'uri' => 'internal:/sites/default/files/2026-08/a+b.pdf',
          'stored_file' => 'public://2026-08/a b.pdf',
          'plus_file' => 'public://2026-08/a+b.pdf',
        ],
      ],
      'the base scheme, which has no leading slash' => [
        'base:sites/default/files/a b.pdf',
        [
          'uri' => 'base:sites/default/files/a+b.pdf',
          'stored_file' => 'public://a b.pdf',
          'plus_file' => 'public://a+b.pdf',
        ],
      ],
      'several eaten pluses' => [
        'internal:/sites/default/files/a b c.pdf',
        [
          'uri' => 'internal:/sites/default/files/a+b+c.pdf',
          'stored_file' => 'public://a b c.pdf',
          'plus_file' => 'public://a+b+c.pdf',
        ],
      ],
      'a query string is preserved byte for byte' => [
        'internal:/sites/default/files/a b.pdf?v=1+2',
        [
          'uri' => 'internal:/sites/default/files/a+b.pdf?v=1+2',
          'stored_file' => 'public://a b.pdf',
          'plus_file' => 'public://a+b.pdf',
        ],
      ],
      'a fragment is preserved byte for byte' => [
        'internal:/sites/default/files/a b.pdf#page 2',
        [
          'uri' => 'internal:/sites/default/files/a+b.pdf#page 2',
          'stored_file' => 'public://a b.pdf',
          'plus_file' => 'public://a+b.pdf',
        ],
      ],
    ];
  }

  /**
   * A URI whose path holds a space inside the files directory is a candidate.
   *
   * @dataProvider candidateUriProvider
   */
  public function testRecognisesCandidates(string $stored, array $expected): void {
    $this->assertSame($expected, ys_core_restore_plus_link_uri_candidate($stored, self::BASE_PATH));
  }

  /**
   * The candidate it produces is never itself a candidate.
   *
   * @dataProvider candidateUriProvider
   */
  public function testCandidateIsIdempotent(string $stored, array $expected): void {
    $this->assertNull(
      ys_core_restore_plus_link_uri_candidate($expected['uri'], self::BASE_PATH),
      'A URI whose plus is already restored holds no space to convert.'
    );
  }

  /**
   * URIs that are not candidates, whatever the filesystem says.
   */
  public static function nonCandidateProvider(): array {
    return [
      // Nothing was eaten: there is no space to put a plus back into.
      'a file name with no space' => ['internal:/sites/default/files/report.pdf'],
      'a file name that already holds a plus' => ['internal:/sites/default/files/a+b.pdf'],
      // Outside the public files directory there is no file to check against,
      // so there is no evidence either way and the value is left alone.
      'a path outside the files directory' => ['internal:/some page/with a space'],
      'a path that only looks like the files directory' => ['internal:/sites/default/filesystem/a b.pdf'],
      // The files directory itself, with no file name below it to restore.
      'the files directory and nothing else' => ['internal:/sites/default/files/'],
      // Other schemes never reach the local path encoder.
      'an external link' => ['https://example.com/a b.pdf'],
      'a mailto link' => ['mailto:someone@example.com?subject=a b'],
      'an entity reference URI' => ['entity:node/123'],
      'a route URI' => ['route:<front>'],
      // Still percent-encoded, so this is the #683 double-encoding case that
      // ys_core_repair_double_encoded_link_uri() owns, not this one.
      'a percent-encoded space' => ['internal:/sites/default/files/a%20b.pdf'],
      // The space is in the query, which core rebuilds separately and which
      // this repair never rewrites.
      'a space only in the query string' => ['internal:/sites/default/files/report.pdf?q=a b'],
      'a space only in the fragment' => ['internal:/sites/default/files/report.pdf#a b'],
      'an empty string' => [''],
    ];
  }

  /**
   * A URI that is not shaped like a candidate is left alone.
   *
   * @dataProvider nonCandidateProvider
   */
  public function testLeavesNonCandidatesAlone(string $stored): void {
    $this->assertNull(ys_core_restore_plus_link_uri_candidate($stored, self::BASE_PATH));
  }

  /**
   * A space belonging to the files directory itself is not a file name's.
   *
   * A site whose public files path contains a space would otherwise make every
   * link below it look like a candidate.
   */
  public function testIgnoresSpaceInTheFilesDirectoryItself(): void {
    $base = 'sites/default/my files';

    $this->assertNull(
      ys_core_restore_plus_link_uri_candidate('internal:/sites/default/my files/report.pdf', $base),
      'The directory\'s own space is not treated as an eaten plus.'
    );
    $this->assertNull(
      ys_core_restore_plus_link_uri_candidate('internal:/sites/default/my files/', $base),
      'A path naming only the directory has no file name to restore.'
    );
    // A space in the file name is still restored, and the directory's own space
    // survives because the path is rebuilt from the stored bytes.
    $this->assertSame(
      [
        'uri' => 'internal:/sites/default/my files/a+b.pdf',
        'stored_file' => 'public://a b.pdf',
        'plus_file' => 'public://a+b.pdf',
      ],
      ys_core_restore_plus_link_uri_candidate('internal:/sites/default/my files/a b.pdf', $base)
    );
  }

}
