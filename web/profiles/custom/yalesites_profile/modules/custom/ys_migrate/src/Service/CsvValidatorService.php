<?php

namespace Drupal\ys_migrate\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for validating CSV files and structure.
 */
class CsvValidatorService {

  use StringTranslationTrait;

  /**
   * Expected CSV columns for profile import.
   */
  const EXPECTED_COLUMNS = [
    'display name' => 'Display Name',
    'first name' => 'First Name',
    'last name' => 'Last Name',
    'honorific prefix' => 'Honorific Prefix',
    'pronouns' => 'Pronouns',
    'position' => 'Position',
    'subtitle' => 'Subtitle',
    'department' => 'Department',
    'email' => 'Email',
    'telephone' => 'Telephone',
    'address' => 'Address',
    'teaser title' => 'Teaser Title',
    'teaser text' => 'Teaser Text',
    'affiliation' => 'Affiliation',
    'audience' => 'Audience',
    'tags' => 'Tags',
    'custom vocabulary' => 'Custom Vocabulary',
  ];

  /**
   * Expected CSV columns for resource import.
   *
   * Resource Media and Teaser Media are absent because a media reference
   * cannot travel in a CSV cell; Affiliation is absent because the Resource
   * content type has no such field.
   */
  const EXPECTED_RESOURCE_COLUMNS = [
    'title' => 'Title',
    'description' => 'Description',
    'abstract' => 'Abstract',
    'citation' => 'Citation',
    'journal publication name' => 'Journal Publication Name',
    'journal publication issue' => 'Journal Publication Issue',
    'resource category' => 'Resource Category',
    'audience' => 'Audience',
    'custom vocab' => 'Custom Vocab',
    'resource publication date' => 'Resource Publication Date',
    'date format' => 'Date Format',
    'tags' => 'Tags',
    'teaser title' => 'Teaser Title',
    'teaser text' => 'Teaser Text',
    'external source' => 'External Source',
    'cas login required' => 'CAS Login Required',
    'pin to beginning of list' => 'Pin to beginning of list',
  ];

  /**
   * Accepted alternative spellings for resource columns.
   *
   * Values are the canonical normalised header each alias maps to. "Custom
   * Vocabulary" is the ticket's wording for "Custom Vocab"; "CAS Protected" is
   * the header ys_content_export writes for "CAS Login Required".
   */
  const RESOURCE_COLUMN_ALIASES = [
    'custom vocabulary' => 'custom vocab',
    'cas protected' => 'cas login required',
  ];

  /**
   * Expected CSV columns for post import.
   *
   * Body becomes a Text block in the post's layout. Teaser Media is absent
   * because this import brings in no images; editors add them after import.
   * Publish Date and Category match the headers ys_content_export writes, so
   * an exported file can be filled in and imported.
   */
  const EXPECTED_POST_COLUMNS = [
    'title' => 'Title',
    'publish date' => 'Publish Date',
    'author' => 'Author',
    'body' => 'Body',
    'category' => 'Category',
    'tags' => 'Tags',
    'audience' => 'Audience',
    'custom vocab' => 'Custom Vocab',
    'teaser title' => 'Teaser Title',
    'teaser text' => 'Teaser Text',
    'external source' => 'External Source',
    'cas login required' => 'CAS Login Required',
    'url alias' => 'URL Alias',
  ];

  /**
   * Accepted alternative spellings for post columns.
   *
   * Values are the canonical normalised header each alias maps to. "CAS
   * Protected" is the header ys_content_export writes for "CAS Login
   * Required".
   */
  const POST_COLUMN_ALIASES = [
    'custom vocabulary' => 'custom vocab',
    'cas protected' => 'cas login required',
  ];

