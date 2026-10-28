<?php

namespace Drupal\ys_migrate\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Shared wiring for the CSV import services.
 *
 * Holds what the importers genuinely have in common: the same four injected
 * dependencies, the row-scoped error message they all produce, and the cell
 * parsers the resource and post importers share (dates, yes/no values, URLs,
 * Teaser Text, and the text format each long-text field stores).
 *
 * The per-row loops in processImport() and previewImport() stay in the
 * implementations: they read as parallel but differ in what identifies a
 * duplicate and in what each importer flags for an editor to follow up.
 */
abstract class CsvImportServiceBase implements CsvImportServiceInterface {

  use StringTranslationTrait;

  /**
   * The node bundle the importer creates.
   *
   * Names the field and form display config the shared cell parsers read.
   */
  const BUNDLE = '';

  /**
   * Date formats accepted in a date column.
   *
   * Deliberately an explicit list rather than strtotime(): "03/04/2026" means
   * different days to different readers, and a silently wrong publication date
   * is worse than a rejected row.
   */
  const DATE_INPUT_FORMATS = ['Y-m-d', 'm/d/Y', 'n/j/Y', 'Y-n-j'];

  /**
   * Fallback text format per long-text field, keyed by field machine name.
   *
   * Long-text fields need an explicit format, or Drupal falls back to the
   * plain-text fallback filter and escapes the editor's markup. The format
   * must also be one the field itself allows: storing anything else leaves the
   * widget disabled on the node form ("you do not have sufficient permissions
   * to edit it"), because no YaleSites role holds "administer filters".
   *
   * The live values are read from each field's own config; these are only the
   * documented fallbacks, used when the field cannot be loaded.
   */
  const TEXT_FORMAT_FALLBACKS = [];

  /**
   * Maximum Teaser Text length when the form display does not declare one.
   */
  const TEASER_TEXT_MAX_LENGTH = 150;

  /**
   * Cell values accepted as TRUE in a boolean column.
   */
  const TRUE_VALUES = ['yes', 'true', '1', 'on'];

  /**
   * Cell values accepted as FALSE in a boolean column.
   */
  const FALSE_VALUES = ['no', 'false', '0', 'off', ''];

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The taxonomy resolver service.
   *
   * @var \Drupal\ys_migrate\Service\TaxonomyResolverService
   */
  protected $taxonomyResolver;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Text formats per field config id, resolved once per import run.
   *
   * @var string[]
   */
  protected $textFormats = [];

  /**
   * The Teaser Text character limit, resolved once per import run.
   *
   * @var int|null
   */
  protected $teaserTextMaxLength;

  /**
   * Constructs a CSV import service.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\ys_migrate\Service\TaxonomyResolverService $taxonomy_resolver
   *   The taxonomy resolver service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    AccountInterface $current_user,
    TaxonomyResolverService $taxonomy_resolver,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->currentUser = $current_user;
    $this->taxonomyResolver = $taxonomy_resolver;
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Formats a row-scoped error message.
   *
   * @param array $row
   *   The CSV row, which may carry the true line number from the validator.
   * @param int $index
   *   The array offset, used when the row has no line number.
   * @param \Exception $e
   *   The exception raised while handling the row.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The message to show the editor.
   */
  protected function rowError(array $row, $index, \Exception $e) {
    return $this->t('Row @row: @error', [
      // Prefer the true CSV line threaded through by the validator; blank rows
      // it skipped mean the array offset is not the line the editor sees.
      '@row' => $row['_row_number'] ?? ($index + 2),
      '@error' => $e->getMessage(),
    ]);
  }

  /**
   * Parses a date cell into a date-only field's storage format.
   *
   * @param string $value
   *   The raw cell value.
   * @param string $column
   *   The column label, for the error message.
   *
   * @return string|null
   *   The date as Y-m-d, or NULL when the cell is empty.
   *
   * @throws \InvalidArgumentException
   *   If the value is not one of the accepted formats or is not a real date.
   */
  protected function parseDate($value, $column) {
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }

    foreach (self::DATE_INPUT_FORMATS as $format) {
      $date = \DateTime::createFromFormat($format, $value);
      // Re-formatting catches values PHP rolls over, such as 2026-02-31
      // becoming 2026-03-03.
      if ($date && $date->format($format) === $value) {
        return $date->format('Y-m-d');
      }
    }

