<?php

namespace Drupal\ys_migrate\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Shared wiring for the CSV import services.
 *
 * Holds what the importers genuinely have in common: the same four injected
 * dependencies, the row-scoped error message they all produce, and the cell
 * parsers more than one importer uses. The resource, post and event importers
 * share dates, yes/no values, URLs, Teaser Text and the text format each
 * long-text field stores. The post and event importers also share rich text
 * (Body, Description), taxonomy columns that only accept existing terms, and
 * URL Alias.
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
   * Vocabulary behind each taxonomy column, keyed by prepared-data key.
   *
   * Read by parseTerms() and termIds().
   */
  const VOCABULARIES = [];

  /**
   * Example path quoted in URL Alias error messages.
   */
  const URL_ALIAS_EXAMPLE = '/about/my-page';

  /**
   * Matches rich text markup the basic_html format cannot show.
   *
   * None of these elements is allowed by basic_html, the format of both the
   * post Text block and the event Description, so they would be stripped when
   * the page renders. D7 Media WYSIWYG tokens ([[{"type":"media",...}]]) and
   * Node Embed tokens ([[nid:123]]) are matched too, since each stood in for
   * an image or an embedded page.
   */
  const MEDIA_PATTERN = '/<(img|picture|iframe|video|audio|object|embed|svg|drupal-media|drupal-entity)\b|\[\[(\{.*?\}|nid:\d+)\]\]/is';

  /**
   * Matches the D7 inline tokens, which would otherwise render as plain text.
   */
  const D7_TOKEN_PATTERN = '/\[\[(\{.*?\}|nid:\d+)\]\]/s';

  /**
   * Date formats accepted in a date column.
   *
   * Deliberately an explicit list rather than strtotime(): "03/04/2026" means
   * different days to different readers, and a silently wrong publication date
   * is worse than a rejected row. Slashed dates are read month first, as in
   * the US.
   *
   * Besides the ISO and US forms, the list holds the date part of each of
   * Drupal 7's built-in date formats, which a D7 date field prints unless the
   * export View is given a custom one. Spelled-out months cannot be misread.
   * Leading zeros are optional and month names ignore case in every format.
   */
  const DATE_INPUT_FORMATS = [
    'Y-m-d',
    'm/d/Y',
    'Y/m/d',
    'd.m.Y',
    'F j, Y',
    'M j, Y',
    'F j Y',
    'M j Y',
    'j F Y',
    'j M Y',
    'j F, Y',
    'j. F Y',
    'Y, F j',
    'Y M j',
  ];

  /**
   * Matches a time of day, in 24-hour or 12-hour form.
   *
   * Captures the hour, the minutes and the am/pm marker. Seconds are accepted
   * and dropped, since the event form itself works in whole minutes.
   */
  const TIME_PATTERN = '/^(\d{1,2})(?::(\d{2}))?(?::\d{2})?\s*([ap])?\.?\s*(?:m\.?)?$/i';

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
   * A time after the date is allowed and dropped: Drupal 7's Post date and
   * most of its date formats print one.
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
    $normalised = $this->normaliseDateText($value);
    if ($normalised === '') {
      return NULL;
    }

    $parts = $this->splitDateAndTime($normalised);
    if ($parts === NULL || ($parts['rest'] !== '' && $this->parseTime($parts['rest']) === NULL)) {
      throw new \InvalidArgumentException(sprintf(
        '%s "%s" is not a valid date. Use YYYY-MM-DD, MM/DD/YYYY or a date such as March 4, 2026.',
        $column,
        $value
      ));
    }

    return $parts['date'];
  }

  /**
   * Removes what Drupal 7 and spreadsheets add around a date.
   *
   * D7's built-in date formats lead with the weekday ("Wednesday, March 4,
   * 2026 - 2:00pm"), and an all-day D7 date can print "(All day)" in place of
   * its time. Both are dropped, which leaves an all-day date as a date alone.
   * ISO 8601's T between the date and the time becomes a space, and runs of
   * whitespace, non-breaking spaces included, become one space.
   *
   * @param string $value
   *   The cell value, with any markup already stripped.
   *
   * @return string
   *   The date text, trimmed.
   */
  protected function normaliseDateText($value) {
    // Byte patterns rather than /u, so a cell that is not valid UTF-8 still
    // reaches the error message instead of becoming NULL.
    $value = trim(preg_replace('/(?:\s|\xC2\xA0)+/', ' ', $value));
    $value = preg_replace('/\(\s*all[ -]?day\s*\)/i', '', $value);
    $value = preg_replace('/^(?:mon|tue|wed|thu|fri|sat|sun)[a-z]*\.?,?\s+/i', '', trim($value));
    $value = preg_replace('/^(\d{4}-\d{1,2}-\d{1,2})T/i', '$1 ', $value);
    return trim($value);
  }

  /**
   * Splits a cell into its date and whatever follows the date.
   *
   * The date is the longest run of leading words that reads as one, so
   * "March 4, 2026 - 2:00pm" splits after "2026". The separator Drupal 7 puts
   * between the date and the time (" - "), or a comma or "at", is dropped.
   *
   * @param string $value
   *   The cell, already passed through normaliseDateText().
   *
   * @return array|null
   *   'date' as Y-m-d and 'rest' as the text after it, empty for a date alone,
   *   or NULL when the cell does not start with a date.
   */
  protected function splitDateAndTime($value) {
    $words = explode(' ', $value);
    // No accepted date format runs to more than three words.
    for ($count = min(count($words), 3); $count > 0; $count--) {
      $date = $this->readDate(rtrim(implode(' ', array_slice($words, 0, $count)), ','));
      if ($date !== NULL) {
        $rest = implode(' ', array_slice($words, $count));
        return [
          'date' => $date,
          'rest' => trim(preg_replace('/^(?:-|,|at\b)\s*/i', '', $rest)),
        ];
      }
    }

    return NULL;
  }

  /**
   * Reads a date written in one of the accepted formats.
   *
   * @param string $value
   *   The date text alone.
   *
   * @return string|null
   *   The date as Y-m-d, or NULL when the text matches no accepted format or
   *   is not a real day.
   */
  protected function readDate($value) {
    foreach (self::DATE_INPUT_FORMATS as $format) {
      // The ! zeroes every field the format leaves out, the time included.
      $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
      if (!$date) {
        continue;
      }
      // PHP rolls an impossible date over (2026-02-31 becomes 2026-03-03) and
      // only warns that it did.
      $errors = \DateTimeImmutable::getLastErrors();
      if ($errors && $errors['warning_count'] > 0) {
        continue;
      }
      // Y also reads a two-digit year, as the first century: 3/4/26 would
      // otherwise import as the year 26.
      if (strpos($value, $date->format('Y')) === FALSE) {
        continue;
      }
      return $date->format('Y-m-d');
    }

    return NULL;
  }

  /**
   * Reads a time of day.
   *
   * @param string $value
   *   The text to read, such as "14:00", "2:00 PM" or "2pm".
   *
   * @return string|null
   *   The time as H:i, or NULL when the text is not a time.
   */
  protected function parseTime($value) {
    if (!preg_match(self::TIME_PATTERN, trim($value), $matches)) {
      return NULL;
    }

    $hour = (int) $matches[1];
    $minute = (int) ($matches[2] ?? 0);
    $meridiem = strtolower($matches[3] ?? '');

    if ($minute > 59) {
      return NULL;
    }

    if ($meridiem === '') {
      // Without am/pm a bare number is too ambiguous to be a time.
      if (($matches[2] ?? '') === '' || $hour > 23) {
        return NULL;
      }
    }
    else {
      if ($hour < 1 || $hour > 12) {
        return NULL;
      }
      $hour = ($hour % 12) + ($meridiem === 'p' ? 12 : 0);
    }

    return sprintf('%02d:%02d', $hour, $minute);
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
    return $this->parseUrl($value, 'External Source');
  }

  /**
   * Parses a web address cell into a link-field URI.
   *
   * @param string $value
   *   The raw cell value.
   * @param string $column
   *   The column label, for the error message.
   *
   * @return string
   *   The validated URL, or an empty string when the cell is empty.
   *
   * @throws \InvalidArgumentException
   *   If the value is not an absolute http or https URL.
   */
  protected function parseUrl($value, $column) {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    // The scheme check is what rejects javascript: and data: URLs; those pass
    // FILTER_VALIDATE_URL, which only checks the general URL shape.
    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    if (!filter_var($value, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        '%s "%s" is not a valid http or https URL.',
        $column,
        $value
      ));
    }

    return $value;
  }

  /**
   * Parses a rich text cell into markup for a basic_html field.
   *
   * D7 inline tokens are removed because they would render as literal text.
   * Everything else is kept as written: the field's own format filters what
   * it cannot show when the page renders.
   *
   * @param string $value
   *   The raw cell value: HTML, or plain text with blank lines between
   *   paragraphs.
   *
   * @return array
   *   'html': the markup to store, or an empty string if the cell holds no
   *   text. 'has_media': whether the cell held images, video, embeds or D7
   *   media tokens.
   */
  protected function parseRichText($value) {
    $value = trim($value);
    if ($value === '') {
      return ['html' => '', 'has_media' => FALSE];
    }

    $html = trim(preg_replace(self::D7_TOKEN_PATTERN, '', $value));
    $has_media = (bool) preg_match(self::MEDIA_PATTERN, $value);

    // A cell that is only filler, such as the <p>&nbsp;</p> a D7 editor leaves
    // behind, or only an image this import drops, would be stored as markup
    // that shows nothing. Treat it as empty.
    if (!$this->hasText($html)) {
      return ['html' => '', 'has_media' => $has_media];
    }

    // A spreadsheet cell is often plain text, which would otherwise run its
    // paragraphs together into one.
    if ($html === strip_tags($html)) {
      $html = $this->plainTextToHtml($html);
    }

    return [
      'html' => $html,
      'has_media' => $has_media,
    ];
  }

  /**
   * Checks whether markup holds any text once its tags are removed.
   *
   * @param string $html
   *   The markup.
   *
   * @return bool
   *   FALSE if it holds nothing but tags, whitespace and non-breaking spaces.
   */
  protected function hasText($html) {
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // A match failure (invalid UTF-8) counts as text, so content is never
    // dropped because it could not be read.
    return preg_match('/[^\s\x{00A0}]/u', $text) !== 0;
  }

  /**
   * Converts plain text into paragraphs, keeping single line breaks.
   *
   * @param string $text
   *   Plain text with blank lines between paragraphs.
   *
   * @return string
   *   The text as escaped HTML paragraphs.
   */
  protected function plainTextToHtml($text) {
    $paragraphs = array_filter(array_map('trim', preg_split('/\R\s*\R/', $text)), 'strlen');

    return implode("\n", array_map(
      static fn ($paragraph) => '<p>' . nl2br(Html::escape($paragraph), FALSE) . '</p>',
      $paragraphs
    ));
  }

  /**
   * Parses a taxonomy cell, checking every term already exists.
   *
   * @param string $value
   *   The raw comma-separated cell value.
   * @param string $key
   *   The prepared-data key, which names the vocabulary in VOCABULARIES.
   * @param string $column
   *   The column label, for the error message.
   *
   * @return string[]
   *   The term names.
   *
   * @throws \InvalidArgumentException
   *   If any name does not match a term in the vocabulary.
   */
  protected function parseTerms($value, $key, $column) {
    $names = $this->taxonomyResolver->parseCommaSeparatedValues($value);
    $this->termIds($names, $key, $column);
    return $names;
  }

  /**
   * Resolves term names to the ids of existing terms.
   *
   * @param string[] $names
   *   The term names.
   * @param string $key
   *   The prepared-data key, which names the vocabulary in VOCABULARIES.
   * @param string $column
   *   The column label, for the error message.
   *
   * @return array
   *   The term ids, in the order given.
   *
   * @throws \InvalidArgumentException
   *   If any name does not match a term in the vocabulary.
   */
  protected function termIds(array $names, $key, $column) {
    $ids = [];
    $missing = [];

    foreach ($names as $name) {
      $term = $this->taxonomyResolver->findTerm($name, static::VOCABULARIES[$key]);
      if ($term) {
        $ids[] = $term->id();
      }
      else {
        $missing[] = '"' . $name . '"';
      }
    }

    if ($missing) {
      throw new \InvalidArgumentException(sprintf(
        '%s has no existing term named %s. Add the term to the site first, or correct the spelling.',
        $column,
        implode(', ', $missing)
      ));
    }

    return $ids;
  }

  /**
   * Parses a URL Alias cell into a path alias.
   *
   * Whether the alias is free is checked separately, by assertAliasAvailable(),
   * so a duplicate row is reported as a duplicate rather than as a clash.
   *
   * @param string $value
   *   The raw cell value, with or without its leading slash.
   *
   * @return string
   *   The alias with a leading slash, or an empty string when the cell is
   *   empty.
   *
   * @throws \InvalidArgumentException
   *   If the value is a full URL or contains characters a path cannot hold.
   */
  protected function parseUrlAlias($value) {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    if (parse_url($value, PHP_URL_SCHEME) || str_starts_with($value, '//')) {
      throw new \InvalidArgumentException(sprintf(
        'URL Alias "%s" must be a path such as %s, not a full web address.',
        $value,
        static::URL_ALIAS_EXAMPLE
      ));
    }
    if (preg_match('/[\s?#]/', $value)) {
      throw new \InvalidArgumentException(sprintf(
        'URL Alias "%s" cannot contain spaces, "?" or "#". Use a path such as %s.',
        $value,
        static::URL_ALIAS_EXAMPLE
      ));
    }

    return '/' . ltrim($value, '/');
  }

  /**
   * Checks no existing page already uses a URL alias.
   *
   * @param string $alias
   *   The alias, or an empty string when the row has none.
   *
   * @throws \InvalidArgumentException
   *   If the alias is already in use.
   */
  protected function assertAliasAvailable($alias) {
    if ($alias === '') {
      return;
    }

    $ids = $this->entityTypeManager->getStorage('path_alias')->getQuery()
      ->condition('alias', $alias)
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($ids)) {
      throw new \InvalidArgumentException(sprintf(
        'URL Alias "%s" is already in use on this site.',
        $alias
      ));
    }
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
