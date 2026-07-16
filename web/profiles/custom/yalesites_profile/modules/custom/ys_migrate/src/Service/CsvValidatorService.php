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
   * Validates the CSV file structure and content.
   *
   * @param string $file_path
   *   The path to the CSV file.
   *
   * @return array
   *   Validation result with 'valid', 'message', 'data', and 'headers' keys.
   */
  public function validateCsvStructure($file_path) {
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

    // Normalize headers (remove whitespace, convert to lowercase).
    $normalized_headers = $this->normalizeHeaders($headers);

    // Check for required header.
    if (!in_array('display name', $normalized_headers)) {
      fclose($handle);
      return [
        'valid' => FALSE,
        'message' => $this->t('The CSV file must contain a "Display Name" column.'),
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
      $row_errors = $this->validateRow($row_data, $row_number);
      if (!empty($row_errors)) {
        $errors = array_merge($errors, $row_errors);
        continue;
      }

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
      'message' => $this->t('CSV file is valid. Found @count profiles.', ['@count' => count($data)]),
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
      return strtolower(trim($header));
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
   * Gets the expected columns for profile import.
   *
   * @return array
   *   Array of expected columns.
   */
  public function getExpectedColumns() {
    return self::EXPECTED_COLUMNS;
  }

}