  /**
   * Expected CSV columns for event import.
   *
   * One row is one event with one date; repeat rules are added in the event
   * form after import. Location name and address both go in Location Details,
   * the one location field editors fill in. Teaser Media is absent because
   * this import brings in no images, and the Localist-owned fields (Yale
   * Location, Room, Event Type, Groups and the Localist ids) are absent
   * because only the Localist sync may set them. Start, End, Event Category
   * and the event-specific columns match the headers ys_content_export
   * writes, so an exported file can be filled in and imported.
   */
  const EXPECTED_EVENT_COLUMNS = [
    'title' => 'Title',
    'start' => 'Start',
    'end' => 'End',
    'all day' => 'All Day',
    'description' => 'Description',
    'location details' => 'Location Details',
    'event experience' => 'Event Experience',
    'event website' => 'Event Website',
    'event website text' => 'Event Website Text',
    'ticket cost' => 'Ticket Cost',
    'ticket registration url' => 'Ticket Registration URL',
    'stream url' => 'Stream URL',
    'event category' => 'Event Category',
    'tags' => 'Tags',
    'audience' => 'Audience',
    'custom vocab' => 'Custom Vocab',
    'teaser title' => 'Teaser Title',
    'teaser text' => 'Teaser Text',
    'cas login required' => 'CAS Login Required',
    'url alias' => 'URL Alias',
    'event id' => 'Event ID',
  ];

  /**
   * Accepted alternative spellings for event columns.
   *
   * Values are the canonical normalised header each alias maps to. The date
   * aliases are the ticket's wording; "CAS Protected" is the header
   * ys_content_export writes for "CAS Login Required".
   */
  const EVENT_COLUMN_ALIASES = [
    'start date' => 'start',
    'start date/time' => 'start',
    'end date' => 'end',
    'end date/time' => 'end',
    'location' => 'location details',
    'registration url' => 'ticket registration url',
    'category' => 'event category',
    'custom vocabulary' => 'custom vocab',
    'cas protected' => 'cas login required',
  ];

  /**
   * Prefix of the trailing summary row a content export appends.
   *
   * This and EXPORT_PLACEHOLDER_PREFIX mirror the strings that
   * ys_content_export's ContentExportController::writeCsv() writes. They are
   * duplicated because that module may not be enabled.
   */
  const EXPORT_SUMMARY_PREFIX = 'Export complete:';

  /**
   * Prefix of the placeholder row for a node that failed to export.
   */
  const EXPORT_PLACEHOLDER_PREFIX = 'Export failed for node ';

  /**
   * Validates the CSV file structure and content for a profile import.
   *
   * @param string $file_path
   *   The path to the CSV file.
   *
   * @return array
   *   Validation result with 'valid', 'message', 'data', and 'headers' keys.
   */
  public function validateCsvStructure($file_path) {
    $result = $this->parseCsv(
      $file_path,
      'display name',
      $this->t('The CSV file must contain a "Display Name" column.'),
      [$this, 'validateRow']
    );

    if ($result['valid']) {
      $result['message'] = $this->t('CSV file is valid. Found @count profiles.', ['@count' => count($result['data'])]);
    }

    return $result;
  }

  /**
   * Validates the CSV file structure and content for a resource import.
   *
   * Only structure and the required Title are checked here. Cell-level
   * problems (an unparseable date, a malformed URL) are reported per row by
   * ResourceImportService, so one typo does not reject the whole file and the
   * editor sees the problem in the preview.
   *
   * @param string $file_path
   *   The path to the CSV file.
   *
   * @return array
   *   Validation result with 'valid', 'message', 'data', and 'headers' keys.
   */
  public function validateResourceCsvStructure($file_path) {
    $result = $this->parseCsv(
      $file_path,
      'title',
      $this->t('The CSV file must contain a "Title" column.'),
      [$this, 'validateTitledRow'],
      self::RESOURCE_COLUMN_ALIASES
    );

    if ($result['valid']) {
      $result['message'] = $this->t('CSV file is valid. Found @count resources.', ['@count' => count($result['data'])]);
    }

    return $result;
  }