    throw new \InvalidArgumentException(sprintf(
      '%s "%s" is not a valid date. Use YYYY-MM-DD or MM/DD/YYYY.',
      $column,
      $value
    ));
  }

  /**
   * Resolves the text format to store for one of the bundle's long-text fields.
   *
   * @param string $field_name
   *   The field machine name, which must have a TEXT_FORMAT_FALLBACKS entry.
   *
   * @return string
   *   The first format the field allows, or the documented fallback.
   */
  protected function textFormat($field_name) {
    return $this->allowedTextFormat(
      'node.' . static::BUNDLE . '.' . $field_name,
      static::TEXT_FORMAT_FALLBACKS[$field_name]
    );
  }

  /**
   * Resolves the first text format a long-text field allows.
   *
   * @param string $field_config_id
   *   The field config id, such as "node.post.field_teaser_text".
   * @param string $fallback
   *   The format to use when the field cannot be loaded or allows any format.
   *
   * @return string
   *   The text format machine name.
   */
  protected function allowedTextFormat($field_config_id, $fallback) {
    if (isset($this->textFormats[$field_config_id])) {
      return $this->textFormats[$field_config_id];
    }

    $allowed = [];

    try {
      $field = $this->entityTypeManager->getStorage('field_config')->load($field_config_id);
      $allowed = $field ? (array) $field->getSetting('allowed_formats') : [];
    }
    catch (\Exception $e) {
      // Fall through to the documented fallback below.
    }

    $this->textFormats[$field_config_id] = $allowed ? reset($allowed) : $fallback;

    return $this->textFormats[$field_config_id];
  }

  /**
   * Reads the Teaser Text character limit from the bundle's form display.
   *
   * The limit is enforced only in JavaScript on the node form, so nothing
   * stops an import writing past it unless it is checked here.
   *
   * @return int
   *   The maximum length, or the documented fallback.
   */
  protected function teaserTextMaxLength() {
    if ($this->teaserTextMaxLength !== NULL) {
      return $this->teaserTextMaxLength;
    }

    $limit = 0;

    try {
      $display = $this->entityTypeManager->getStorage('entity_form_display')
        ->load('node.' . static::BUNDLE . '.default');
      $component = $display ? $display->getComponent('field_teaser_text') : [];
      $limit = (int) ($component['third_party_settings']['maxlength']['maxlength_js'] ?? 0);
    }
    catch (\Exception $e) {
      // Fall through to the documented fallback below.
    }

    $this->teaserTextMaxLength = $limit ?: self::TEASER_TEXT_MAX_LENGTH;

    return $this->teaserTextMaxLength;
  }

  /**
   * Checks a Teaser Text cell against the platform's own length limit.
   *
   * @param string $value
   *   The raw cell value.
   *
   * @return string
   *   The trimmed teaser text.
   *
   * @throws \InvalidArgumentException
   *   If the text is longer than the node form allows.
   */
  protected function parseTeaserText($value) {
    $value = trim($value);
    $limit = $this->teaserTextMaxLength();

    if (mb_strlen($value) > $limit) {
      throw new \InvalidArgumentException(sprintf(
        'Teaser Text is %d characters; the limit is %d.',
        mb_strlen($value),
        $limit
      ));
    }

    return $value;
  }

  /**
   * Parses an External Source cell into a link-field URI.
   *
   * @param string $value
   *   The raw cell value.
   *
   * @return string
   *   The validated URL, or an empty string when the cell is empty.
   *
   * @throws \InvalidArgumentException
   *   If the value is not an absolute http or https URL.
   */
  protected function parseExternalSource($value) {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    // The scheme check is what rejects javascript: and data: URLs; those pass
    // FILTER_VALIDATE_URL, which only checks the general URL shape.
    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    if (!filter_var($value, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'External Source "%s" is not a valid http or https URL.',
        $value
      ));
    }

    return $value;
  }

  /**
   * Parses a yes/no cell into a boolean.
   *
   * @param string $value
   *   The raw cell value. An empty cell is FALSE.
   * @param string $column
   *   The column label, for the error message.
   *
   * @return bool
   *   The parsed value.
   *
   * @throws \InvalidArgumentException
   *   If the value is not a recognised spelling. Unrecognised values are
   *   rejected rather than quietly treated as FALSE.
   */
  protected function parseBoolean($value, $column) {
    $value = strtolower(trim($value));

    if (in_array($value, self::TRUE_VALUES, TRUE)) {
      return TRUE;
    }
    if (in_array($value, self::FALSE_VALUES, TRUE)) {
      return FALSE;
    }

    throw new \InvalidArgumentException(sprintf(
      '%s "%s" is not a yes/no value. Use Yes or No.',
      $column,
      $value
    ));
  }

}
