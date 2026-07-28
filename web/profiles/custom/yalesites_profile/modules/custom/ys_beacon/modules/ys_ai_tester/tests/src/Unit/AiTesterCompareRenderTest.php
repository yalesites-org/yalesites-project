<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\AnswerBackendRegistry;
use Drupal\ys_ai_tester\Controller\AiTesterController;
use Drupal\ys_ai_tester\RunComparator;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests that the comparison controller renders all pair states without error.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester\Controller\AiTesterController
 *
 * @group ys_beacon
 */
class AiTesterCompareRenderTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // compare() and its helpers call $this->t() and Url::fromRoute().
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * A side entry as produced by RunComparator::side().
   */
  protected function side(string $answer, int $cited, int $retrieved, bool $empty, string $error = ''): array {
    return [
      'error' => $error,
      'answer' => $answer,
      'citations' => [],
      'len' => mb_strlen($answer),
      'cited' => $cited,
      'retrieved' => $retrieved,
      'empty' => $empty,
    ];
  }

  /**
   * A run meta entry as produced by RunComparator::metaArray().
   */
  protected function runMeta(int $id, string $file, string $backend = 'beacon'): array {
    return [
      'id' => $id,
      'created' => $id * 1000,
      'source_filename' => $file,
      'status' => 'complete',
      'backend' => $backend,
    ];
  }

  /**
   * Builds the controller with a comparator stubbed to return $data.
   */
  protected function controllerReturning(array $data): AiTesterController {
    $comparator = $this->createMock(RunComparator::class);
    $comparator->method('compare')->willReturn($data);

    $date_formatter = $this->createMock(DateFormatterInterface::class);
    $date_formatter->method('format')->willReturn('2026-06-20');

    $registry = $this->createMock(AnswerBackendRegistry::class);
    $registry->method('labelFor')->willReturnCallback(
      static fn (string $id): string => ucfirst($id)
    );

    return new AiTesterController(
      $this->createMock(Connection::class),
      $date_formatter,
      $comparator,
      $registry,
    );
  }

  /**
   * @covers ::compare
   * @covers ::comparisonRow
   * @covers ::sideCell
   * @covers ::runMetaBlock
   * @covers ::statusLabel
   */
  public function testCompareRendersEveryPairState(): void {
    $data = [
      'run_a' => $this->runMeta(2, 'a.yml'),
      'run_b' => $this->runMeta(3, 'b.yml'),
      'pairs' => [
        [
          'question' => 'What is Yale?',
          'status' => 'differs',
          'a' => $this->side('Yale is a university.', 1, 2, FALSE),
          'b' => $this->side('Yale is a private university.', 2, 2, FALSE),
          'len_delta' => 7,
          'citation_overlap' => [
            'both' => [['url' => 'https://yale.edu', 'title' => 'Yale']],
            'only_a' => [['url' => 'https://a.example', 'title' => 'About']],
            'only_b' => [['url' => 'https://news.yale.edu', 'title' => 'News']],
          ],
        ],
        [
          'question' => 'Identical?',
          'status' => 'identical',
          'a' => $this->side('Same.', 0, 0, FALSE),
          'b' => $this->side('Same.', 0, 0, FALSE),
          'len_delta' => 0,
          'citation_overlap' => ['both' => [], 'only_a' => [], 'only_b' => []],
        ],
        [
          'question' => 'Only in A?',
          'status' => 'only_a',
          'a' => $this->side('A only.', 0, 0, FALSE),
          'b' => NULL,
          'len_delta' => 0,
          'citation_overlap' => ['both' => [], 'only_a' => [], 'only_b' => []],
        ],
        [
          'question' => 'Empty in B?',
          'status' => 'only_b',
          'a' => NULL,
          'b' => $this->side('', 0, 0, TRUE),
          'len_delta' => 0,
          'citation_overlap' => ['both' => [], 'only_a' => [], 'only_b' => []],
        ],
      ],
      'summary' => [
        'total_compared' => 2,
        'differ' => 1,
        'identical' => 1,
        'only_a' => 1,
        'only_b' => 1,
      ],
    ];

    $build = $this->controllerReturning($data)->compare(2, 3);

    // The render must build without a type error (regression: runMetaBlock once
    // rejected the t() "Run A" label) and expose the expected sections.
    $this->assertArrayHasKey('summary', $build);
    $this->assertArrayHasKey('meta', $build);
    $this->assertArrayHasKey('downloads', $build);
    $this->assertArrayHasKey('results', $build);
    $this->assertArrayHasKey('#markup', $build['meta']['a']);
    $this->assertCount(4, $build['results']['#rows']);
    // Each row has the four columns: status, question, run A, run B.
    $this->assertCount(4, $build['results']['#rows'][0]);
  }

  /**
   * The compare view renders unique sources as new-window citation links.
   *
   * @covers ::sideCell
   * @covers ::citationLink
   */
  public function testCompareUniqueSourcesAreNewWindowLinks(): void {
    $data = [
      'run_a' => $this->runMeta(2, 'a.yml'),
      'run_b' => $this->runMeta(3, 'b.yml'),
      'pairs' => [
        [
          'question' => 'What is Yale?',
          'status' => 'differs',
          'a' => $this->side('Yale is a university.', 1, 2, FALSE),
          'b' => $this->side('Yale is a private university.', 2, 2, FALSE),
          'len_delta' => 7,
          'citation_overlap' => [
            'both' => [],
            'only_a' => [
              ['url' => 'https://a.example', 'title' => 'About A'],
              ['url' => 'javascript:alert(1)', 'title' => 'Sneaky'],
            ],
            'only_b' => [['url' => 'https://news.yale.edu', 'title' => 'News']],
          ],
        ],
      ],
      'summary' => [
        'total_compared' => 1,
        'differ' => 1,
        'identical' => 0,
        'only_a' => 0,
        'only_b' => 0,
      ],
    ];

    $build = $this->controllerReturning($data)->compare(2, 3);
    // Row 0, column index 2 is Run A's side cell; its 'unique' element lists
    // the sources only Run A retrieved.
    $unique = $build['results']['#rows'][0][2]['data']['unique'];

    // The http(s) source becomes a link that opens in a new window, hardened
    // with rel and carrying the visually-hidden a11y cue.
    $link = $unique['link_0'];
    $this->assertSame('link', $link['#type']);
    $this->assertSame('_blank', $link['#attributes']['target']);
    $this->assertSame('noopener noreferrer', $link['#attributes']['rel']);
    $this->assertSame('https://a.example', $link['#url']->getUri());
    $title = (string) $link['#title'];
    $this->assertStringContainsString('About A', $title);
    $this->assertStringContainsString('visually-hidden', $title);
    $this->assertStringContainsString('opens in new window', $title);

    // A non-http(s) URL never renders as a live link — it degrades to text.
    $sneaky = $unique['link_1'];
    $this->assertArrayNotHasKey('#type', $sneaky);
    $this->assertArrayHasKey('#markup', $sneaky);
    $this->assertStringNotContainsString('<a', (string) $sneaky['#markup']);
  }

  /**
   * The single-run detail view links citations the same new-window way.
   *
   * @covers ::buildCitationsCell
   * @covers ::citationLink
   */
  public function testDetailCitationsAreNewWindowLinks(): void {
    $controller = $this->controllerReturning([]);
    $method = new \ReflectionMethod($controller, 'buildCitationsCell');
    $method->setAccessible(TRUE);

    $cell = $method->invoke($controller, [
      ['title' => 'Yale', 'url' => 'https://yale.edu', 'cited' => TRUE],
      ['title' => 'No link', 'url' => 'mailto:x@example.com', 'cited' => FALSE],
    ]);

    $link = $cell['#items'][0]['link'];
    $this->assertSame('link', $link['#type']);
    $this->assertSame('_blank', $link['#attributes']['target']);
    $this->assertSame('noopener noreferrer', $link['#attributes']['rel']);
    $this->assertStringContainsString('opens in new window', (string) $link['#title']);

    // Non-http(s) citation stays plain text.
    $fallback = $cell['#items'][1]['link'];
    $this->assertArrayNotHasKey('#type', $fallback);
    $this->assertArrayHasKey('#markup', $fallback);
  }

  /**
   * Builds a minimal one-pair comparison over the two given backends.
   *
   * @param string $backend_a
   *   Run A's backend id.
   * @param string $backend_b
   *   Run B's backend id.
   * @param array $side_b
   *   Run B's side entry.
   *
   * @return array
   *   A comparison structure ready to hand to the stubbed comparator.
   */
  protected function comparisonOf(string $backend_a, string $backend_b, array $side_b): array {
    return [
      'run_a' => $this->runMeta(2, 'a.txt', $backend_a),
      'run_b' => $this->runMeta(3, 'a.txt', $backend_b),
      'pairs' => [
        [
          'question' => 'What are the office hours?',
          'status' => 'differs',
          'a' => $this->side('Beacon answer.', 1, 1, FALSE),
          'b' => $side_b,
          'len_delta' => 0,
          'citation_overlap' => ['both' => [], 'only_a' => [], 'only_b' => []],
        ],
      ],
      'summary' => [
        'total_compared' => 1,
        'differ' => 1,
        'identical' => 0,
        'only_a' => 0,
        'only_b' => 0,
      ],
    ];
  }

  /**
   * Comparing two assistants warns that answers are expected to differ.
   *
   * @covers ::compare
   * @covers ::crossAssistantCaveat
   */
  public function testCrossAssistantComparisonShowsCaveat(): void {
    $data = $this->comparisonOf('beacon', 'legacy', $this->side('Legacy answer.', 1, 1, FALSE));

    $build = $this->controllerReturning($data)->compare(2, 3);

    $this->assertArrayHasKey('#markup', $build['caveat']);
    $caveat = (string) $build['caveat']['#markup'];
    $this->assertStringContainsString('different assistants', $caveat);
    $this->assertStringContainsString('not a regression', $caveat);
    $this->assertStringContainsString('Beacon', $caveat);
    $this->assertStringContainsString('Legacy', $caveat);
  }

  /**
   * Comparing two runs of one assistant shows no caveat at all.
   *
   * @covers ::compare
   * @covers ::crossAssistantCaveat
   */
  public function testSameAssistantComparisonHasNoCaveat(): void {
    $data = $this->comparisonOf('beacon', 'beacon', $this->side('Second answer.', 1, 1, FALSE));

    $build = $this->controllerReturning($data)->compare(2, 3);

    $this->assertSame([], $build['caveat']);
  }

  /**
   * A recorded error is reported instead of reading as an empty answer.
   *
   * @covers ::sideCell
   */
  public function testErroredSideReportsTheError(): void {
    $errored = $this->side('', 0, 0, TRUE, 'cURL error 28: Operation timed out');
    $data = $this->comparisonOf('beacon', 'legacy', $errored);

    $build = $this->controllerReturning($data)->compare(2, 3);
    $meta = (string) $build['results']['#rows'][0][3]['data']['meta']['#markup'];

    $this->assertStringContainsString('Operation timed out', $meta);
    $this->assertStringNotContainsString('Empty answer', $meta);
  }

}
