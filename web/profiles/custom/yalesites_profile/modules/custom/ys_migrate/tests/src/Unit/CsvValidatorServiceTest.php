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
   * Formats one CSV line, quoting cells that hold tabs or line breaks.
   */
  protected function csvLine(array $cells): string {
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $cells, ',', '"', '');
    rewind($handle);
    $line = stream_get_contents($handle);
    fclose($handle);
    return $line;
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
   * The URL and Published columns a content export writes are not flagged.
   *
   * @covers ::getUnknownResourceColumns
   */
  public function testGetUnknownResourceColumnsAcceptsExportOnlyColumns() {
    $path = $this->createCsvFile("Title,URL,Published\nAnnual Report,/resource/annual-report,Yes\n");

    $result = $this->csvValidator->validateResourceCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertSame([], $this->csvValidator->getUnknownResourceColumns($result['headers']));
  }

  /**
   * The apostrophe an export puts in front of formula-like cells is removed.
   *
   * It is removed only when the character after it is one the export guards,
   * so a value that merely starts with an apostrophe imports as written.
   *
   * @param string $cell
   *   The cell as it appears in the file.
   * @param string $expected
   *   The value the importer should read.
   *
   * @dataProvider formulaGuardProvider
   * @covers ::validateResourceCsvStructure
   * @covers ::removeFormulaGuard
   */
  public function testValidateResourceCsvStructureRemovesFormulaGuard(string $cell, string $expected) {
    // Tags shows the guard is removed from every column, not just Title.
    $path = $this->createCsvFile("Title,Tags\n" . $this->csvLine([$cell, $cell]));

    $result = $this->csvValidator->validateResourceCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertSame($expected, $result['data'][0]['title']);
    $this->assertSame($expected, $result['data'][0]['tags']);
  }

  /**
   * Provides file cells and the value the resource import should read.
   *
   * @return array
   *   Cases: [cell, expected].
   */
  public static function formulaGuardProvider(): array {
    return [
      'guarded dash' => ["'-DOMINO TEST c90 formula", '-DOMINO TEST c90 formula'],
      'guarded equals' => ["'=1+1", '=1+1'],
      'guarded plus' => ["'+1 rating", '+1 rating'],
      'guarded at' => ["'@yale, Research", '@yale, Research'],
      'guarded leading space then formula' => ["' =1+1", ' =1+1'],
      'guarded tab' => ["'\tIndented", "\tIndented"],
      'apostrophe before ordinary text kept' => ["'Tis the season", "'Tis the season"],
      'lone apostrophe kept' => ["'", "'"],
      'doubled apostrophe kept' => ["''-Draft", "''-Draft"],
      'apostrophe after a space kept' => [" '-Draft", " '-Draft"],
      'unguarded formula untouched' => ['-Already plain', '-Already plain'],
    ];
  }

  /**
   * The required-Title check runs on the value left after the guard is gone.
   *
   * @covers ::validateResourceCsvStructure
   */
  public function testValidateResourceCsvStructureChecksTitleWithoutGuard() {
    $path = $this->createCsvFile("Title,Tags\n" . $this->csvLine(["'\t", 'tag1']));

    $result = $this->csvValidator->validateResourceCsvStructure($path);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('Row 2: Title is required.', (string) $result['message']);
  }

  /**
   * The profile import reads a leading apostrophe as written.
   *
   * A profile export cannot be imported as profiles, so there is no export
   * apostrophe to remove.
   *
   * @covers ::validateCsvStructure
   */
  public function testValidateCsvStructureKeepsLeadingApostrophe() {
    $path = $this->createCsvFile("Display Name\n'-Jane Doe\n");

    $result = $this->csvValidator->validateCsvStructure($path);

    $this->assertTrue($result['valid']);
    $this->assertSame("'-Jane Doe", $result['data'][0]['display name']);
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

}