  /**
   * Validates the CSV file structure and content for a post import.
   *
   * Only structure, the required headers and the required Title are checked
   * here. Cell-level problems, an empty Publish Date included, are reported
   * per row by PostImportService, so one gap does not reject the whole file
   * and the editor sees the problem in the preview.
   *
   * @param string $file_path
   *   The path to the CSV file.
   *
   * @return array
   *   Validation result with 'valid', 'message', 'data', and 'headers' keys.
   */
  public function validatePostCsvStructure($file_path) {
    $result = $this->parseCsv(
      $file_path,
      'title',
      $this->t('The CSV file must contain a "Title" column.'),
      [$this, 'validateTitledRow'],
      self::POST_COLUMN_ALIASES
    );

    // Without the column every row would fail the same way, so say it once.
    if ($result['valid'] && !isset($result['headers']['publish date'])) {
      return [
        'valid' => FALSE,
        'message' => $this->t('The CSV file must contain a "Publish Date" column.'),
        'data' => [],
        'headers' => [],
      ];
    }

    if ($result['valid']) {
      $result['message'] = $this->t('CSV file is valid. Found @count posts.', ['@count' => count($result['data'])]);
    }

    return $result;
  }

  /**
   * Validates the CSV file structure and content for an event import.
   *
   * Only structure, the required headers and the required Title are checked
   * here. Cell-level problems, an empty or unreadable Start included, are
   * reported per row by EventImportService, so one gap does not reject the
   * whole file and the editor sees the problem in the preview.
   *
   * @param string $file_path
   *   The path to the CSV file.
   *
   * @return array
   *   Validation result with 'valid', 'message', 'data', and 'headers' keys.
   */
  public function validateEventCsvStructure($file_path) {
    $result = $this->parseCsv(
      $file_path,
      'title',
      $this->t('The CSV file must contain a "Title" column.'),
      [$this, 'validateTitledRow'],
      self::EVENT_COLUMN_ALIASES
    );

    // Without the column every row would fail the same way, so say it once.
    if ($result['valid'] && !isset($result['headers']['start'])) {
      return [
        'valid' => FALSE,
        'message' => $this->t('The CSV file must contain a "Start" column.'),
        'data' => [],
        'headers' => [],
      ];
    }

    if ($result['valid']) {
      $result['message'] = $this->t('CSV file is valid. Found @count events.', ['@count' => count($result['data'])]);
    }

    return $result;
  }

