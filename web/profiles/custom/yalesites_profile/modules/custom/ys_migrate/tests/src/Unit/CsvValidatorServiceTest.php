<?php

namespace Drupal\Tests\ys_migrate\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_migrate\Service\CsvValidatorService;

/**
 * Unit tests for CsvValidatorService.
 *
 * @coversDefaultClass \Drupal\ys_migrate\Service\CsvValidatorService
 * @group ys_migrate
 * @group yalesites
 */
class CsvValidatorServiceTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\ys_migrate\Service\CsvValidatorService
   */
  protected $csvValidator;

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
   * Writes $content to a temporary file and returns its path.
   */
  protected function createCsvFile(string $content): string {
    $path = tempnam(sys_get_temp_dir(), 'ys_migrate_csv_test_');
    file_put_contents($path, $content);
    $this->tempFiles[] = $path;
    return $path;
  }

  /**
   * A well-formed CSV with multiple rows and columns validates successfully.
   *
   * @covers ::validateCsvStructure
   */
  public function testValidateCsvStructureWithValidMultiRowCsv() {
    $path = $this->createCsvFile(
      "Display Name,First Name,Last Name,Email,Teaser Text\n" .
      "Jane Doe,Jane,Doe,jane@example.com,Short bio text.\n" .
      "John Smith,John,Smith,john@example.com,Another short bio.\n"
    );

    $result = $this->csvValidator->validateCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertEquals('CSV file is valid. Found 2 profiles.', (string) $result['message']);
    $this->assertCount(2, $result['data']);
    $this->assertEquals([
      'display name' => 'Jane Doe',
      'first name' => 'Jane',
      'last name' => 'Doe',
      'email' => 'jane@example.com',
      'teaser text' => 'Short bio text.',
      '_row_number' => 2,
    ], $result['data'][0]);
    // The second data row is CSV line 3 (header is line 1).
    $this->assertSame(3, $result['data'][1]['_row_number']);
    $this->assertEquals([
      'display name' => 'Display Name',
      'first name' => 'First Name',
      'last name' => 'Last Name',
      'email' => 'Email',
      'teaser text' => 'Teaser Text',
    ], $result['headers']);
  }

  /**
   * A CSV containing only the required Display Name column is valid.
   *
   * @covers ::validateCsvStructure
   */
  public function testValidateCsvStructureWithOnlyDisplayNameColumn() {
    $path = $this->createCsvFile("Display Name\nJane Doe\n");

    $result = $this->csvValidator->validateCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertEquals([['display name' => 'Jane Doe', '_row_number' => 2]], $result['data']);
  }

  /**
   * A CSV missing the required Display Name column is rejected.
   *
   * @covers ::validateCsvStructure
   */
  public function testValidateCsvStructureWithoutDisplayNameColumn() {
    $path = $this->createCsvFile("Name,Email\nJane,jane@example.com\n");

    $result = $this->csvValidator->validateCsvStructure($path);

    $this->assertFalse($result['valid']);
    $this->assertEquals('The CSV file must contain a "Display Name" column.', (string) $result['message']);
    $this->assertSame([], $result['data']);
  }

  /**
   * A completely empty file is rejected as having no header row.
   *
   * @covers ::validateCsvStructure
   */
  public function testValidateCsvStructureWithEmptyFile() {
    $path = $this->createCsvFile('');

    $result = $this->csvValidator->validateCsvStructure($path);

    $this->assertFalse($result['valid']);
    $this->assertEquals('The CSV file appears to be empty or invalid.', (string) $result['message']);
  }

  /**
   * A nonexistent file path is rejected gracefully instead of erroring.
   *
   * Fopen() emits a PHP warning for a missing file; it is suppressed here
   * (matching the service's own lack of suppression) purely so PHPUnit's
   * warning-to-exception conversion doesn't mask the method's actual,
   * graceful "unable to open" return value.
   *
   * @covers ::validateCsvStructure
   */
  public function testValidateCsvStructureWithUnopenableFile() {
    $result = @$this->csvValidator->validateCsvStructure('/nonexistent/path/does-not-exist.csv');

    $this->assertFalse($result['valid']);
    $this->assertEquals('Unable to open the CSV file.', (string) $result['message']);
  }

  /**
   * A row missing the required Display Name value invalidates the file.
   *
   * A single bad row invalidates the entire CSV (not just that row) -- the
   * method returns no data at all when any row fails validation.
   *
   * @covers ::validateCsvStructure
   */
  public function testValidateCsvStructureWithMissingDisplayNameValue() {
    $path = $this->createCsvFile("Display Name,Email\n,jane@example.com\n");

    $result = $this->csvValidator->validateCsvStructure($path);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('Row 2: Display Name is required.', (string) $result['message']);
    $this->assertSame([], $result['data']);
  }

  /**
   * A row with a malformed email address invalidates the file.
   *
   * @covers ::validateCsvStructure
   */
  public function testValidateCsvStructureWithInvalidEmail() {
    $path = $this->createCsvFile("Display Name,Email\nJane Doe,not-an-email\n");

    $result = $this->csvValidator->validateCsvStructure($path);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('Row 2: Invalid email format: not-an-email.', (string) $result['message']);
  }

  /**
   * A row with Teaser Text over 150 characters invalidates the file.
   *
   * @covers ::validateCsvStructure
   */
  public function testValidateCsvStructureWithTeaserTextTooLong() {
    $long_text = str_repeat('x', 151);
    $path = $this->createCsvFile("Display Name,Teaser Text\nJane Doe,{$long_text}\n");

    $result = $this->csvValidator->validateCsvStructure($path);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('Row 2: Teaser Text exceeds 150 characters.', (string) $result['message']);
  }

  /**
   * A row with fewer columns than the header row invalidates the file.
   *
   * @covers ::validateCsvStructure
   */
  public function testValidateCsvStructureWithIncorrectColumnCount() {
    $path = $this->createCsvFile("Display Name,Email,Department\nJane Doe,jane@example.com\n");

    $result = $this->csvValidator->validateCsvStructure($path);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('Row 2: Incorrect number of columns (expected 3, got 2).', (string) $result['message']);
  }

  /**
   * A blank line between data rows is silently skipped, not an error.
   *
   * @covers ::validateCsvStructure
   */
  public function testValidateCsvStructureSkipsBlankRows() {
    $path = $this->createCsvFile(
      "Display Name,Email\n" .
      "Jane Doe,jane@example.com\n" .
      "\n" .
      "John Smith,john@example.com\n"
    );

    $result = $this->csvValidator->validateCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertCount(2, $result['data']);
    // The blank line 3 is skipped, so John is CSV line 4 -- the reported row
    // number must reflect the true line, not the compacted array offset (which
    // would give 3). This is the row-number-drift regression guard.
    $this->assertSame(2, $result['data'][0]['_row_number']);
    $this->assertSame(4, $result['data'][1]['_row_number']);
  }

  /**
   * Headers are normalized (trimmed, lowercased) while originals are kept.
   *
   * @covers ::validateCsvStructure
   */
  public function testValidateCsvStructureNormalizesHeaders() {
    $path = $this->createCsvFile(" Display Name ,EMAIL\nJane Doe,jane@example.com\n");

    $result = $this->csvValidator->validateCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertEquals([
      'display name' => ' Display Name ',
      'email' => 'EMAIL',
    ], $result['headers']);
    $this->assertEquals([
      'display name' => 'Jane Doe',
      'email' => 'jane@example.com',
      '_row_number' => 2,
    ], $result['data'][0]);
  }

  /**
   * Multiple errors on the same row are joined with a semicolon separator.
   *
   * @covers ::validateCsvStructure
   */
  public function testValidateCsvStructureJoinsMultipleRowErrors() {
    $path = $this->createCsvFile("Display Name,Email\n,bad-email\n");

    $result = $this->csvValidator->validateCsvStructure($path);

    $message = (string) $result['message'];
    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('Row 2: Display Name is required.', $message);
    $this->assertStringContainsString('Row 2: Invalid email format: bad-email.', $message);
    $this->assertStringContainsString('; ', $message);
  }

  /**
   * GetExpectedColumns() returns the full documented column list.
   *
   * @covers ::getExpectedColumns
   */
  public function testGetExpectedColumns() {
    $columns = $this->csvValidator->getExpectedColumns();

    $this->assertArrayHasKey('display name', $columns);
    $this->assertEquals('Display Name', $columns['display name']);
    $this->assertEquals('Custom Vocabulary', $columns['custom vocabulary']);
    $this->assertCount(17, $columns);
  }

  /**
   * A well-formed resource CSV validates and reports the resource count.
   *
   * @covers ::validateResourceCsvStructure
   */
  public function testValidateResourceCsvStructureWithValidCsv() {
    $path = $this->createCsvFile(
      "Title,Description,External Source\n" .
      "Annual Report,A yearly summary.,https://example.com/a.pdf\n" .
      "Style Guide,How we write.,\n"
    );

    $result = $this->csvValidator->validateResourceCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertEquals('CSV file is valid. Found 2 resources.', (string) $result['message']);
    $this->assertCount(2, $result['data']);
    $this->assertEquals('Annual Report', $result['data'][0]['title']);
  }

  /**
   * A resource CSV without a Title column is rejected.
   *
   * @covers ::validateResourceCsvStructure
   */
  public function testValidateResourceCsvStructureRequiresTitleColumn() {
    $path = $this->createCsvFile("Description,Tags\nSomething,tag1\n");

    $result = $this->csvValidator->validateResourceCsvStructure($path);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('must contain a "Title" column', (string) $result['message']);
  }

  /**
   * A resource row with an empty Title is reported by line number.
   *
   * @covers ::validateResourceCsvStructure
   */
  public function testValidateResourceCsvStructureRequiresTitleValue() {
    $path = $this->createCsvFile("Title,Description\nGood,fine\n   ,also fine\n");

    $result = $this->csvValidator->validateResourceCsvStructure($path);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('Row 3: Title is required.', (string) $result['message']);
  }

  /**
   * Cell-level problems are left to the import service, not the validator.
   *
   * A bad date or URL must not reject the whole file; it surfaces as a row
   * error in the preview so the editor can see and fix just that row.
   *
   * @covers ::validateResourceCsvStructure
   */
  public function testValidateResourceCsvStructureIgnoresCellSemantics() {
    $path = $this->createCsvFile(
      "Title,Resource Publication Date,External Source\n" .
      "Odd One,whenever,not a url\n"
    );

    $result = $this->csvValidator->validateResourceCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertCount(1, $result['data']);
  }

  /**
   * The true CSV line number is threaded onto each resource row.
   *
   * @covers ::validateResourceCsvStructure
   */
  public function testValidateResourceCsvStructureThreadsRowNumbers() {
    $path = $this->createCsvFile("Title\nFirst\n\nSecond\n");

    $result = $this->csvValidator->validateResourceCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertSame(2, $result['data'][0]['_row_number']);
    // The blank line is skipped, so the second resource is really line 4.
    $this->assertSame(4, $result['data'][1]['_row_number']);
  }

  /**
   * Alternative header spellings are folded onto their canonical column.
   *
   * "Custom Vocabulary" is the ticket's wording; "CAS Protected" is the header
   * ys_content_export writes, so an export can be edited and imported back.
   *
   * @covers ::validateResourceCsvStructure
   */
  public function testValidateResourceCsvStructureFoldsHeaderAliases() {
    $path = $this->createCsvFile("Title,Custom Vocabulary,CAS Protected\nAliased,Vocab A,Yes\n");

    $result = $this->csvValidator->validateResourceCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertSame('Vocab A', $result['data'][0]['custom vocab']);
    $this->assertSame('Yes', $result['data'][0]['cas login required']);
    $this->assertSame([], $this->csvValidator->getUnknownResourceColumns($result['headers']));
  }

  /**
   * An Excel UTF-8 byte-order mark does not hide the first column's header.
   *
   * @covers ::validateResourceCsvStructure
   */
  public function testValidateResourceCsvStructureToleratesUtf8Bom() {
    $path = $this->createCsvFile("\xEF\xBB\xBFTitle,Description\nBom Survivor,fine\n");

    $result = $this->csvValidator->validateResourceCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertSame('Bom Survivor', $result['data'][0]['title']);
  }

  /**
   * An export's trailing summary row is skipped, not counted or rejected.
   *
   * @covers ::validateResourceCsvStructure
   */
  public function testValidateResourceCsvStructureSkipsExportSummaryRow() {
    $path = $this->createCsvFile(
      "\xEF\xBB\xBFTitle,Description,CAS Protected\n" .
      "Annual Report,A summary.,No\n" .
      "Style Guide,How we write.,Yes\n" .
      "\"Export complete: 2 of 2 rows exported (0 failed, 0 skipped)\"\n"
    );

    $result = $this->csvValidator->validateResourceCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertCount(2, $result['data']);
    $this->assertEquals('CSV file is valid. Found 2 resources.', (string) $result['message']);
  }

  /**
   * An export's failed-row placeholder is rejected with a clear message.
   *
   * @covers ::validateResourceCsvStructure
   */
  public function testValidateResourceCsvStructureRejectsExportPlaceholderRow() {
    $path = $this->createCsvFile(
      "Title,Description,CAS Protected\n" .
      "Annual Report,A summary.,No\n" .
      "Export failed for node 123,,\n"
    );

    $result = $this->csvValidator->validateResourceCsvStructure($path);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('Row 3: this row is a placeholder for content that failed to export. Remove it or export again.', (string) $result['message']);
    $this->assertSame([], $result['data']);
  }

  /**
   * Headers the importer will silently ignore are reported to the caller.
   *
   * @covers ::getUnknownResourceColumns
   */
  public function testGetUnknownResourceColumnsNamesIgnoredHeaders() {
    $path = $this->createCsvFile("Title,Tag,Editor Notes\nTypo'd,tag1,internal\n");

    $result = $this->csvValidator->validateResourceCsvStructure($path);
    $unknown = $this->csvValidator->getUnknownResourceColumns($result['headers']);

    $this->assertTrue($result['valid']);
    // "Tag" is a typo for "Tags"; without this warning it would be dropped.
    $this->assertContains('Tag', $unknown);
    $this->assertContains('Editor Notes', $unknown);
    $this->assertNotContains('Title', $unknown);
  }

  /**
   * GetExpectedResourceColumns() omits the fields a CSV cannot carry.
   *
   * @covers ::getExpectedResourceColumns
   */
  public function testGetExpectedResourceColumns() {
    $columns = $this->csvValidator->getExpectedResourceColumns();

    $this->assertEquals('Title', $columns['title']);
    $this->assertEquals('Resource Category', $columns['resource category']);
    $this->assertEquals('Pin to beginning of list', $columns['pin to beginning of list']);
    $this->assertEquals('Abstract', $columns['abstract']);
    $this->assertEquals('Citation', $columns['citation']);
    $this->assertEquals('Journal Publication Name', $columns['journal publication name']);
    $this->assertEquals('Journal Publication Issue', $columns['journal publication issue']);
    // Media cannot travel in a CSV cell, and Resource has no Affiliation field.
    $this->assertArrayNotHasKey('resource media', $columns);
    $this->assertArrayNotHasKey('teaser media', $columns);
    $this->assertArrayNotHasKey('affiliation', $columns);
    $this->assertCount(17, $columns);
  }

  /**
   * A well-formed post CSV validates and reports the post count.
   *
   * @covers ::validatePostCsvStructure
   */
  public function testValidatePostCsvStructureWithValidCsv() {
    $path = $this->createCsvFile(
      "Title,Publish Date,Body\n" .
      "Climate Study,2026-03-04,<p>Findings.</p>\n" .
      "Weekly Update,2026-03-05,\n"
    );

    $result = $this->csvValidator->validatePostCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertEquals('CSV file is valid. Found 2 posts.', (string) $result['message']);
    $this->assertCount(2, $result['data']);
    $this->assertSame('Climate Study', $result['data'][0]['title']);
    $this->assertSame('2026-03-04', $result['data'][0]['publish date']);
  }

  /**
   * A post CSV without a Title column is rejected.
   *
   * @covers ::validatePostCsvStructure
   */
  public function testValidatePostCsvStructureRequiresTitleColumn() {
    $path = $this->createCsvFile("Publish Date,Body\n2026-03-04,Words\n");

    $result = $this->csvValidator->validatePostCsvStructure($path);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('must contain a "Title" column', (string) $result['message']);
  }

  /**
   * A post CSV without a Publish Date column is rejected once, not per row.
   *
   * @covers ::validatePostCsvStructure
   */
  public function testValidatePostCsvStructureRequiresPublishDateColumn() {
    $path = $this->createCsvFile("Title,Body\nClimate Study,Words\nWeekly Update,More words\n");

    $result = $this->csvValidator->validatePostCsvStructure($path);

    $this->assertFalse($result['valid']);
    $this->assertEquals('The CSV file must contain a "Publish Date" column.', (string) $result['message']);
    $this->assertSame([], $result['data']);
  }

  /**
   * An empty Publish Date is left for the importer to report against its row.
   *
   * Rejecting the whole file here would block every other row over one gap.
   *
   * @covers ::validatePostCsvStructure
   */
  public function testValidatePostCsvStructureLeavesCellChecksToTheImporter() {
    $path = $this->createCsvFile("Title,Publish Date\nNo Date,\nHas Date,2026-03-04\n");

    $result = $this->csvValidator->validatePostCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertCount(2, $result['data']);
  }

  /**
   * A post row with an empty Title is reported by line number.
   *
   * @covers ::validatePostCsvStructure
   */
  public function testValidatePostCsvStructureRequiresTitleValue() {
    $path = $this->createCsvFile("Title,Publish Date\nFine,2026-03-04\n,2026-03-05\n");

    $result = $this->csvValidator->validatePostCsvStructure($path);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('Row 3: Title is required.', (string) $result['message']);
  }

  /**
   * A quoted Body spanning several lines stays one cell in one row.
   *
   * @covers ::validatePostCsvStructure
   */
  public function testValidatePostCsvStructureKeepsMultilineBodyInOneRow() {
    $path = $this->createCsvFile(
      "Title,Publish Date,Body\n" .
      "Climate Study,2026-03-04,\"First paragraph.\n\nSecond, with a comma.\"\n" .
      "Weekly Update,2026-03-05,Short.\n"
    );

    $result = $this->csvValidator->validatePostCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertCount(2, $result['data']);
    $this->assertSame("First paragraph.\n\nSecond, with a comma.", $result['data'][0]['body']);
    // Row numbers count records, matching the row a spreadsheet shows, not the
    // file's physical lines, which the multi-line cell above has stretched.
    $this->assertSame(3, $result['data'][1]['_row_number']);
  }

  /**
   * Post header aliases fold onto their canonical names.
   *
   * "CAS Protected" is the header ys_content_export writes; "Featured Image
   * ID" is how the import was first described.
   *
   * @covers ::validatePostCsvStructure
   */
  public function testValidatePostCsvStructureFoldsHeaderAliases() {
    $path = $this->createCsvFile(
      "Title,Publish Date,Custom Vocabulary,CAS Protected,Featured Image ID\n" .
      "Aliased,2026-03-04,Vocab A,Yes,123\n"
    );

    $result = $this->csvValidator->validatePostCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertSame('Vocab A', $result['data'][0]['custom vocab']);
    $this->assertSame('Yes', $result['data'][0]['cas login required']);
    $this->assertSame('123', $result['data'][0]['teaser media id']);
    $this->assertSame([], $this->csvValidator->getUnknownPostColumns($result['headers']));
  }

  /**
   * A Manage Posts export reads back in, its extra columns named as ignored.
   *
   * @covers ::validatePostCsvStructure
   * @covers ::getUnknownPostColumns
   */
  public function testValidatePostCsvStructureReadsManagePostsExport() {
    $path = $this->createCsvFile(
      "\xEF\xBB\xBFTitle,Publish Date,URL,Published,CAS Protected,Tags,Audience,Custom Vocab,Category\n" .
      "Climate Study,2026-03-04,/news/climate-study,Yes,No,Research,,,News\n" .
      "\"Export complete: 1 of 1 rows exported (0 failed, 0 skipped)\"\n"
    );

    $result = $this->csvValidator->validatePostCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertCount(1, $result['data']);
    $this->assertSame(['URL', 'Published'], $this->csvValidator->getUnknownPostColumns($result['headers']));
  }

  /**
   * GetExpectedPostColumns() lists the template's columns, in order.
   *
   * @covers ::getExpectedPostColumns
   */
  public function testGetExpectedPostColumns() {
    $this->assertSame([
      'Title',
      'Publish Date',
      'Author',
      'Body',
      'Category',
      'Tags',
      'Audience',
      'Custom Vocab',
      'Teaser Title',
      'Teaser Text',
      'Teaser Media ID',
      'External Source',
      'CAS Login Required',
      'URL Alias',
    ], array_values($this->csvValidator->getExpectedPostColumns()));
  }

}
