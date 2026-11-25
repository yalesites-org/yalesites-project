<?php

namespace Drupal\Tests\ys_migrate\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_content_export\ContentExportBuilder;
use Drupal\ys_migrate\Service\CsvValidatorService;
use Drupal\ys_migrate\Service\ResourceImportService;
use Drupal\ys_migrate\Service\TaxonomyResolverService;

/**
 * A Manage Resources export imported back through the Resource CSV import.
 *
 * Files are built with ys_content_export's own ContentExportBuilder, so the
 * importer is tested against what the export really writes rather than a
 * hand-written copy of it. CsvValidatorService::removeFormulaGuard() mirrors
 * ContentExportBuilder::sanitizeCell(), and a change to one without the other
 * fails here. Only the class is needed; the module does not have to be
 * enabled.
 *
 * @group ys_migrate
 * @group yalesites
 */
class ResourceExportRoundTripTest extends UnitTestCase {

  /**
   * The CSV validator under test.
   *
   * @var \Drupal\ys_migrate\Service\CsvValidatorService
   */
  protected $csvValidator;

  /**
   * The mocked node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $nodeStorage;

  /**
   * The resource import service under test.
   *
   * @var \Drupal\ys_migrate\Service\ResourceImportService
   */
  protected $resourceImport;

  /**
   * Paths of temporary CSV files created during a test, for cleanup.
   *
   * @var string[]
   */
  protected $tempFiles = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->csvValidator = new CsvValidatorService();
    $this->csvValidator->setStringTranslation($this->getStringTranslationStub());

    $taxonomy_resolver = $this->createMock(TaxonomyResolverService::class);
    $taxonomy_resolver->method('parseCommaSeparatedValues')
      ->willReturnCallback(fn ($value) => $value === '' ? [] : array_map('trim', explode(',', $value)));

    // Config storages load nothing, so the importer uses its documented
    // fallbacks for text formats and the Teaser Text limit.
    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $config_storage = $this->createMock(EntityStorageInterface::class);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->willReturnCallback(fn ($entity_type) => $entity_type === 'node' ? $this->nodeStorage : $config_storage);

    $this->resourceImport = new ResourceImportService(
      $this->createMock(AccountInterface::class),
      $taxonomy_resolver,
      $entity_type_manager,
      $this->createMock(LoggerChannelFactoryInterface::class)
    );
    $this->resourceImport->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->tempFiles as $path) {
      if (file_exists($path)) {
        unlink($path);
      }
    }
    parent::tearDown();
  }

  /**
   * Every column of a resource export is recognised by the importer.
   *
   * @covers \Drupal\ys_migrate\Service\CsvValidatorService::getUnknownResourceColumns
   */
  public function testExportedResourceColumnsAreAllRecognised() {
    $path = $this->exportFile([
      [
        'title' => 'Annual Report',
        'field_publish_date' => '2026-03-04',
        'url' => '/resource/annual-report',
        'published' => 'Yes',
        'cas_protected' => 'No',
        'field_tags' => 'Research',
      ],
    ]);

    $result = $this->csvValidator->validateResourceCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertSame([], $this->csvValidator->getUnknownResourceColumns($result['headers']));
  }

  /**
   * A value comes back from export and import exactly as it went in.
   *
   * @param string $value
   *   The value stored on the resource.
   *
   * @dataProvider roundTripProvider
   * @covers \Drupal\ys_migrate\Service\CsvValidatorService::validateResourceCsvStructure
   */
  public function testExportedValueImportsUnchanged(string $value) {
    // A taxonomy column too, since the export guards every cell.
    $path = $this->exportFile([['title' => $value, 'field_tags' => $value]]);

    $result = $this->csvValidator->validateResourceCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertSame($value, $result['data'][0]['title']);
    $this->assertSame($value, $result['data'][0]['tags']);
  }

  /**
   * Provides stored values, guarded and unguarded by the export.
   *
   * @return array
   *   Cases: [value].
   */
  public static function roundTripProvider(): array {
    return [
      'dash' => ['-DOMINO TEST c90 formula'],
      'equals' => ['=1+1'],
      'plus' => ['+1'],
      'at' => ['@SUM(A1)'],
      'leading space then formula' => [' =1+1'],
      'tab' => ["\tIndented"],
      'apostrophe before ordinary text' => ["'Tis the season"],
      'ordinary text' => ['Annual Report'],
    ];
  }

  /**
   * Skip duplicates finds a resource whose title the export guarded.
   *
   * The reported bug: the title came back with the export's apostrophe, so it
   * no longer matched and a second copy was created.
   *
   * @covers \Drupal\ys_migrate\Service\ResourceImportService::previewImport
   * @covers \Drupal\ys_migrate\Service\ResourceImportService::processImport
   */
  public function testSkipDuplicatesMatchesAnExportedTitle() {
    $stored_title = '-DOMINO TEST c90 formula';
    $path = $this->exportFile([['title' => $stored_title, 'published' => 'Yes']]);
    $data = $this->csvValidator->validateResourceCsvStructure($path)['data'];

    $this->nodeStorage->method('getQuery')
      ->willReturnCallback(fn () => $this->titleQuery($stored_title));
    $this->nodeStorage->method('load')->willReturn($this->createMock(NodeInterface::class));
    $this->nodeStorage->expects($this->never())->method('create');

    $preview = $this->resourceImport->previewImport($data, TRUE);
    $this->assertSame([$stored_title], $preview['duplicates']);
    $this->assertSame([], $preview['valid_resources']);

    $result = $this->resourceImport->processImport($data, TRUE);
    $this->assertSame(0, $result['created']);
    $this->assertSame(1, $result['skipped']);
  }

  /**
   * Writes a resource export the way ys_content_export does.
   *
   * Every cell goes through sanitizeCell() and every line through writeRow(),
   * under the export's real header row and with its trailing summary row.
   *
   * @param array $rows
   *   Rows keyed by ContentExportBuilder column key; missing keys are empty.
   *
   * @return string
   *   The path of the temporary file.
   */
  protected function exportFile(array $rows): string {
    $columns = ContentExportBuilder::getColumns('resource');
    $path = tempnam(sys_get_temp_dir(), 'ys_migrate_round_trip_');
    $this->tempFiles[] = $path;

    $handle = fopen($path, 'w');
    ContentExportBuilder::writeRow($handle, array_values($columns));
    foreach ($rows as $row) {
      ContentExportBuilder::writeRow($handle, array_map(
        fn ($key) => ContentExportBuilder::sanitizeCell($row[$key] ?? ''),
        array_keys($columns)
      ));
    }
    ContentExportBuilder::writeRow($handle, [sprintf('Export complete: %1$d of %1$d rows exported (0 failed, 0 skipped)', count($rows))]);
    fclose($handle);

    return $path;
  }

  /**
   * Mocks a node query that matches only one title, exactly as stored.
   *
   * @param string $stored_title
   *   The title of the resource that already exists.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The mocked query.
   */
  protected function titleQuery(string $stored_title): QueryInterface {
    $matched = FALSE;
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')
      ->willReturnCallback(function ($field, $value = NULL) use ($query, $stored_title, &$matched) {
        if ($field === 'title') {
          $matched = $value === $stored_title;
        }
        return $query;
      });
    $query->method('range')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    // A closure rather than fn(), which would capture $matched by value.
    $query->method('execute')->willReturnCallback(function () use (&$matched) {
      return $matched ? [9] : [];
    });
    return $query;
  }

}