  /**
   * Reads a CSV file into normalised rows, applying per-row validation.
   *
   * @param string $file_path
   *   The path to the CSV file.
   * @param string $required_header
   *   The normalised header the file must contain.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $missing_header_message
   *   The message to return when that header is absent.
   * @param callable $row_validator
   *   Receives the normalised row and its line number, returns an array of
   *   error messages.
   * @param array $aliases
   *   Optional alternative header spellings, mapping the alias to the
   *   canonical header, so consumers only ever see canonical keys.
   *
   * @return array
   *   Validation result with 'valid', 'message', 'data', and 'headers' keys.
   *   On success 'message' is empty and the caller supplies its own.
   */
  protected function parseCsv($file_path, $required_header, $missing_header_message, callable $row_validator, array $aliases = []) {
    $handle = fopen($file_path, 'r');
    if (!$handle) {
      return [
        'valid' => FALSE,
        'message' => $this->t('Unable to open the CSV file.'),
        'data' => [],
        'headers' => [],
      ];
    }

    // Read the header row.
    $headers = fgetcsv($handle);
    if (!$headers) {
      fclose($handle);
      return [
        'valid' => FALSE,
        'message' => $this->t('The CSV file appears to be empty or invalid.'),
        'data' => [],
        'headers' => [],
      ];
    }

    // Normalize headers (remove whitespace, convert to lowercase) and fold any
    // accepted alias onto its canonical name.
    $normalized_headers = array_map(
      function ($header) use ($aliases) {
        return $aliases[$header] ?? $header;
      },
      $this->normalizeHeaders($headers)
    );

    // Check for required header.
    if (!in_array($required_header, $normalized_headers)) {
      fclose($handle);
      return [
        'valid' => FALSE,
        'message' => $missing_header_message,
        'data' => [],
        'headers' => [],
      ];
    }

    // Create a mapping from normalized headers to original headers.
    $header_mapping = array_combine($normalized_headers, $headers);

    $data = [];
    $row_number = 1;
    $errors = [];

    while (($row = fgetcsv($handle)) !== FALSE) {
      $row_number++;
      $row = array_map([$this, 'trimCell'], $row);

      // Skip empty rows.
      if (empty(array_filter($row))) {
        continue;
      }

      // Skip the one-cell summary row a content export appends, so an exported
      // file can be imported back.
      if (str_starts_with($row[0], self::EXPORT_SUMMARY_PREFIX) && count(array_filter($row)) === 1) {
        continue;
      }

      // A placeholder stands in for content that failed to export; importing
      // it would create a junk item.
      if (str_starts_with($row[0], self::EXPORT_PLACEHOLDER_PREFIX)) {
        $errors[] = $this->t('Row @row: this row is a placeholder for content that failed to export. Remove it or export again.', [
          '@row' => $row_number,
        ]);
        continue;
      }

      // Ensure we have the right number of columns.
      if (count($row) !== count($headers)) {
        $errors[] = $this->t('Row @row: Incorrect number of columns (expected @expected, got @actual).', [
          '@row' => $row_number,
          '@expected' => count($headers),
          '@actual' => count($row),
        ]);
        continue;
      }

      // Create a data array with normalized keys.
      $row_data = array_combine($normalized_headers, $row);

      // Validate the row data.
      $row_errors = $row_validator($row_data, $row_number);
      if (!empty($row_errors)) {
        $errors = array_merge($errors, $row_errors);
        continue;
      }

      // Carry the true CSV line number so consumers report the real row rather
      // than a compacted array offset (blank rows skipped above shift offsets).
      $row_data['_row_number'] = $row_number;
      $data[] = $row_data;
    }

    fclose($handle);

    if (!empty($errors)) {
      return [
        'valid' => FALSE,
        'message' => $this->t('CSV validation errors: @errors', ['@errors' => implode('; ', $errors)]),
        'data' => [],
        'headers' => [],
      ];
    }

    return [
      'valid' => TRUE,
      'message' => '',
      'data' => $data,
      'headers' => $header_mapping,
    ];
  }

