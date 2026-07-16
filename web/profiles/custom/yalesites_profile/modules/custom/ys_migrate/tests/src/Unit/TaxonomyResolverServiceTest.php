<?php

namespace Drupal\Tests\ys_migrate\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\taxonomy\TermInterface;
use Drupal\ys_migrate\Service\TaxonomyResolverService;

/**
 * Unit tests for TaxonomyResolverService.
 *
 * @coversDefaultClass \Drupal\ys_migrate\Service\TaxonomyResolverService
 * @group ys_migrate
 * @group yalesites
 */
class TaxonomyResolverServiceTest extends UnitTestCase {

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The taxonomy term storage mock.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $termStorage;

  /**
   * The logger channel mock.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerChannel;

  /**
   * The service under test.
   *
   * @var \Drupal\ys_migrate\Service\TaxonomyResolverService
   */
  protected $taxonomyResolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->termStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->with('taxonomy_term')
      ->willReturn($this->termStorage);

    $this->loggerChannel = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->with('ys_migrate')->willReturn($this->loggerChannel);

    $this->taxonomyResolver = new TaxonomyResolverService($this->entityTypeManager, $loggerFactory);
  }

  /**
   * Builds a query mock that returns the given tids from execute().
   */
  protected function mockQuery(array $tids): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('execute')->willReturn($tids);
    return $query;
  }

  /**
   * FindOrCreateTerm() loads and returns an existing term when one matches.
   *
   * @covers ::findOrCreateTerm
   */
  public function testFindOrCreateTermReturnsExistingTerm() {
    $term = $this->createMock(TermInterface::class);
    $this->termStorage->method('getQuery')->willReturn($this->mockQuery([5 => '5']));
    $this->termStorage->expects($this->once())->method('load')->with('5')->willReturn($term);
    $this->termStorage->expects($this->never())->method('create');

    $result = $this->taxonomyResolver->findOrCreateTerm('Existing Term', 'tags');

    $this->assertSame($term, $result);
  }

  /**
   * FindOrCreateTerm() creates and saves a new term when none matches.
   *
   * @covers ::findOrCreateTerm
   */
  public function testFindOrCreateTermCreatesNewTerm() {
    $term = $this->createMock(TermInterface::class);
    $term->expects($this->once())->method('save');

    $this->termStorage->method('getQuery')->willReturn($this->mockQuery([]));
    $this->termStorage->expects($this->once())
      ->method('create')
      ->with(['vid' => 'tags', 'name' => 'New Term'])
      ->willReturn($term);

    $result = $this->taxonomyResolver->findOrCreateTerm('New Term', 'tags');

    $this->assertSame($term, $result);
  }

  /**
   * FindOrCreateTerm() logs and returns NULL when term creation fails.
   *
   * @covers ::findOrCreateTerm
   */
  public function testFindOrCreateTermReturnsNullWhenSaveFails() {
    $term = $this->createMock(TermInterface::class);
    $term->method('save')->willThrowException(new \Exception('Database error'));

    $this->termStorage->method('getQuery')->willReturn($this->mockQuery([]));
    $this->termStorage->method('create')->willReturn($term);

    $this->loggerChannel->expects($this->once())
      ->method('error')
      ->with(
        'Failed to create taxonomy term @name in @vocabulary: @error',
        [
          '@name' => 'Broken Term',
          '@vocabulary' => 'tags',
          '@error' => 'Database error',
        ]
      );

    $result = $this->taxonomyResolver->findOrCreateTerm('Broken Term', 'tags');

    $this->assertNull($result);
  }

  /**
   * ResolveTerms() collects the id of each resolved term, in order.
   *
   * FindOrCreateTerm() is stubbed out here (via a partial mock) so this
   * test isolates resolveTerms()'s own aggregation logic; findOrCreateTerm()
   * itself is covered directly above.
   *
   * @covers ::resolveTerms
   */
  public function testResolveTermsCollectsIds() {
    $termA = $this->createMock(TermInterface::class);
    $termA->method('id')->willReturn(1);
    $termC = $this->createMock(TermInterface::class);
    $termC->method('id')->willReturn(3);

    $resolver = $this->getMockBuilder(TaxonomyResolverService::class)
      ->setConstructorArgs([$this->entityTypeManager, $this->createMock(LoggerChannelFactoryInterface::class)])
      ->onlyMethods(['findOrCreateTerm'])
      ->getMock();
    $resolver->method('findOrCreateTerm')
      ->willReturnOnConsecutiveCalls($termA, NULL, $termC);

    $result = $resolver->resolveTerms(['Term A', 'Term B', 'Term C'], 'tags');

    $this->assertSame([1, 3], $result);
  }

  /**
   * ResolveTerms() returns an empty array for an empty term name list.
   *
   * @covers ::resolveTerms
   */
  public function testResolveTermsWithEmptyList() {
    $this->termStorage->expects($this->never())->method('getQuery');

    $result = $this->taxonomyResolver->resolveTerms([], 'tags');

    $this->assertSame([], $result);
  }

  /**
   * ParseCommaSeparatedValues() splits and trims a comma-separated string.
   *
   * @covers ::parseCommaSeparatedValues
   */
  public function testParseCommaSeparatedValuesWithMultipleValues() {
    $result = $this->taxonomyResolver->parseCommaSeparatedValues('Alpha, Beta,Gamma');

    $this->assertSame(['Alpha', 'Beta', 'Gamma'], $result);
  }

  /**
   * ParseCommaSeparatedValues() returns an empty array for an empty string.
   *
   * @covers ::parseCommaSeparatedValues
   */
  public function testParseCommaSeparatedValuesWithEmptyString() {
    $this->assertSame([], $this->taxonomyResolver->parseCommaSeparatedValues(''));
  }

  /**
   * ParseCommaSeparatedValues() drops genuinely empty segments.
   *
   * Leading, trailing, and doubled-up commas produce empty ('') segments
   * that are filtered out entirely -- but array_filter() preserves the
   * original explode() indices, so the surviving values keep their original
   * (non-sequential) keys rather than being renumbered from 0.
   *
   * @covers ::parseCommaSeparatedValues
   */
  public function testParseCommaSeparatedValuesDropsEmptySegments() {
    $result = $this->taxonomyResolver->parseCommaSeparatedValues(',Alpha,,Beta,');

    $this->assertSame([1 => 'Alpha', 3 => 'Beta'], $result);
  }

  /**
   * A whitespace-only segment survives filtering and becomes an empty string.
   *
   * Array_filter() runs before array_map('trim', ...) in the implementation,
   * so a segment of only spaces (truthy before trimming) is not dropped the
   * way a literally empty segment is -- it instead comes out as ''. This
   * characterizes that exact, easy-to-miss ordering rather than the more
   * intuitive "no empty strings in the result" behavior.
   *
   * @covers ::parseCommaSeparatedValues
   */
  public function testParseCommaSeparatedValuesWhitespaceOnlySegmentBecomesEmptyString() {
    $result = $this->taxonomyResolver->parseCommaSeparatedValues('Alpha, ,Beta');

    $this->assertSame(['Alpha', '', 'Beta'], $result);
  }

}
