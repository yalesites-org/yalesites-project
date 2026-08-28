<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\RunComparator;

/**
 * Tests the pure comparison logic of the AI Tester run comparator.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester\RunComparator
 *
 * @group ys_beacon
 */
class RunComparatorTest extends UnitTestCase {

  /**
   * The comparator under test (constructed with an unused DB connection).
   */
  protected RunComparator $comparator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // compareResults() is pure: the connection is never touched.
    $this->comparator = new RunComparator($this->createMock(Connection::class));
  }

  /**
   * Builds a run meta array.
   */
  protected function meta(int $id): array {
    return [
      'id' => $id,
      'created' => 1000 + $id,
      'source_filename' => "run$id.yml",
      'status' => 'complete',
    ];
  }

  /**
   * Builds a result row with decoded citations.
   */
  protected function result(string $question, string $answer, array $citations = []): array {
    return [
      'question' => $question,
      'answer' => $answer,
      'citations' => $citations,
    ];
  }

  /**
   * Builds a single normalized citation.
   */
  protected function citation(?string $url, string $title, bool $cited): array {
    return ['url' => $url, 'title' => $title, 'cited' => $cited];
  }

  /**
   * @covers ::compareResults
   */
  public function testIdenticalAnswersAreFlaggedIdentical(): void {
    $out = $this->comparator->compareResults(
      $this->meta(1),
      $this->meta(2),
      [$this->result('Q1', 'Same answer')],
      [$this->result('Q1', 'Same answer')],
    );

    $this->assertCount(1, $out['pairs']);
    $this->assertSame('identical', $out['pairs'][0]['status']);
    $this->assertSame(1, $out['summary']['total_compared']);
    $this->assertSame(1, $out['summary']['identical']);
    $this->assertSame(0, $out['summary']['differ']);
  }

  /**
   * @covers ::compareResults
   */
  public function testDifferingAnswersAreFlaggedDiffers(): void {
    $out = $this->comparator->compareResults(
      $this->meta(1),
      $this->meta(2),
      [$this->result('Q1', 'Answer A')],
      [$this->result('Q1', 'Answer B')],
    );

    $this->assertSame('differs', $out['pairs'][0]['status']);
    $this->assertSame(1, $out['summary']['differ']);
    $this->assertSame(0, $out['summary']['identical']);
  }

  /**
   * @covers ::compareResults
   */
  public function testQuestionOnlyInFirstRunKeepsOrder(): void {
    $out = $this->comparator->compareResults(
      $this->meta(1),
      $this->meta(2),
      [$this->result('Q1', 'x'), $this->result('Q2', 'y')],
      [$this->result('Q1', 'x')],
    );

    $this->assertCount(2, $out['pairs']);
    $this->assertSame('Q1', $out['pairs'][0]['question']);
    $this->assertSame('identical', $out['pairs'][0]['status']);
    $this->assertSame('Q2', $out['pairs'][1]['question']);
    $this->assertSame('only_a', $out['pairs'][1]['status']);
    $this->assertNotNull($out['pairs'][1]['a']);
    $this->assertNull($out['pairs'][1]['b']);
    $this->assertSame(1, $out['summary']['only_a']);
    $this->assertSame(1, $out['summary']['total_compared']);
  }

  /**
   * @covers ::compareResults
   */
  public function testQuestionOnlyInSecondRunIsAppendedLast(): void {
    $out = $this->comparator->compareResults(
      $this->meta(1),
      $this->meta(2),
      [$this->result('Q1', 'x')],
      [$this->result('Q1', 'x'), $this->result('Q3', 'z')],
    );

    $this->assertCount(2, $out['pairs']);
    $this->assertSame('Q1', $out['pairs'][0]['question']);
    $this->assertSame('Q3', $out['pairs'][1]['question']);
    $this->assertSame('only_b', $out['pairs'][1]['status']);
    $this->assertNull($out['pairs'][1]['a']);
    $this->assertNotNull($out['pairs'][1]['b']);
    $this->assertSame(1, $out['summary']['only_b']);
  }

  /**
   * @covers ::compareResults
   */
  public function testDuplicateQuestionsPairByOccurrence(): void {
    $out = $this->comparator->compareResults(
      $this->meta(1),
      $this->meta(2),
      [$this->result('Dup', 'a1'), $this->result('Dup', 'a2')],
      [$this->result('Dup', 'b1')],
    );

    $this->assertCount(2, $out['pairs']);
    // First occurrence pairs a1 with b1 (matched, differing answers).
    $this->assertSame('differs', $out['pairs'][0]['status']);
    $this->assertSame('a1', $out['pairs'][0]['a']['answer']);
    $this->assertSame('b1', $out['pairs'][0]['b']['answer']);
    // Second 'Dup' in A has no partner in B.
    $this->assertSame('only_a', $out['pairs'][1]['status']);
    $this->assertSame('a2', $out['pairs'][1]['a']['answer']);
  }

  /**
   * @covers ::compareResults
   */
  public function testWhitespaceOnlyDifferenceMatchesAndIsIdentical(): void {
    $out = $this->comparator->compareResults(
      $this->meta(1),
      $this->meta(2),
      [$this->result('  Q1  ', 'answer')],
      [$this->result('Q1', '  answer  ')],
    );

    $this->assertCount(1, $out['pairs']);
    $this->assertSame('identical', $out['pairs'][0]['status']);
  }

  /**
   * @covers ::compareResults
   */
  public function testCitationOverlapPartitionsByUrl(): void {
    $out = $this->comparator->compareResults(
      $this->meta(1),
      $this->meta(2),
      [
        $this->result('Q1', 'a', [
          $this->citation('http://x', 'X', TRUE),
          $this->citation('http://y', 'Y', FALSE),
          // Empty URL is skipped from overlap entirely.
          $this->citation('', 'NoUrl', TRUE),
        ]),
      ],
      [
        $this->result('Q1', 'b', [
          $this->citation('http://x', 'X', TRUE),
          $this->citation('http://z', 'Z', TRUE),
        ]),
      ],
    );

    $overlap = $out['pairs'][0]['citation_overlap'];
    $this->assertSame(['http://x'], array_column($overlap['both'], 'url'));
    $this->assertSame(['http://y'], array_column($overlap['only_a'], 'url'));
    $this->assertSame(['http://z'], array_column($overlap['only_b'], 'url'));
  }

  /**
   * @covers ::compareResults
   */
  public function testPerSideSignalsCountCitedRetrievedLengthAndEmpty(): void {
    $out = $this->comparator->compareResults(
      $this->meta(1),
      $this->meta(2),
      [
        $this->result('Q1', 'hello', [
          $this->citation('http://x', 'X', TRUE),
          $this->citation('http://y', 'Y', FALSE),
        ]),
      ],
      [$this->result('Q1', '')],
    );

    $pair = $out['pairs'][0];
    $this->assertSame(5, $pair['a']['len']);
    $this->assertSame(1, $pair['a']['cited']);
    $this->assertSame(2, $pair['a']['retrieved']);
    $this->assertFalse($pair['a']['empty']);

    $this->assertSame(0, $pair['b']['len']);
    $this->assertSame(0, $pair['b']['cited']);
    $this->assertTrue($pair['b']['empty']);

    $this->assertSame(-5, $pair['len_delta']);
  }

  /**
   * @covers ::compareResults
   */
  public function testSummaryTalliesAllStatuses(): void {
    $out = $this->comparator->compareResults(
      $this->meta(1),
      $this->meta(2),
      [
        $this->result('Same', 'x'),
        $this->result('Changed', 'a'),
        $this->result('OnlyA', 'q'),
      ],
      [
        $this->result('Same', 'x'),
        $this->result('Changed', 'b'),
        $this->result('OnlyB', 'r'),
      ],
    );

    $this->assertSame(2, $out['summary']['total_compared']);
    $this->assertSame(1, $out['summary']['identical']);
    $this->assertSame(1, $out['summary']['differ']);
    $this->assertSame(1, $out['summary']['only_a']);
    $this->assertSame(1, $out['summary']['only_b']);
  }

  /**
   * @covers ::compareResults
   */
  public function testRunMetaIsPassedThrough(): void {
    $out = $this->comparator->compareResults(
      $this->meta(7),
      $this->meta(9),
      [$this->result('Q1', 'x')],
      [$this->result('Q1', 'x')],
    );

    $this->assertSame(7, $out['run_a']['id']);
    $this->assertSame(9, $out['run_b']['id']);
    $this->assertSame('run7.yml', $out['run_a']['source_filename']);
  }

  /**
   * The comparison exposes which assistant answered each side.
   *
   * @covers ::compareResults
   */
  public function testRunMetaExposesTheBackend(): void {
    $out = $this->comparator->compareResults(
      ['backend' => 'beacon'] + $this->meta(7),
      ['backend' => 'legacy'] + $this->meta(9),
      [$this->result('Q1', 'x')],
      [$this->result('Q1', 'y')],
    );

    $this->assertSame('beacon', $out['run_a']['backend']);
    $this->assertSame('legacy', $out['run_b']['backend']);
  }

  /**
   * A run stored before backends existed reads back as Beacon.
   *
   * @covers ::compareResults
   */
  public function testRunMetaBackendDefaultsToBeacon(): void {
    $out = $this->comparator->compareResults(
      $this->meta(1),
      $this->meta(2),
      [$this->result('Q1', 'x')],
      [$this->result('Q1', 'x')],
    );

    $this->assertSame('beacon', $out['run_a']['backend']);
    $this->assertSame('beacon', $out['run_b']['backend']);
  }

  /**
   * Returns the overlap partition for a one-question, two-sided comparison.
   *
   * Every host-normalization case below asks the same question of the same
   * shape of input, so the setup lives here rather than in each test.
   */
  protected function overlapOf(array $urls_a, array $urls_b): array {
    $cite = fn (array $urls): array => array_map(
      fn (string $url): array => $this->citation($url, 'T', TRUE),
      $urls,
    );

    $out = $this->comparator->compareResults(
      $this->meta(1),
      $this->meta(2),
      [$this->result('Q1', 'a', $cite($urls_a))],
      [$this->result('Q1', 'b', $cite($urls_b))],
    );

    return $out['pairs'][0]['citation_overlap'];
  }

  /**
   * The reported bug: the same source on two hosts counted as no overlap.
   *
   * Comparing a multidev against production is the normal case for this tool,
   * and before normalization every URL differed by host, so `both` was empty on
   * every question. The URLs here are the ones from the report.
   *
   * @covers ::compareResults
   */
  public function testCitationOverlapMatchesTheSameSourceAcrossHostnames(): void {
    $overlap = $this->overlapOf(
      ['https://v2260-ys-gatewayassist-yale-edu.pantheonsite.io/sites/default/files/2026-06/guide.pdf'],
      ['https://gatewayassist.yale.edu/sites/default/files/2026-06/guide.pdf'],
    );

    $this->assertCount(1, $overlap['both']);
    $this->assertSame([], $overlap['only_a']);
    $this->assertSame([], $overlap['only_b']);
  }

  /**
   * A shared source is displayed with a real URL, not the internal match key.
   *
   * @covers ::compareResults
   */
  public function testSharedSourceIsDisplayedWithItsOriginalUrl(): void {
    $overlap = $this->overlapOf(
      ['https://staging.example.edu/about'],
      ['https://www.example.edu/about'],
    );

    $this->assertSame(
      ['https://staging.example.edu/about'],
      array_column($overlap['both'], 'url'),
    );
    $this->assertSame(['T'], array_column($overlap['both'], 'title'));
  }

  /**
   * A scheme change alone is not a different source.
   *
   * @covers ::compareResults
   */
  public function testCitationOverlapIgnoresTheScheme(): void {
    $overlap = $this->overlapOf(
      ['http://example.edu/about'],
      ['https://example.edu/about'],
    );

    $this->assertCount(1, $overlap['both']);
  }

  /**
   * A trailing slash is not part of a source's identity.
   *
   * @covers ::compareResults
   */
  public function testCitationOverlapIgnoresTrailingSlashes(): void {
    $overlap = $this->overlapOf(
      ['https://a.example.edu/about/'],
      ['https://b.example.edu/about'],
    );

    $this->assertCount(1, $overlap['both']);
  }

  /**
   * Percent-encoded and literal spellings of one path are the same source.
   *
   * @covers ::compareResults
   */
  public function testCitationOverlapNormalisesPercentEncoding(): void {
    $overlap = $this->overlapOf(
      ['https://a.example.edu/files/Supplier%20Guide.pdf'],
      ['https://b.example.edu/files/Supplier Guide.pdf'],
    );

    $this->assertCount(1, $overlap['both']);
  }

  /**
   * A fragment addresses a place inside a source, not a different source.
   *
   * @covers ::compareResults
   */
  public function testCitationOverlapIgnoresTheFragment(): void {
    $overlap = $this->overlapOf(
      ['https://a.example.edu/policies#section-3'],
      ['https://b.example.edu/policies'],
    );

    $this->assertCount(1, $overlap['both']);
  }

  /**
   * A relative citation matches the absolute form of the same path.
   *
   * @covers ::compareResults
   */
  public function testCitationOverlapMatchesRelativeAndAbsoluteForms(): void {
    $overlap = $this->overlapOf(
      ['/about/leadership'],
      ['https://example.edu/about/leadership'],
    );

    $this->assertCount(1, $overlap['both']);
  }

  /**
   * The query string stays significant: it can select a different resource.
   *
   * `/media/3359/download?inline` is a real Beacon citation shape, so dropping
   * the query would merge genuinely distinct sources.
   *
   * @covers ::compareResults
   */
  public function testCitationOverlapKeepsTheQueryStringSignificant(): void {
    $overlap = $this->overlapOf(
      ['https://a.example.edu/media/3359/download?inline'],
      ['https://b.example.edu/media/3359/download?attachment'],
    );

    $this->assertSame([], $overlap['both']);
    $this->assertCount(1, $overlap['only_a']);
    $this->assertCount(1, $overlap['only_b']);
  }

  /**
   * Two different paths on one host stay distinct — no over-matching.
   *
   * @covers ::compareResults
   */
  public function testCitationOverlapKeepsDifferentPathsDistinct(): void {
    $overlap = $this->overlapOf(
      ['https://example.edu/about', 'https://example.edu/contact'],
      ['https://example.edu/about'],
    );

    $this->assertSame(
      ['https://example.edu/about'],
      array_column($overlap['both'], 'url'),
    );
    $this->assertSame(
      ['https://example.edu/contact'],
      array_column($overlap['only_a'], 'url'),
    );
  }

  /**
   * A URL with no path keeps its full value rather than matching every other.
   *
   * This is the guard against the worst failure mode: without it every
   * path-less URL would normalize to the same empty key and a whole run's
   * worth of unrelated sources would be reported as shared. It is also what
   * keeps the pre-existing partition test's bare-host sources distinct.
   *
   * @covers ::compareResults
   */
  public function testCitationOverlapDoesNotMatchTwoBareHosts(): void {
    $overlap = $this->overlapOf(
      ['https://a.example.edu'],
      ['https://b.example.edu'],
    );

    $this->assertSame([], $overlap['both']);
    $this->assertCount(1, $overlap['only_a']);
    $this->assertCount(1, $overlap['only_b']);
  }

  /**
   * Several path-less URLs in one run stay distinct from each other.
   *
   * The dangerous shape is not two runs but one: three sources collapsing onto
   * a single key would silently drop two of them from the partition.
   *
   * @covers ::compareResults
   */
  public function testCitationOverlapKeepsSeveralPathlessUrlsDistinct(): void {
    $overlap = $this->overlapOf(
      ['http://x', 'http://y'],
      ['http://x', 'http://z'],
    );

    $this->assertSame(['http://x'], array_column($overlap['both'], 'url'));
    $this->assertSame(['http://y'], array_column($overlap['only_a'], 'url'));
    $this->assertSame(['http://z'], array_column($overlap['only_b'], 'url'));
  }

  /**
   * Each run reports the host its citations came from.
   *
   * A cross-host comparison is only readable if the reader can see that is
   * what they are looking at.
   *
   * @covers ::compareResults
   */
  public function testRunMetaExposesEachRunsCitationHost(): void {
    $out = $this->comparator->compareResults(
      $this->meta(1),
      $this->meta(2),
      [
        $this->result('Q1', 'a', [
          // A source without a URL cannot name a host; the next one can.
          $this->citation('', 'NoUrl', TRUE),
          $this->citation('https://v2260-ys-gatewayassist-yale-edu.pantheonsite.io/x', 'X', TRUE),
        ]),
      ],
      [
        $this->result('Q1', 'b', [
          $this->citation('https://gatewayassist.yale.edu/x', 'X', TRUE),
        ]),
      ],
    );

    $this->assertSame('v2260-ys-gatewayassist-yale-edu.pantheonsite.io', $out['run_a']['host']);
    $this->assertSame('gatewayassist.yale.edu', $out['run_b']['host']);
  }

  /**
   * A foreign site's page is never confused with the run's own same-named page.
   *
   * A read-only or whole-collection index cites other sites alongside this one,
   * so one run legitimately holds both. Only the run's own host is noise; a
   * foreign host stays in the key. '/about' is among the most common paths on
   * the platform, so collapsing the two would be a high-rate false match.
   *
   * @covers ::compareResults
   */
  public function testCitationOverlapKeepsForeignSitesDistinctFromTheOwnSite(): void {
    $overlap = $this->overlapOf(
      [
        // Two own-site citations, so the run's own host is unambiguous.
        'https://sitea.yale.edu/about',
        'https://sitea.yale.edu/directory',
        // A borrowed chunk from a different site that shares a path.
        'https://siteb.yale.edu/about',
      ],
      ['https://sitea.yale.edu/directory'],
    );

    $this->assertSame(
      ['https://sitea.yale.edu/directory'],
      array_column($overlap['both'], 'url'),
    );
    // The own /about and the foreign /about are both unmatched, and crucially
    // they are two entries rather than one collapsed one.
    $this->assertSame(
      ['https://sitea.yale.edu/about', 'https://siteb.yale.edu/about'],
      array_column($overlap['only_a'], 'url'),
    );
  }

  /**
   * A cross-host comparison still matches its foreign sources.
   *
   * The multidev-vs-production case with a borrowed source in the mix: the own
   * host differs between the runs and is dropped on each side, while the
   * foreign host is identical on both sides and matches as it stands.
   *
   * @covers ::compareResults
   */
  public function testCitationOverlapMatchesOwnAndForeignSourcesAcrossHosts(): void {
    $overlap = $this->overlapOf(
      [
        'https://v2260-sitea-yale-edu.pantheonsite.io/about',
        'https://v2260-sitea-yale-edu.pantheonsite.io/directory',
        'https://siteb.yale.edu/policies',
      ],
      [
        'https://sitea.yale.edu/about',
        'https://sitea.yale.edu/directory',
        'https://siteb.yale.edu/policies',
      ],
    );

    $this->assertCount(3, $overlap['both']);
    $this->assertSame([], $overlap['only_a']);
    $this->assertSame([], $overlap['only_b']);
  }

  /**
   * Surrounding whitespace does not defeat a match.
   *
   * A stored citation_url arrives through ai_search's HTML-to-Markdown
   * conversion and RagRetriever::decodeStoredValue(), neither of which trims,
   * so padding is reachable from real data. Untrimmed, a leading space also
   * stops parse_url finding a host at all.
   *
   * @covers ::compareResults
   */
  public function testCitationOverlapIgnoresSurroundingWhitespace(): void {
    $overlap = $this->overlapOf(
      ["  https://a.example.edu/about\n"],
      ['https://b.example.edu/about'],
    );

    $this->assertCount(1, $overlap['both']);
  }

  /**
   * Two spellings of one source in a single run fold to one overlap entry.
   *
   * Pinned deliberately rather than left implicit: the side's own 'retrieved'
   * count still counts both, so a reader comparing the two numbers on screen
   * should be able to rely on this difference being intended.
   *
   * @covers ::compareResults
   */
  public function testCitationOverlapFoldsTwoSpellingsWithinOneRun(): void {
    $out = $this->comparator->compareResults(
      $this->meta(1),
      $this->meta(2),
      [
        $this->result('Q1', 'a', [
          $this->citation('https://a.example.edu/about', 'T', TRUE),
          $this->citation('https://a.example.edu/about/', 'T', TRUE),
        ]),
      ],
      [
        $this->result('Q1', 'b', [
          $this->citation('https://b.example.edu/about', 'T', TRUE),
        ]),
      ],
    );

    $this->assertCount(1, $out['pairs'][0]['citation_overlap']['both']);
    $this->assertSame(2, $out['pairs'][0]['a']['retrieved']);
  }

  /**
   * An encoded delimiter decodes, which is an accepted collision.
   *
   * Documented in citationMatchKey() as a tradeoff rather than a defect: our
   * citations come from Drupal routes and file URLs, which never carry one.
   * This test exists so that changing the tradeoff has to be deliberate.
   *
   * @covers ::compareResults
   */
  public function testCitationOverlapTreatsAnEncodedSlashAsLiteral(): void {
    $overlap = $this->overlapOf(
      ['https://a.example.edu/files/a%2Fb'],
      ['https://b.example.edu/files/a/b'],
    );

    $this->assertCount(1, $overlap['both']);
  }

  /**
   * A run with no absolute citation URLs reports no host rather than guessing.
   *
   * @covers ::compareResults
   */
  public function testRunMetaHostIsEmptyWhenNoCitationNamesOne(): void {
    $out = $this->comparator->compareResults(
      $this->meta(1),
      $this->meta(2),
      [$this->result('Q1', 'a', [$this->citation('/relative/only', 'R', TRUE)])],
      [$this->result('Q1', 'b')],
    );

    $this->assertSame('', $out['run_a']['host']);
    $this->assertSame('', $out['run_b']['host']);
  }

  /**
   * The run's host is the one it cites most, not the one it cites first.
   *
   * On a borrowed or whole-collection index a foreign site can rank first, and
   * this value both labels the run in the UI and decides which host citation
   * matching treats as noise -- so a confidently wrong host is worse than none.
   *
   * @covers ::compareResults
   */
  public function testRunMetaHostIsTheMostFrequentlyCitedHost(): void {
    $out = $this->comparator->compareResults(
      $this->meta(1),
      $this->meta(2),
      [
        $this->result('Q1', 'a', [
          // A borrowed source ranks first, but the run is not answered on it.
          $this->citation('https://borrowed.yale.edu/policies', 'B', TRUE),
          $this->citation('https://own.yale.edu/about', 'O', TRUE),
          $this->citation('https://own.yale.edu/directory', 'O', TRUE),
        ]),
      ],
      [$this->result('Q1', 'b')],
    );

    $this->assertSame('own.yale.edu', $out['run_a']['host']);
  }

  /**
   * A source only Run B retrieved is reported with Run B's own URL.
   *
   * @covers ::compareResults
   */
  public function testSourceOnlyInSecondRunKeepsItsOwnUrl(): void {
    $overlap = $this->overlapOf(
      ['https://staging.example.edu/about'],
      ['https://www.example.edu/about', 'https://www.example.edu/news'],
    );

    $this->assertSame(
      ['https://www.example.edu/news'],
      array_column($overlap['only_b'], 'url'),
    );
  }

}