  /**
   * Normalizes CSV headers.
   *
   * @param array $headers
   *   The raw headers from the CSV.
   *
   * @return array
   *   Normalized headers.
   */
  protected function normalizeHeaders(array $headers) {
    return array_map(function ($header) {
      // Excel's "CSV UTF-8" export writes a byte-order mark, which would
      // otherwise make the first column's header unrecognisable.
      return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $header)));
    }, $headers);
  }

  /**
   * Trims a cell, including non-breaking spaces at either end.
   *
   * A Drupal 7 Views Data Export decodes HTML entities in every cell, so an
   * &nbsp; arrives as a real U+00A0, which trim() leaves in place. Left at the
   * end of a date or a term name, it makes a valid value fail to match.
   *
   * @param string|null $value
   *   The raw cell value.
   *
   * @return string
   *   The value without leading or trailing whitespace.
   */
  protected function trimCell($value) {
    $trimmed = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', (string) $value);

    // preg_replace() returns NULL for a cell that is not valid UTF-8, such as
    // one from a file Excel saved in Windows-1252. trim() still works there.
    return $trimmed ?? trim((string) $value);
  }

  /**
   * Validates a single row of CSV data.
   *
   * @param array $row_data
   *   The row data with normalized keys.
   * @param int $row_number
   *   The row number for error reporting.
   *
   * @return array
   *   Array of validation errors.
   */
  protected function validateRow(array $row_data, $row_number) {
    $errors = [];

    // Validate required fields.
    if (empty(trim($row_data['display name']))) {
      $errors[] = $this->t('Row @row: Display Name is required.', ['@row' => $row_number]);
    }

    // Validate email format if provided.
    if (!empty($row_data['email']) && !filter_var($row_data['email'], FILTER_VALIDATE_EMAIL)) {
      $errors[] = $this->t('Row @row: Invalid email format: @email.', [
        '@row' => $row_number,
        '@email' => $row_data['email'],
      ]);
    }

    // Validate teaser text length.
    if (!empty($row_data['teaser text']) && strlen($row_data['teaser text']) > 150) {
      $errors[] = $this->t('Row @row: Teaser Text exceeds 150 characters.', ['@row' => $row_number]);
    }

    return $errors;
  }

  /**
   * Validates a single row of resource, post or event CSV data.
   *
   * Those importers check everything but the Title per row themselves.
   *
   * @param array $row_data
   *   The row data with normalized keys.
   * @param int $row_number
   *   The row number for error reporting.
   *
   * @return array
   *   Array of validation errors.
   */
  protected function validateTitledRow(array $row_data, $row_number) {
    $errors = [];

    if (empty(trim($row_data['title'] ?? ''))) {
      $errors[] = $this->t('Row @row: Title is required.', ['@row' => $row_number]);
    }

    return $errors;
  }

  /**
   * Gets the expected columns for profile import.
   *
   * @return array
   *   Array of expected columns.
   */
  public function getExpectedColumns() {
    return self::EXPECTED_COLUMNS;
  }

  /**
   * Gets the expected columns for resource import.
   *
   * @return array
   *   Array of expected columns, keyed by normalised header.
   */
  public function getExpectedResourceColumns() {
    return self::EXPECTED_RESOURCE_COLUMNS;
  }

  /**
   * Gets the expected columns for post import.
   *
   * @return array
   *   Array of expected columns, keyed by normalised header.
   */
  public function getExpectedPostColumns() {
    return self::EXPECTED_POST_COLUMNS;
  }

  /**
   * Lists post CSV headers the importer does not understand.
   *
   * @param array $headers
   *   The header mapping returned by validatePostCsvStructure(), keyed by
   *   normalised header.
   *
   * @return array
   *   The original spellings of the headers that will be ignored.
   *
   * @see getUnknownResourceColumns()
   */
  public function getUnknownPostColumns(array $headers) {
    return array_values(array_diff_key($headers, self::EXPECTED_POST_COLUMNS));
  }

  /**
   * Gets the expected columns for event import.
   *
   * @return array
   *   Array of expected columns, keyed by normalised header.
   */
  public function getExpectedEventColumns() {
    return self::EXPECTED_EVENT_COLUMNS;
  }

  /**
   * Lists event CSV headers the importer does not understand.
   *
   * @param array $headers
   *   The header mapping returned by validateEventCsvStructure(), keyed by
   *   normalised header.
   *
   * @return array
   *   The original spellings of the headers that will be ignored.
   *
   * @see getUnknownResourceColumns()
   */
  public function getUnknownEventColumns(array $headers) {
    return array_values(array_diff_key($headers, self::EXPECTED_EVENT_COLUMNS));
  }

  /**
   * Lists resource CSV headers the importer does not understand.
   *
   * A header the importer does not recognise is skipped in silence, so a
   * mistyped "Tags" would drop every tag with nothing to show for it. The
   * caller warns about whatever this returns.
   *
   * @param array $headers
   *   The header mapping returned by validateResourceCsvStructure(), keyed by
   *   normalised header.
   *
   * @return array
   *   The original spellings of the headers that will be ignored.
   */
  public function getUnknownResourceColumns(array $headers) {
    // Aliases were already folded into their canonical header when the file
    // was parsed, so only the canonical list needs comparing here.
    return array_values(array_diff_key($headers, self::EXPECTED_RESOURCE_COLUMNS));
  }

}
