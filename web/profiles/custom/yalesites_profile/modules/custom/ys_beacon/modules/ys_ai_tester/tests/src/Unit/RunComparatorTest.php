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

}
