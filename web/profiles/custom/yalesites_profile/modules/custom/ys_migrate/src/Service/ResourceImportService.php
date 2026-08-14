<?php

namespace Drupal\ys_migrate\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for importing resource content from CSV data.
 *
 * Resource Media cannot travel in a CSV cell, so imported resources never carry
 * one. Rows with an External Source are usable immediately; the rest are listed
 * in the import result so the editor knows which nodes still need a file.
 */
class ResourceImportService {

  use StringTranslationTrait;

  /**
   * Date formats accepted in the Resource Publication Date column.
   *
   * Deliberately an explicit list rather than strtotime(): "03/04/2026" means
   * different days to different readers, and a silently wrong publication date
   * is worse than a rejected row.
   */
  const DATE_INPUT_FORMATS = ['Y-m-d', 'm/d/Y', 'n/j/Y', 'Y-n-j'];

  /**
   * The field storage holding the allowed Date Format values.
   */
  const DATE_FORMAT_STORAGE = 'node.field_date_format';

  /**
   * Fallback Date Format options, keyed by stored value.
   *
   * The live values are read from DATE_FORMAT_STORAGE so adding an option to
   * that field does not silently start rejecting valid CSV cells. This copy is
   * only used if the field is missing, as it is in a unit test.
   */
  const DATE_FORMAT_OPTIONS = [
    'year_only' => 'Year',
    'month_year' => 'Month/Year',
    'date' => 'Month/Day/Year',
  ];

  /**
   * Fallback text format per long-text field.
   *
   * Long-text fields need an explicit format, or Drupal falls back to the
   * plain-text fallback filter and escapes the editor's markup. The format
   * must also be one the field itself allows: each of these fields restricts
   * allowed_formats to a single format, and storing anything else leaves the
   * widget disabled on the node form ("you do not have sufficient permissions
   * to edit it"), because no YaleSites role holds "administer filters".
   *
   * The live values are read from each field's own config; these are only the
   * documented fallbacks, used when the field cannot be loaded.
   */
  const TEXT_FORMAT_FALLBACKS = [
    'field_content_description' => 'restricted_html',
    'field_teaser_text' => 'heading_html',
    'field_abstract' => 'restricted_html',
    'field_citation' => 'restricted_html',
  ];

  /**
   * Maximum Teaser Text length when the form display does not declare one.
   */
  const TEASER_TEXT_MAX_LENGTH = 150;

  /**
   * Moderation state imported resources are created in.
   *
   * Matches the editorial workflow's own default_moderation_state.
   */
  const MODERATION_STATE = 'draft';

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
   * Allowed Date Format values, resolved once per import run.
   *
   * @var array|null
   */
  protected $dateFormatOptions;

  /**
   * Text formats per long-text field, resolved once per import run.
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
   * Constructs a ResourceImportService object.
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
   * Prepares resource data from a CSV row.
   *
   * @param array $row
   *   The CSV row data, keyed by lowercased column header.
   *
   * @return array
   *   Prepared resource data.
   *
   * @throws \InvalidArgumentException
   *   If a cell cannot be interpreted. Thrown per row so one bad cell fails
   *   only its own row rather than the whole file.
   */
  public function prepareResourceData(array $row) {
    return [
      'title' => trim($row['title'] ?? ''),
      'description' => trim($row['description'] ?? ''),
      'abstract' => trim($row['abstract'] ?? ''),
      'citation' => trim($row['citation'] ?? ''),
      'journal_publication_name' => trim($row['journal publication name'] ?? ''),
      'journal_publication_issue' => trim($row['journal publication issue'] ?? ''),
      'category' => $this->taxonomyResolver->parseCommaSeparatedValues($row['resource category'] ?? ''),
      'audience' => $this->taxonomyResolver->parseCommaSeparatedValues($row['audience'] ?? ''),
      // Alternative header spellings are folded onto the canonical name by
      // CsvValidatorService::RESOURCE_COLUMN_ALIASES before we see the row.
      'custom_vocab' => $this->taxonomyResolver->parseCommaSeparatedValues($row['custom vocab'] ?? ''),
      'tags' => $this->taxonomyResolver->parseCommaSeparatedValues($row['tags'] ?? ''),
      'publish_date' => $this->parseDate($row['resource publication date'] ?? ''),
      'date_format' => $this->parseDateFormat($row['date format'] ?? ''),
      'teaser_title' => trim($row['teaser title'] ?? ''),
      'teaser_text' => $this->parseTeaserText($row['teaser text'] ?? ''),
      'external_source' => $this->parseExternalSource($row['external source'] ?? ''),
      'login_required' => $this->parseBoolean($row['cas login required'] ?? '', 'CAS Login Required'),
      'sticky' => $this->parseBoolean($row['pin to beginning of list'] ?? '', 'Pin to beginning of list'),
    ];
  }

