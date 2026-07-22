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

}
