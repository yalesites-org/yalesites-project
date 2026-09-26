<?php

namespace Drupal\Tests\ys_views_content_resources\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\taxonomy\TermInterface;
use Drupal\taxonomy\TermStorageInterface;
use Drupal\ys_views_content_resources\ExposedTaxonomyFilterOptions;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ExposedTaxonomyFilterOptions.
 *
 * @coversDefaultClass \Drupal\ys_views_content_resources\ExposedTaxonomyFilterOptions
 * @group ys_views_content_resources
 * @group yalesites
 */
class ExposedTaxonomyFilterOptionsTest extends UnitTestCase {

  /**
   * The term storage mock.
   *
   * @var \Drupal\taxonomy\TermStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $termStorage;

  /**
   * The logger mock.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The service under test.
   *
   * @var \Drupal\ys_views_content_resources\ExposedTaxonomyFilterOptions
   */
  protected $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->termStorage = $this->createMock(TermStorageInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('taxonomy_term')
      ->willReturn($this->termStorage);

    $this->logger = $this->createMock(LoggerInterface::class);

    $this->service = new ExposedTaxonomyFilterOptions($entityTypeManager, $this->logger);
  }

  /**
   * Builds the minimal filters array Views would hold for a taxonomy filter.
   */
  protected function filtersWith(string $name, string $vid): array {
    return [
      $name => [
        'id' => $name,
        'plugin_id' => 'taxonomy_index_tid',
        'vid' => $vid,
        'expose' => ['reduce' => FALSE],
      ],
    ];
  }

  /**
   * Makes loadTree() return the given tids for a vocabulary/parent.
   */
  protected function stubTree(string $vid, int $parent, array $tids): void {
    $terms = array_map(static fn ($tid) => (object) ['tid' => $tid], $tids);
    $this->termStorage->expects($this->once())
      ->method('loadTree')
      ->with($vid, $parent, NULL)
      ->willReturn($terms);
  }

  /**
   * Makes loadMultiple() return terms of the given tid => vocabulary map.
   */
  protected function stubTerms(array $vocabularies): void {
    $terms = [];
    foreach ($vocabularies as $tid => $vid) {
      $term = $this->createMock(TermInterface::class);
      $term->method('bundle')->willReturn($vid);
      $terms[$tid] = $term;
    }
    $this->termStorage->method('loadMultiple')->willReturn($terms);
  }

  /**
   * @covers ::apply
   */
  public function testExcludedTermsAreRemovedFromWholeVocabulary() {
    $this->stubTerms([2 => 'resource_category', 4 => 'resource_category']);
    $this->stubTree('resource_category', 0, [1, 2, 3, 4]);
    $filters = $this->filtersWith('field_category_target_id', 'resource_category');

    $this->assertTrue($this->service->apply($filters, 'field_category_target_id', [2, 4]));

    $this->assertSame([1 => 1, 3 => 3], $filters['field_category_target_id']['value']);
    $this->assertTrue($filters['field_category_target_id']['limit']);
    $this->assertTrue($filters['field_category_target_id']['expose']['reduce']);
  }

  /**
   * @covers ::apply
   */
  public function testParentLimitsToDescendants() {
    $this->stubTree('custom_vocab', 10, [11, 12]);
    $filters = $this->filtersWith('field_custom_vocab_target_id', 'custom_vocab');

    $this->assertTrue($this->service->apply($filters, 'field_custom_vocab_target_id', [], 10));

    $this->assertSame([11 => 11, 12 => 12], $filters['field_custom_vocab_target_id']['value']);
  }

  /**
   * @covers ::apply
   */
  public function testParentAndExclusionsCombine() {
    $this->stubTerms([12 => 'resource_category']);
    $this->stubTree('resource_category', 10, [11, 12, 13]);
    $filters = $this->filtersWith('field_category_target_id', 'resource_category');

    $this->service->apply($filters, 'field_category_target_id', ['12'], 10);

    $this->assertSame([11 => 11, 13 => 13], $filters['field_category_target_id']['value']);
  }

  /**
   * @covers ::apply
   */
  public function testExcludedIdsOutsideVocabularyAreIgnored() {
    // 999 is a tag; it cannot appear in the audience filter, so the filter
    // keeps its configured options and no tree is loaded.
    $this->stubTerms([999 => 'tags']);
    $this->termStorage->expects($this->never())->method('loadTree');
    $filters = $this->filtersWith('field_audience_target_id', 'audience');
    $before = $filters;

    $this->assertFalse($this->service->apply($filters, 'field_audience_target_id', [999]));

    $this->assertSame($before, $filters);
  }

  /**
   * @covers ::apply
   */
  public function testOnlyExcludedIdsInVocabularyAreRemoved() {
    $this->stubTerms([21 => 'audience', 999 => 'tags']);
    $this->stubTree('audience', 0, [20, 21]);
    $filters = $this->filtersWith('field_audience_target_id', 'audience');

    $this->assertTrue($this->service->apply($filters, 'field_audience_target_id', [21, 999]));

    $this->assertSame([20 => 20], $filters['field_audience_target_id']['value']);
  }

  /**
   * @covers ::apply
   */
  public function testParentStillAppliesWhenExcludedIdsAreOutsideVocabulary() {
    $this->stubTerms([999 => 'tags']);
    $this->stubTree('custom_vocab', 10, [11, 12]);
    $filters = $this->filtersWith('field_custom_vocab_target_id', 'custom_vocab');

    $this->assertTrue($this->service->apply($filters, 'field_custom_vocab_target_id', [999], 10));

    $this->assertSame([11 => 11, 12 => 12], $filters['field_custom_vocab_target_id']['value']);
  }

  /**
   * @covers ::apply
   */
  public function testNoConstraintLeavesFilterUntouched() {
    $this->termStorage->expects($this->never())->method('loadTree');
    $filters = $this->filtersWith('field_category_target_id', 'resource_category');
    $before = $filters;

    $this->assertFalse($this->service->apply($filters, 'field_category_target_id', []));
    $this->assertFalse($this->service->apply($filters, 'field_category_target_id', [], 0));

    $this->assertSame($before, $filters);
  }

  /**
   * @covers ::apply
   */
  public function testMissingFilterIsNoOp() {
    $this->termStorage->expects($this->never())->method('loadTree');
    $filters = $this->filtersWith('field_category_target_id', 'resource_category');
    $before = $filters;

    $this->assertFalse($this->service->apply($filters, 'field_nope_target_id', [1]));

    $this->assertSame($before, $filters);
  }

  /**
   * @covers ::apply
   */
  public function testFilterWithoutVocabularyIsNoOpAndLogs() {
    $this->termStorage->expects($this->never())->method('loadTree');
    $this->logger->expects($this->once())->method('warning');
    $filters = $this->filtersWith('field_category_target_id', 'resource_category');
    unset($filters['field_category_target_id']['vid']);
    $before = $filters;

    $this->assertFalse($this->service->apply($filters, 'field_category_target_id', [1]));

    $this->assertSame($before, $filters);
  }

  /**
   * @covers ::reduceTermsForExposure
   */
  public function testReduceTermsForExposure() {
    $available = [1 => 1, 2 => 2, 3 => 3];

    $this->assertSame($available, ExposedTaxonomyFilterOptions::reduceTermsForExposure($available, []));
    $this->assertSame([1 => 1, 3 => 3], ExposedTaxonomyFilterOptions::reduceTermsForExposure($available, [2, 99]));
  }

}