  /**
   * Finds an existing resource by title.
   *
   * Resources have no natural unique key, so title is the duplicate key.
   *
   * @param string $title
   *   The title to search for.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The existing resource node or NULL.
   */
  public function findExistingResource($title) {
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'resource')
      ->condition('title', $title)
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($nids)) {
      return $this->entityTypeManager->getStorage('node')->load(reset($nids));
    }

    return NULL;
  }

  /**
   * Creates a resource node from the prepared data.
   *
   * @param array $data
   *   The resource data, as returned by prepareResourceData().
   *
   * @return \Drupal\node\NodeInterface
   *   The created node.
   *
   * @throws \Exception
   *   If the node cannot be saved. The reason is logged and re-thrown so the
   *   caller can surface it instead of a generic failure message.
   */
  public function createResourceNode(array $data) {
    $values = [
      'type' => 'resource',
      'title' => $data['title'],
      'field_category' => $this->taxonomyResolver->resolveTerms($data['category'], 'resource_category'),
      'field_audience' => $this->taxonomyResolver->resolveTerms($data['audience'], 'audience'),
      'field_tags' => $this->taxonomyResolver->resolveTerms($data['tags'], 'tags'),
      'field_custom_vocab' => $this->taxonomyResolver->resolveTerms($data['custom_vocab'], 'custom_vocab'),
      'field_login_required' => (int) $data['login_required'],
      'sticky' => (int) $data['sticky'],
      'uid' => $this->currentUser->id(),
      // The resource bundle runs under the editorial workflow, so publication
      // is owned by moderation_state; a 'status' value set here is silently
      // overridden. Imported resources land as drafts for an editor to review
      // and publish, which is what that workflow's default_moderation_state
      // asks for and the safer default for a bulk import.
      'moderation_state' => self::MODERATION_STATE,
    ];

    // Optional values are omitted rather than written as empty, so an imported
    // node looks the same as one an editor left blank in the node form.
    if ($data['description'] !== '') {
      $values['field_content_description'] = [
        'value' => $data['description'],
        'format' => $this->textFormat('field_content_description'),
      ];
    }
    if ($data['abstract'] !== '') {
      $values['field_abstract'] = [
        'value' => $data['abstract'],
        'format' => $this->textFormat('field_abstract'),
      ];
    }
    if ($data['citation'] !== '') {
      $values['field_citation'] = [
        'value' => $data['citation'],
        'format' => $this->textFormat('field_citation'),
      ];
    }
    if ($data['journal_publication_name'] !== '') {
      $values['field_journal_publication_name'] = $data['journal_publication_name'];
    }
    if ($data['journal_publication_issue'] !== '') {
      $values['field_journal_publication_issue'] = $data['journal_publication_issue'];
    }
    if ($data['teaser_title'] !== '') {
      $values['field_teaser_title'] = $data['teaser_title'];
    }
    if ($data['teaser_text'] !== '') {
      $values['field_teaser_text'] = [
        'value' => $data['teaser_text'],
        'format' => $this->textFormat('field_teaser_text'),
      ];
    }
    if ($data['publish_date'] !== NULL) {
      $values['field_publish_date'] = $data['publish_date'];
    }
    if ($data['date_format'] !== NULL) {
      $values['field_date_format'] = $data['date_format'];
    }
    if ($data['external_source'] !== '') {
      $values['field_external_source'] = ['uri' => $data['external_source']];
    }

    $node = $this->entityTypeManager->getStorage('node')->create($values);

    try {
      $node->save();
      return $node;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('ys_migrate')->error('Failed to create resource node: @error', ['@error' => $e->getMessage()]);
      // Re-throw so processImport() can report the real reason to the user
      // instead of a generic "could not create resource" message.
      throw $e;
    }
  }

  /**
   * Processes the import and creates resource nodes.
   *
   * @param array $data
   *   The CSV data.
   * @param bool $skip_duplicates
   *   Whether to skip rows whose title already exists.
   *
   * @return array
   *   Import results with 'created', 'skipped', 'errors' and 'needs_media'
   *   keys, the last being the titles created without an External Source,
   *   which are the ones an editor must still attach Resource Media to.
   */
  public function processImport(array $data, $skip_duplicates) {
    $created = 0;
    $skipped = 0;
    $errors = [];
    $needs_media = [];

    foreach ($data as $index => $row) {
      try {
        $resource_data = $this->prepareResourceData($row);

        if ($skip_duplicates && $this->findExistingResource($resource_data['title'])) {
          $skipped++;
          continue;
        }

        $this->createResourceNode($resource_data);
        $created++;

        if ($resource_data['external_source'] === '') {
          $needs_media[] = $resource_data['title'];
        }
      }
      catch (\Exception $e) {
        $errors[] = $this->rowError($row, $index, $e);
      }
    }

    return [
      'created' => $created,
      'skipped' => $skipped,
      'errors' => $errors,
      'needs_media' => $needs_media,
    ];
  }

  /**
   * Previews the import without creating content.
   *
   * @param array $data
   *   The CSV data.
   * @param bool $skip_duplicates
   *   Whether to skip rows whose title already exists.
   *
   * @return array
   *   Preview results with 'valid_resources', 'duplicates', 'errors' and
   *   'total' keys.
   */
  public function previewImport(array $data, $skip_duplicates) {
    $duplicates = [];
    $valid_resources = [];
    $errors = [];
    $seen_titles = [];

    foreach ($data as $index => $row) {
      try {
        $resource_data = $this->prepareResourceData($row);

        // A title repeated inside the file is a duplicate too: the import
        // creates the first row, then skips the rest. Tracking it here keeps
        // the preview's count honest, which is the whole point of previewing.
        if ($skip_duplicates
          && (isset($seen_titles[$resource_data['title']]) || $this->findExistingResource($resource_data['title']))) {
          $duplicates[] = $resource_data['title'];
          continue;
        }

        $seen_titles[$resource_data['title']] = TRUE;
        $valid_resources[] = $resource_data;
      }
      catch (\Exception $e) {
        $errors[] = $this->rowError($row, $index, $e);
      }
    }

    return [
      'valid_resources' => $valid_resources,
      'duplicates' => $duplicates,
      'errors' => $errors,
      'total' => count($data),
    ];
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
   * Parses a publication date cell into the field's storage format.
   *
   * @param string $value
   *   The raw cell value.
   *
   * @return string|null
   *   The date as Y-m-d, or NULL when the cell is empty.
   *
   * @throws \InvalidArgumentException
   *   If the value is not one of the accepted formats or is not a real date.
   */
  protected function parseDate($value) {
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
      'Resource Publication Date "%s" is not a valid date. Use YYYY-MM-DD or MM/DD/YYYY.',
      $value
    ));
  }

  /**
   * Parses a Date Format cell into the stored list value.
   *
   * @param string $value
   *   The raw cell value: either the stored key or the human label.
   *
   * @return string|null
   *   The stored value, or NULL when the cell is empty.
   *
   * @throws \InvalidArgumentException
   *   If the value matches neither a key nor a label.
   */
  protected function parseDateFormat($value) {
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }

    $options = $this->dateFormatOptions();

    foreach ($options as $key => $label) {
      if (strcasecmp($value, $key) === 0 || strcasecmp($value, $label) === 0) {
        return $key;
      }
    }

    throw new \InvalidArgumentException(sprintf(
      'Date Format "%s" is not recognised. Use one of: %s.',
      $value,
      implode(', ', $options)
    ));
  }

  /**
   * Resolves the text format to store for a long-text field.
   *
   * @param string $field_name
   *   The field machine name.
   *
   * @return string
   *   The first format the field allows, or the documented fallback.
   */
  protected function textFormat($field_name) {
    if (isset($this->textFormats[$field_name])) {
      return $this->textFormats[$field_name];
    }

    $allowed = [];

    try {
      $field = $this->entityTypeManager->getStorage('field_config')
        ->load('node.resource.' . $field_name);
      $allowed = $field ? (array) $field->getSetting('allowed_formats') : [];
    }
    catch (\Exception $e) {
      // Fall through to the documented fallback below.
    }

    $this->textFormats[$field_name] = $allowed
      ? reset($allowed)
      : self::TEXT_FORMAT_FALLBACKS[$field_name];

    return $this->textFormats[$field_name];
  }

  /**
   * Reads the Teaser Text character limit from the resource form display.
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
        ->load('node.resource.default');
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
   * Reads the allowed Date Format values from the field's own storage config.
   *
   * @return array
   *   Allowed values keyed by stored value, falling back to
   *   DATE_FORMAT_OPTIONS when the field storage cannot be read.
   */
  protected function dateFormatOptions() {
    if ($this->dateFormatOptions !== NULL) {
      return $this->dateFormatOptions;
    }

    $options = [];

    try {
      $storage = $this->entityTypeManager->getStorage('field_storage_config')
        ->load(self::DATE_FORMAT_STORAGE);
      // A loaded field storage returns allowed_values already simplified to a
      // value => label map, not the list of value/label pairs the config YAML
      // is written as.
      $options = $storage ? (array) $storage->getSetting('allowed_values') : [];
    }
    catch (\Exception $e) {
      // Fall through to the documented defaults below.
    }

    $this->dateFormatOptions = $options ?: self::DATE_FORMAT_OPTIONS;

    return $this->dateFormatOptions;
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
