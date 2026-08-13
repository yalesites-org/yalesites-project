<?php

namespace Drupal\Tests\ys_migrate\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\field\FieldConfigInterface;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\node\NodeInterface;
use Drupal\ys_migrate\Service\ResourceImportService;
use Drupal\ys_migrate\Service\TaxonomyResolverService;

/**
 * Unit tests for ResourceImportService.
 *
 * @coversDefaultClass \Drupal\ys_migrate\Service\ResourceImportService
 * @group ys_migrate
 * @group yalesites
 */
class ResourceImportServiceTest extends UnitTestCase {

  /**
   * The taxonomy resolver mock.
   *
   * @var \Drupal\ys_migrate\Service\TaxonomyResolverService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $taxonomyResolver;

  /**
   * The node storage mock.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $nodeStorage;

  /**
   * The field storage config storage mock.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fieldStorage;

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The logger channel mock.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerChannel;

  /**
   * The logger factory mock.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerFactory;

  /**
   * The current user mock.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * The service under test.
   *
   * @var \Drupal\ys_migrate\Service\ResourceImportService
   */
  protected $resourceImport;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->currentUser->method('id')->willReturn(42);

    $this->taxonomyResolver = $this->createMock(TaxonomyResolverService::class);

    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $this->fieldStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(function ($entity_type) {
        return $entity_type === 'node' ? $this->nodeStorage : $this->fieldStorage;
      });

    $this->loggerChannel = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->loggerFactory->method('get')->with('ys_migrate')->willReturn($this->loggerChannel);

