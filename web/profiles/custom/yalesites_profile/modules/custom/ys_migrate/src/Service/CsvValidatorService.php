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
      [$this, 'validateResourceRow'],
      self::RESOURCE_COLUMN_ALIASES
    );

    if ($result['valid']) {
      $result['message'] = $this->t('CSV file is valid. Found @count resources.', ['@count' => count($result['data'])]);
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

      // Skip empty rows.
      if (empty(array_filter($row))) {
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
   * Validates a single row of resource CSV data.
   *
   * @param array $row_data
   *   The row data with normalized keys.
   * @param int $row_number
   *   The row number for error reporting.
   *
   * @return array
   *   Array of validation errors.
   */
  protected function validateResourceRow(array $row_data, $row_number) {
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
