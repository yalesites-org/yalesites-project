<?php

namespace Drupal\ys_migrate\Service;

/**
 * Service for importing resource content from CSV data.
 *
 * Resource Media cannot travel in a CSV cell, so imported resources never carry
 * one. Rows with an External Source are usable immediately; the rest are listed
 * in the import result so the editor knows which nodes still need a file.
 */
class ResourceImportService extends CsvImportServiceBase {

  /**
   * {@inheritdoc}
   */
  const BUNDLE = 'resource';

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
   * {@inheritdoc}
   *
   * Each of these fields restricts allowed_formats to a single format.
   */
  const TEXT_FORMAT_FALLBACKS = [
    'field_content_description' => 'restricted_html',
    'field_teaser_text' => 'heading_html',
    'field_abstract' => 'restricted_html',
    'field_citation' => 'restricted_html',
  ];

  /**
   * Moderation state imported resources are created in.
   *
   * Matches the editorial workflow's own default_moderation_state.
   */
  const MODERATION_STATE = 'draft';

  /**
   * Allowed Date Format values, resolved once per import run.
   *
   * @var array|null
   */
  protected $dateFormatOptions;

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
      'publish_date' => $this->parseDate($row['resource publication date'] ?? '', 'Resource Publication Date'),
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
   * {@inheritdoc}
   *
   * Adds a 'needs_media' key: the titles created without an External Source,
   * which are the ones an editor must still attach Resource Media to.
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
   * {@inheritdoc}
   *
   * Resources are keyed by title, and a title repeated inside the file counts
   * as a duplicate too. Adds an 'errors' key alongside 'valid_resources', so
   * a row that cannot be parsed is reported at preview rather than at import.
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

}