    $this->resourceImport = new ResourceImportService(
      $this->currentUser,
      $this->taxonomyResolver,
      $this->entityTypeManager,
      $this->loggerFactory
    );
    $this->resourceImport->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Builds a query mock that returns the given nids from execute().
   */
  protected function mockQuery(array $nids): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('execute')->willReturn($nids);
    return $query;
  }

  /**
   * Makes the taxonomy resolver behave like the real comma splitter.
   */
  protected function passThroughTaxonomyParsing(): void {
    $this->taxonomyResolver->method('parseCommaSeparatedValues')
      ->willReturnCallback(function ($value) {
        return $value === '' ? [] : array_map('trim', array_filter(explode(',', $value)));
      });
  }

  /**
   * A full CSV row is trimmed and reshaped into the expected resource data.
   *
   * @covers ::prepareResourceData
   */
  public function testPrepareResourceDataWithFullRow() {
    $this->passThroughTaxonomyParsing();

    $row = [
      'title' => '  Annual Report  ',
      'description' => ' A yearly summary. ',
      'resource category' => 'Reports, Finance',
      'audience' => 'Alumni',
      'custom vocab' => 'Custom A',
      'resource publication date' => '2026-03-04',
      'date format' => 'Month/Year',
      'tags' => 'tag1, tag2',
      'teaser title' => ' Report ',
      'teaser text' => ' Short teaser. ',
      'external source' => ' https://example.com/report.pdf ',
      'cas login required' => 'Yes',
      'pin to beginning of list' => 'No',
    ];

    $result = $this->resourceImport->prepareResourceData($row);

    $this->assertSame('Annual Report', $result['title']);
    $this->assertSame('A yearly summary.', $result['description']);
    $this->assertSame(['Reports', 'Finance'], $result['category']);
    $this->assertSame(['Alumni'], $result['audience']);
    $this->assertSame(['Custom A'], $result['custom_vocab']);
    $this->assertSame(['tag1', 'tag2'], $result['tags']);
    $this->assertSame('2026-03-04', $result['publish_date']);
    $this->assertSame('month_year', $result['date_format']);
    $this->assertSame('Report', $result['teaser_title']);
    $this->assertSame('Short teaser.', $result['teaser_text']);
    $this->assertSame('https://example.com/report.pdf', $result['external_source']);
    $this->assertTrue($result['login_required']);
    $this->assertFalse($result['sticky']);
  }

  /**
   * The stored text format is read from each field's own allowed_formats.
   *
   * @covers ::createResourceNode
   */
  public function testCreateResourceNodeReadsTextFormatFromFieldConfig() {
    $this->taxonomyResolver->method('resolveTerms')->willReturn([]);

    $field = $this->createMock(FieldConfigInterface::class);
    $field->method('getSetting')->with('allowed_formats')->willReturn(['some_other_html']);
    $this->fieldStorage->method('load')->willReturn($field);

    $captured = NULL;
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('create')
      ->willReturnCallback(function ($values) use (&$captured, $node) {
        $captured = $values;
        return $node;
      });

    $this->resourceImport->createResourceNode(
      ['title' => 'Configured', 'description' => 'Body.'] + $this->emptyData()
    );

    $this->assertSame('some_other_html', $captured['field_content_description']['format']);
  }

  /**
   * A row with only a title yields empty values for everything else.
   *
   * @covers ::prepareResourceData
   */
  public function testPrepareResourceDataWithTitleOnly() {
    $this->passThroughTaxonomyParsing();

    $result = $this->resourceImport->prepareResourceData(['title' => 'Bare Resource']);

    $this->assertSame('Bare Resource', $result['title']);
    $this->assertSame('', $result['description']);
    $this->assertSame([], $result['tags']);
    $this->assertNull($result['publish_date']);
    $this->assertNull($result['date_format']);
    $this->assertSame('', $result['external_source']);
    $this->assertFalse($result['login_required']);
    $this->assertFalse($result['sticky']);
  }

  /**
   * A repeated title inside one file counts as a duplicate in the preview.
   *
   * The preview is the feature's safety net, so it must not promise two
   * creations where the import would make one and skip the other.
   *
   * @covers ::previewImport
   */
  public function testPreviewImportCountsRepeatedTitlesWithinTheFile() {
    $this->passThroughTaxonomyParsing();
    $this->nodeStorage->method('getQuery')->willReturn($this->mockQuery([]));

    $result = $this->resourceImport->previewImport([
      ['title' => 'Same Name'],
      ['title' => 'Same Name'],
    ], TRUE);

    $this->assertCount(1, $result['valid_resources']);
    $this->assertSame(['Same Name'], $result['duplicates']);
  }

  /**
   * An unparseable date is rejected rather than silently guessed at.
   *
   * @covers ::prepareResourceData
   */
  public function testPrepareResourceDataRejectsUnparseableDate() {
    $this->passThroughTaxonomyParsing();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Resource Publication Date');

    $this->resourceImport->prepareResourceData([
      'title' => 'Bad Date',
      'resource publication date' => 'sometime last spring',
    ]);
  }

  /**
   * A date that looks valid but is not a real day is rejected.
   *
   * @covers ::prepareResourceData
   */
  public function testPrepareResourceDataRejectsImpossibleDate() {
    $this->passThroughTaxonomyParsing();

    $this->expectException(\InvalidArgumentException::class);

    $this->resourceImport->prepareResourceData([
      'title' => 'Impossible Date',
      'resource publication date' => '2026-02-31',
    ]);
  }

  /**
   * Date Format accepts the stored key as well as the human label.
   *
   * @covers ::prepareResourceData
   */
  public function testPrepareResourceDataAcceptsDateFormatKeyOrLabel() {
    $this->passThroughTaxonomyParsing();

    $byKey = $this->resourceImport->prepareResourceData([
      'title' => 'Key',
      'date format' => 'year_only',
    ]);
    $byLabel = $this->resourceImport->prepareResourceData([
      'title' => 'Label',
      'date format' => 'month/day/year',
    ]);

    $this->assertSame('year_only', $byKey['date_format']);
    $this->assertSame('date', $byLabel['date_format']);
  }

  /**
   * Date Format options come from the field's own storage config.
   *
   * A value added to field.storage.node.field_date_format must be accepted
   * without editing this service.
   *
   * @covers ::prepareResourceData
   */
  public function testPrepareResourceDataReadsDateFormatOptionsFromFieldConfig() {
    $this->passThroughTaxonomyParsing();

    $field_storage = $this->createMock(FieldStorageConfigInterface::class);
    // A loaded field storage hands back a simplified value => label map, not
    // the value/label pairs the config YAML is written as.
    $field_storage->method('getSetting')->with('allowed_values')->willReturn([
      'year_only' => 'Year',
      'decade' => 'Decade',
    ]);
    $this->fieldStorage->method('load')->with('node.field_date_format')->willReturn($field_storage);

    $result = $this->resourceImport->prepareResourceData([
      'title' => 'Config Driven',
      'date format' => 'Decade',
    ]);

    $this->assertSame('decade', $result['date_format']);
  }

  /**
   * An unknown Date Format value is rejected.
   *
   * @covers ::prepareResourceData
   */
  public function testPrepareResourceDataRejectsUnknownDateFormat() {
    $this->passThroughTaxonomyParsing();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Date Format');

    $this->resourceImport->prepareResourceData([
      'title' => 'Bad Format',
      'date format' => 'fortnightly',
    ]);
  }

  /**
   * A non-http External Source is rejected before it reaches the link field.
   *
   * @covers ::prepareResourceData
   */
  public function testPrepareResourceDataRejectsNonHttpExternalSource() {
    $this->passThroughTaxonomyParsing();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('External Source');

    $this->resourceImport->prepareResourceData([
      'title' => 'Bad Link',
      'external source' => 'javascript:alert(1)',
    ]);
  }

  /**
   * A bare hostname with no scheme is rejected.
   *
   * @covers ::prepareResourceData
   */
  public function testPrepareResourceDataRejectsSchemelessExternalSource() {
    $this->passThroughTaxonomyParsing();

    $this->expectException(\InvalidArgumentException::class);

    $this->resourceImport->prepareResourceData([
      'title' => 'Bare Host',
      'external source' => 'example.com/report.pdf',
    ]);
  }

  /**
   * Boolean columns accept the spellings editors actually type.
   *
   * @covers ::prepareResourceData
   *
   * @dataProvider booleanValueProvider
   */
  public function testPrepareResourceDataCoercesBooleans($value, $expected) {
    $this->passThroughTaxonomyParsing();

    $result = $this->resourceImport->prepareResourceData([
      'title' => 'Boolish',
      'cas login required' => $value,
    ]);

    $this->assertSame($expected, $result['login_required']);
  }

  /**
   * Accepted boolean spellings and the value each maps to.
   */
  public static function booleanValueProvider(): array {
    return [
      ['Yes', TRUE],
      ['yes', TRUE],
      ['TRUE', TRUE],
      ['1', TRUE],
      ['On', TRUE],
      ['No', FALSE],
      ['false', FALSE],
      ['0', FALSE],
      ['Off', FALSE],
      ['', FALSE],
    ];
  }

  /**
   * An unrecognised boolean value is rejected rather than treated as FALSE.
   *
   * @covers ::prepareResourceData
   */
  public function testPrepareResourceDataRejectsUnknownBoolean() {
    $this->passThroughTaxonomyParsing();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('CAS Login Required');

    $this->resourceImport->prepareResourceData([
      'title' => 'Maybe',
      'cas login required' => 'maybe',
    ]);
  }

  /**
   * An existing resource is matched by exact title within the bundle.
   *
   * @covers ::findExistingResource
   */
  public function testFindExistingResourceReturnsMatch() {
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('getQuery')->willReturn($this->mockQuery([7]));
    $this->nodeStorage->method('load')->with(7)->willReturn($node);

    $this->assertSame($node, $this->resourceImport->findExistingResource('Annual Report'));
  }

  /**
   * No match returns NULL rather than loading anything.
   *
   * @covers ::findExistingResource
   */
  public function testFindExistingResourceReturnsNullWhenAbsent() {
    $this->nodeStorage->method('getQuery')->willReturn($this->mockQuery([]));
    $this->nodeStorage->expects($this->never())->method('load');

    $this->assertNull($this->resourceImport->findExistingResource('Nothing'));
  }

  /**
   * Prepared data is mapped onto the resource bundle's real field names.
   *
   * @covers ::createResourceNode
   */
  public function testCreateResourceNodeMapsFields() {
    $this->taxonomyResolver->method('resolveTerms')->willReturnMap([
      [['Reports'], 'resource_category', [11]],
      [['Alumni'], 'audience', [12]],
      [['tag1'], 'tags', [13]],
      [['Custom A'], 'custom_vocab', [14]],
    ]);

    $captured = NULL;
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('create')
      ->willReturnCallback(function ($values) use (&$captured, $node) {
        $captured = $values;
        return $node;
      });

    $this->resourceImport->createResourceNode([
      'title' => 'Annual Report',
      'description' => 'A yearly summary.',
      'category' => ['Reports'],
      'audience' => ['Alumni'],
      'custom_vocab' => ['Custom A'],
      'tags' => ['tag1'],
      'publish_date' => '2026-03-04',
      'date_format' => 'month_year',
      'teaser_title' => 'Report',
      'teaser_text' => 'Short teaser.',
      'external_source' => 'https://example.com/report.pdf',
      'login_required' => TRUE,
      'sticky' => TRUE,
    ]);

    $this->assertSame('resource', $captured['type']);
    $this->assertSame('Annual Report', $captured['title']);
    $this->assertSame([11], $captured['field_category']);
    $this->assertSame([12], $captured['field_audience']);
    $this->assertSame([13], $captured['field_tags']);
    $this->assertSame([14], $captured['field_custom_vocab']);
    $this->assertSame('2026-03-04', $captured['field_publish_date']);
    $this->assertSame('month_year', $captured['field_date_format']);
    $this->assertSame('Report', $captured['field_teaser_title']);
    $this->assertSame(1, $captured['field_login_required']);
    $this->assertSame(1, $captured['sticky']);
    $this->assertSame(42, $captured['uid']);

    // The resource bundle is under the editorial workflow, so publication is
    // owned by moderation_state. Setting 'status' instead is silently
    // overridden, which is why it must not be used here.
    $this->assertSame('draft', $captured['moderation_state']);
    $this->assertArrayNotHasKey('status', $captured);

    // Long-text fields must carry an explicit format, or Drupal falls back to
    // the plain-text fallback filter and escapes the editor's markup. The
    // format also has to be one the field allows, or the widget renders
    // disabled on the node form.
    $this->assertSame(
      ['value' => 'A yearly summary.', 'format' => 'restricted_html'],
      $captured['field_content_description']
    );
    $this->assertSame(
      ['value' => 'Short teaser.', 'format' => 'heading_html'],
      $captured['field_teaser_text']
    );

    // A link field stores a URI, not a bare string.
    $this->assertSame(
      ['uri' => 'https://example.com/report.pdf'],
      $captured['field_external_source']
    );
  }

  /**
   * Empty optional values are omitted rather than written as empty fields.
   *
   * @covers ::createResourceNode
   */
  public function testCreateResourceNodeOmitsEmptyOptionalFields() {
    $this->taxonomyResolver->method('resolveTerms')->willReturn([]);

    $captured = NULL;
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('create')
      ->willReturnCallback(function ($values) use (&$captured, $node) {
        $captured = $values;
        return $node;
      });

    $this->resourceImport->createResourceNode([
      'title' => 'Bare Resource',
      'description' => '',
      'category' => [],
      'audience' => [],
      'custom_vocab' => [],
      'tags' => [],
      'publish_date' => NULL,
      'date_format' => NULL,
      'teaser_title' => '',
      'teaser_text' => '',
      'external_source' => '',
      'login_required' => FALSE,
      'sticky' => FALSE,
    ]);

    $this->assertArrayNotHasKey('field_publish_date', $captured);
    $this->assertArrayNotHasKey('field_date_format', $captured);
    $this->assertArrayNotHasKey('field_external_source', $captured);
    $this->assertArrayNotHasKey('field_content_description', $captured);
    $this->assertArrayNotHasKey('field_teaser_text', $captured);
    $this->assertSame('Bare Resource', $captured['title']);
  }

  /**
   * A failed save is logged and rethrown so the row error is reportable.
   *
   * @covers ::createResourceNode
   */
  public function testCreateResourceNodeRethrowsSaveFailure() {
    $this->taxonomyResolver->method('resolveTerms')->willReturn([]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('save')->willThrowException(new \Exception('Database went away'));
    $this->nodeStorage->method('create')->willReturn($node);

    $this->loggerChannel->expects($this->once())->method('error');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Database went away');

    $this->resourceImport->createResourceNode(['title' => 'Doomed'] + $this->emptyData());
  }

  /**
   * Import counts creations and flags rows that still need Resource Media.
   *
   * @covers ::processImport
   */
  public function testProcessImportFlagsRowsNeedingMedia() {
    $this->passThroughTaxonomyParsing();
    $this->taxonomyResolver->method('resolveTerms')->willReturn([]);

    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('create')->willReturn($node);
    $this->nodeStorage->method('getQuery')->willReturn($this->mockQuery([]));

    $result = $this->resourceImport->processImport([
      ['title' => 'Has Source', 'external source' => 'https://example.com/a.pdf'],
      ['title' => 'Needs Media'],
    ], FALSE);

    $this->assertSame(2, $result['created']);
    $this->assertSame(0, $result['skipped']);
    $this->assertSame([], $result['errors']);
    $this->assertSame(['Needs Media'], $result['needs_media']);
  }

  /**
   * Duplicate titles are skipped when the option is on.
   *
   * @covers ::processImport
   */
  public function testProcessImportSkipsDuplicateTitles() {
    $this->passThroughTaxonomyParsing();
    $this->taxonomyResolver->method('resolveTerms')->willReturn([]);

    $existing = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('getQuery')->willReturn($this->mockQuery([9]));
    $this->nodeStorage->method('load')->willReturn($existing);
    $this->nodeStorage->expects($this->never())->method('create');

    $result = $this->resourceImport->processImport([
      ['title' => 'Already Here'],
    ], TRUE);

    $this->assertSame(0, $result['created']);
    $this->assertSame(1, $result['skipped']);
  }

  /**
   * A bad cell fails only its own row and reports the real CSV line number.
   *
   * @covers ::processImport
   */
  public function testProcessImportReportsRowErrorsWithoutStoppingTheRun() {
    $this->passThroughTaxonomyParsing();
    $this->taxonomyResolver->method('resolveTerms')->willReturn([]);

    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('create')->willReturn($node);
    $this->nodeStorage->method('getQuery')->willReturn($this->mockQuery([]));

    $result = $this->resourceImport->processImport([
      ['title' => 'Good', '_row_number' => 2],
      ['title' => 'Bad', 'resource publication date' => 'whenever', '_row_number' => 7],
      ['title' => 'Also Good', '_row_number' => 8],
    ], FALSE);

    $this->assertSame(2, $result['created']);
    $this->assertCount(1, $result['errors']);
    $this->assertStringContainsString('Row 7', (string) $result['errors'][0]);
  }

  /**
   * Preview creates nothing and separates duplicates from importable rows.
   *
   * @covers ::previewImport
   */
  public function testPreviewImportCreatesNothing() {
    $this->passThroughTaxonomyParsing();

    $existing = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('getQuery')->willReturnOnConsecutiveCalls(
      $this->mockQuery([]),
      $this->mockQuery([9])
    );
    $this->nodeStorage->method('load')->willReturn($existing);
    $this->nodeStorage->expects($this->never())->method('create');

    $result = $this->resourceImport->previewImport([
      ['title' => 'New One'],
      ['title' => 'Already Here'],
    ], TRUE);

    $this->assertCount(1, $result['valid_resources']);
    $this->assertSame('New One', $result['valid_resources'][0]['title']);
    $this->assertSame(['Already Here'], $result['duplicates']);
    $this->assertSame(2, $result['total']);
  }

  /**
   * Preview surfaces bad cells before anything is saved.
   *
   * @covers ::previewImport
   */
  public function testPreviewImportReportsRowErrors() {
    $this->passThroughTaxonomyParsing();
    $this->nodeStorage->method('getQuery')->willReturn($this->mockQuery([]));

    $result = $this->resourceImport->previewImport([
      ['title' => 'Fine'],
      ['title' => 'Broken', 'external source' => 'not a url', '_row_number' => 3],
    ], FALSE);

    $this->assertCount(1, $result['valid_resources']);
    $this->assertCount(1, $result['errors']);
    $this->assertStringContainsString('Row 3', (string) $result['errors'][0]);
  }

  /**
   * Empty prepared values, for tests that only care about one key.
   */
  protected function emptyData(): array {
    return [
      'description' => '',
      'category' => [],
      'audience' => [],
      'custom_vocab' => [],
      'tags' => [],
      'publish_date' => NULL,
      'date_format' => NULL,
      'teaser_title' => '',
      'teaser_text' => '',
      'external_source' => '',
      'login_required' => FALSE,
      'sticky' => FALSE,
    ];
  }

}
