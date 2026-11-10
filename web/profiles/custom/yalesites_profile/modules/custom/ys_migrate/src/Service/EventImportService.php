<?php

namespace Drupal\ys_migrate\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Service for importing event content from CSV data.
 *
 * Each row becomes one event with a single date. Repeat rules cannot be
 * written in a spreadsheet cell, so recurring events are out of scope: an
 * editor adds the rule in the event form after import.
 *
 * Imported events are marked with the "CSV import" Event Source, and the old
 * site's id goes in Event ID, the same fields the Localist and Campus Groups
 * integrations use to label their own events. Localist ID is never set: the
 * Localist sync only touches events it created, and the event page treats any
 * event with a Localist ID as a Localist event.
 *
 * Like the post importer, taxonomy columns only accept terms the site already
 * has, and images, video or embeds in the Description are left out and the
 * event listed for an editor to finish by hand.
 */
class EventImportService extends CsvImportServiceBase {

  /**
   * {@inheritdoc}
   */
  const BUNDLE = 'event';

  /**
   * {@inheritdoc}
   */
  const TEXT_FORMAT_FALLBACKS = [
    'field_event_description' => 'basic_html',
    'field_event_location_details' => 'restricted_html',
    'field_teaser_text' => 'heading_html',
  ];

  /**
   * {@inheritdoc}
   */
  const VOCABULARIES = [
    'category' => 'event_category',
    'tags' => 'tags',
    'audience' => 'audience',
    'custom_vocab' => 'custom_vocab',
    'experience' => 'event_type',
  ];

  /**
   * {@inheritdoc}
   */
  const URL_ALIAS_EXAMPLE = '/events/my-event';

  /**
   * Moderation state imported events are created in.
   *
   * Set deliberately: the events workflow's own default is published.
   */
  const MODERATION_STATE = 'draft';

  /**
   * Moderation state for events the editor chose to publish on import.
   */
  const PUBLISHED_STATE = 'published';

  /**
   * Permission for the events workflow's draft-to-published transition.
   *
   * Only roles that can publish an event by hand may publish them on import.
   */
  const PUBLISH_PERMISSION = 'use events transition publish';

  /**
   * The Event Source term that marks an imported event.
   *
   * Created in the event_sources vocabulary on first use, as the Localist and
   * Campus Groups migrations create theirs.
   */
  const EVENT_SOURCE = 'CSV import';

  /**
   * Vocabulary holding the Event Source terms.
   */
  const EVENT_SOURCE_VOCABULARY = 'event_sources';

  /**
   * Link text used when an Event Website is given without any.
   *
   * The event page shows the link text as its button label, with no fallback
   * of its own, so an empty text would leave a button with no name.
   */
  const EVENT_WEBSITE_DEFAULT_TEXT = 'Event Website';

  /**
   * Longest value the plain-text Ticket Cost and Event ID fields store.
   */
  const STRING_MAX_LENGTH = 255;

  /**
   * Matches a time of day, in 24-hour or 12-hour form.
   *
   * Captures the hour, the minutes and the am/pm marker. Seconds are accepted
   * and dropped, since the event form itself works in whole minutes.
   */
  const TIME_PATTERN = '/^(\d{1,2})(?::(\d{2}))?(?::\d{2})?\s*([ap])?\.?\s*(?:m\.?)?$/i';

  /**
   * Stored format of Start and End in the prepared data and the preview.
   *
   * The same format ys_content_export writes, so an export reads back in.
   */
  const DATETIME_FORMAT = 'Y-m-d H:i';

  /**
   * The config factory, for the site's timezone.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The site's timezone, resolved once per import run.
   *
   * @var \DateTimeZone|null
   */
  protected $timezone;

  /**
   * Constructs an EventImportService object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\ys_migrate\Service\TaxonomyResolverService $taxonomy_resolver
   *   The taxonomy resolver service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    AccountInterface $current_user,
    TaxonomyResolverService $taxonomy_resolver,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
  ) {
    parent::__construct($current_user, $taxonomy_resolver, $entity_type_manager, $logger_factory);
    $this->configFactory = $config_factory;
  }

  /**
   * Prepares event data from a CSV row.
   *
   * @param array $row
   *   The CSV row data, keyed by lowercased column header.
   *
   * @return array
   *   Prepared event data. Start and End are UNIX timestamps, with
   *   'start_text' and 'end_text' giving the same moments in the site's
   *   timezone for the preview. Taxonomy values stay as names, already
   *   checked to exist, so the preview can show what the editor typed.
   *
   * @throws \InvalidArgumentException
   *   If a cell cannot be interpreted. Thrown per row so one bad cell fails
   *   only its own row rather than the whole file.
   */
  public function prepareEventData(array $row) {
    // A Drupal 7 date field exports wrapped in a <span> unless the View strips
    // its tags, and Description needs tags kept, so a date may arrive wrapped.
    foreach (['start', 'end'] as $column) {
      $row[$column] = strip_tags($row[$column] ?? '');
    }

    $dates = $this->parseEventDates($row);
    $description = $this->parseRichText($row['description'] ?? '');
    $description['html'] = $this->filterDescription($description['html']);
    $website = $this->parseEventWebsite($row['event website'] ?? '', $row['event website text'] ?? '');

    return [
      'title' => trim($row['title'] ?? ''),
      'start' => $dates['start'],
      'end' => $dates['end'],
      'all_day' => $dates['all_day'],
      'start_text' => $this->formatTimestamp($dates['start'], $dates['all_day']),
      'end_text' => $this->formatTimestamp($dates['end'], $dates['all_day']),
      'description' => $description['html'],
      'description_has_media' => $description['has_media'],
      'location_details' => trim($row['location details'] ?? ''),
      'experience' => $this->parseExperience($row['event experience'] ?? ''),
      'event_website' => $website['uri'],
      'event_website_text' => $website['text'],
      'ticket_cost' => $this->parseString($row['ticket cost'] ?? '', 'Ticket Cost'),
      'ticket_registration_url' => $this->parseUrl($row['ticket registration url'] ?? '', 'Ticket Registration URL'),
      'stream_url' => $this->parseUrl($row['stream url'] ?? '', 'Stream URL'),
      'category' => $this->parseTerms($row['event category'] ?? '', 'category', 'Event Category'),
      'tags' => $this->parseTerms($row['tags'] ?? '', 'tags', 'Tags'),
      'audience' => $this->parseTerms($row['audience'] ?? '', 'audience', 'Audience'),
      // Alternative header spellings are folded onto the canonical name by
      // CsvValidatorService::EVENT_COLUMN_ALIASES before we see the row.
      'custom_vocab' => $this->parseTerms($row['custom vocab'] ?? '', 'custom_vocab', 'Custom Vocab'),
      'teaser_title' => trim($row['teaser title'] ?? ''),
      'teaser_text' => $this->parseTeaserText($row['teaser text'] ?? ''),
      'login_required' => $this->parseBoolean($row['cas login required'] ?? '', 'CAS Login Required'),
      'url_alias' => $this->parseUrlAlias($row['url alias'] ?? ''),
      'event_id' => $this->parseString($row['event id'] ?? '', 'Event ID'),
    ];
  }

  /**
   * Checks whether the current user may publish events on import.
   *
   * @return bool
   *   TRUE if they can publish an event in the events workflow.
   */
  public function canPublish() {
    return $this->currentUser->hasPermission(self::PUBLISH_PERMISSION);
  }

  /**
   * Finds an existing event that a row would duplicate.
   *
   * An event with the same title starting at the same moment is a duplicate
   * whatever created it, which also catches a copy Localist has already
   * synced. A row with an Event ID also matches an event an earlier CSV
   * import created from that id; ids are only compared within the CSV import
   * source, because Localist and Campus Groups store their own ids in the
   * same field.
   *
   * @param string $title
   *   The event title.
   * @param int $start
   *   The start, as a UNIX timestamp.
   * @param string $event_id
   *   The old site's event id, or an empty string.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The existing event node or NULL.
   */
  public function findExistingEvent($title, $start, $event_id = '') {
    $storage = $this->entityTypeManager->getStorage('node');

    $nids = $storage->getQuery()
      ->condition('type', self::BUNDLE)
      ->condition('title', $title)
      ->condition('field_event_date.value', $start)
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($nids) && $event_id !== '') {
      $source = $this->taxonomyResolver->findTerm(self::EVENT_SOURCE, self::EVENT_SOURCE_VOCABULARY);
      if ($source) {
        $nids = $storage->getQuery()
          ->condition('type', self::BUNDLE)
          ->condition('field_event_source', $source->id())
          ->condition('field_event_id', $event_id)
          ->range(0, 1)
          ->accessCheck(FALSE)
          ->execute();
      }
    }

    if (!empty($nids)) {
      return $storage->load(reset($nids));
    }

    return NULL;
  }

  /**
   * Creates an event node from the prepared data.
   *
   * @param array $data
   *   The event data, as returned by prepareEventData().
   * @param bool $publish
   *   Whether to publish the event rather than leave it a draft.
   *
   * @return \Drupal\node\NodeInterface
   *   The created node.
   *
   * @throws \Exception
   *   If the node cannot be saved. The reason is logged and re-thrown so the
   *   caller can surface it instead of a generic failure message.
   */
  public function createEventNode(array $data, $publish = FALSE) {
    $values = [
      'type' => self::BUNDLE,
      'title' => $data['title'],
      'field_event_date' => [
        'value' => $data['start'],
        'end_value' => $data['end'],
        'duration' => intdiv($data['end'] - $data['start'], 60),
      ],
      'field_category' => $this->termIds($data['category'], 'category', 'Event Category'),
      'field_tags' => $this->termIds($data['tags'], 'tags', 'Tags'),
      'field_audience' => $this->termIds($data['audience'], 'audience', 'Audience'),
      'field_custom_vocab' => $this->termIds($data['custom_vocab'], 'custom_vocab', 'Custom Vocab'),
      'field_localist_event_experience' => $this->termIds($data['experience'], 'experience', 'Event Experience'),
      'field_event_source' => $this->eventSourceIds(),
      'field_login_required' => (int) $data['login_required'],
      'uid' => $this->currentUser->id(),
      // The event bundle runs under the events workflow, so publication is
      // owned by moderation_state, and that workflow publishes new events by
      // default. Imported events land as drafts for an editor to review,
      // unless the editor chose to publish them.
      'moderation_state' => $publish ? self::PUBLISHED_STATE : self::MODERATION_STATE,
    ];

    // Optional values are omitted rather than written as empty, so an imported
    // node looks the same as one an editor left blank in the node form.
    if ($data['description'] !== '') {
      $values['field_event_description'] = [
        'value' => $data['description'],
        'format' => $this->textFormat('field_event_description'),
      ];
    }
    if ($data['location_details'] !== '') {
      $values['field_event_location_details'] = [
        'value' => $data['location_details'],
        'format' => $this->textFormat('field_event_location_details'),
      ];
    }
    if ($data['event_website'] !== '') {
      $values['field_event_cta'] = [
        'uri' => $data['event_website'],
        'title' => $data['event_website_text'],
      ];
    }
    if ($data['ticket_cost'] !== '') {
      $values['field_ticket_cost'] = $data['ticket_cost'];
    }
    if ($data['ticket_registration_url'] !== '') {
      $values['field_ticket_registration_url'] = ['uri' => $data['ticket_registration_url']];
    }
    if ($data['stream_url'] !== '') {
      $values['field_stream_url'] = ['uri' => $data['stream_url']];
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
    if ($data['url_alias'] !== '') {
      // 'pathauto' => 0 is PathautoState::SKIP: without it Pathauto replaces
      // the alias the editor asked for with one built from its own pattern.
      $values['path'] = ['alias' => $data['url_alias'], 'pathauto' => 0];
    }
    if ($data['event_id'] !== '') {
      $values['field_event_id'] = $data['event_id'];
    }

    $node = $this->entityTypeManager->getStorage('node')->create($values);

    try {
      $node->save();
      return $node;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('ys_migrate')->error('Failed to create event node: @error', ['@error' => $e->getMessage()]);
      // Re-throw so processImport() can report the real reason to the user
      // instead of a generic "could not create event" message.
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   *
   * Adds a 'needs_images' key: the titles whose Description held images,
   * video or embeds that could not be imported, which an editor must add by
   * hand.
   *
   * Takes a 'publish' option, honoured only for an editor who canPublish().
   */
  public function processImport(array $data, $skip_duplicates, array $options = []) {
    // Checked here as well as on the form: a batch runs whatever options it
    // was built with.
    $publish = !empty($options['publish']) && $this->canPublish();
    $created = 0;
    $skipped = 0;
    $errors = [];
    $needs_images = [];

    foreach ($data as $index => $row) {
      try {
        $event_data = $this->prepareEventData($row);

        if ($skip_duplicates && $this->findExistingEvent($event_data['title'], $event_data['start'], $event_data['event_id'])) {
          $skipped++;
          continue;
        }

        // Checked after the duplicate test, so re-running a file reports its
        // rows as skipped duplicates rather than as aliases already in use.
        $this->assertAliasAvailable($event_data['url_alias']);

        $this->createEventNode($event_data, $publish);
        $created++;

        if ($event_data['description_has_media']) {
          $needs_images[] = $event_data['title'];
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
      'needs_images' => $needs_images,
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Events are keyed by title and start, and by Event ID when a row has one;
   * a row repeating either inside the file counts as a duplicate too. A URL
   * Alias repeated inside the file is an error, as it would be at import.
   * Adds an 'errors' key alongside 'valid_events', so a row that cannot be
   * parsed is reported at preview rather than at import.
   */
  public function previewImport(array $data, $skip_duplicates) {
    $duplicates = [];
    $valid_events = [];
    $errors = [];
    $seen_events = [];
    $seen_aliases = [];

    foreach ($data as $index => $row) {
      try {
        $event_data = $this->prepareEventData($row);
        $keys = [$event_data['title'] . "\0" . $event_data['start']];
        if ($event_data['event_id'] !== '') {
          $keys[] = "id\0" . $event_data['event_id'];
        }

        // An event repeated inside the file is a duplicate too: the import
        // creates the first row, then skips the rest. Tracking it here keeps
        // the preview's count honest, which is the whole point of previewing.
        if ($skip_duplicates
          && (array_intersect_key($seen_events, array_flip($keys))
            || $this->findExistingEvent($event_data['title'], $event_data['start'], $event_data['event_id']))) {
          $duplicates[] = $event_data['title'];
          continue;
        }

        $this->assertAliasAvailable($event_data['url_alias']);
        if ($event_data['url_alias'] !== '' && isset($seen_aliases[$event_data['url_alias']])) {
          throw new \InvalidArgumentException(sprintf(
            'URL Alias "%s" is used by more than one row.',
            $event_data['url_alias']
          ));
        }

        $seen_events += array_fill_keys($keys, TRUE);
        if ($event_data['url_alias'] !== '') {
          $seen_aliases[$event_data['url_alias']] = TRUE;
        }
        $valid_events[] = $event_data;
      }
      catch (\Exception $e) {
        $errors[] = $this->rowError($row, $index, $e);
      }
    }

    return [
      'valid_events' => $valid_events,
      'duplicates' => $duplicates,
      'errors' => $errors,
      'total' => count($data),
    ];
  }

  /**
   * Parses the Start, End and All Day cells into one event date.
   *
   * An all-day event is stored the way Smart Date's own widget stores one:
   * from midnight on the first day to 23:59 on the last. Start without a time
   * means all day unless All Day says otherwise. A timed event with no End is
   * a point in time; an End holding only a time is on Start's day.
   *
   * @param array $row
   *   The CSV row data.
   *
   * @return array
   *   'start' and 'end' as UNIX timestamps, and 'all_day'.
   *
   * @throws \InvalidArgumentException
   *   If Start is missing, a cell cannot be read, a time is missing where one
   *   is needed, or End is before Start.
   */
  protected function parseEventDates(array $row) {
    $start = $this->parseDateTime($row['start'] ?? '', 'Start');
    if ($start === NULL) {
      throw new \InvalidArgumentException('Start is required.');
    }
    if ($start['date'] === NULL) {
      throw new \InvalidArgumentException(sprintf(
        'Start "%s" needs a date as well as a time.',
        trim($row['start'])
      ));
    }

    $end = $this->parseDateTime($row['end'] ?? '', 'End');
    $all_day_cell = trim($row['all day'] ?? '');
    $all_day = $all_day_cell === ''
      ? $start['time'] === NULL
      : $this->parseBoolean($all_day_cell, 'All Day');

    if ($all_day) {
      $end_date = $end['date'] ?? $start['date'];
      $start_ts = $this->timestamp($start['date'], '00:00');
      $end_ts = $this->timestamp($end_date, '23:59');
    }
    else {
      if ($start['time'] === NULL) {
        throw new \InvalidArgumentException(sprintf(
          'Start "%s" needs a time, such as 2026-03-04 14:00, unless All Day is Yes.',
          trim($row['start'])
        ));
      }
      $start_ts = $this->timestamp($start['date'], $start['time']);

      if ($end === NULL) {
        $end_ts = $start_ts;
      }
      elseif ($end['time'] === NULL) {
        throw new \InvalidArgumentException(sprintf(
          'End "%s" needs a time, since Start has one.',
          trim($row['end'])
        ));
      }
      else {
        $end_ts = $this->timestamp($end['date'] ?? $start['date'], $end['time']);
      }
    }

    if ($end_ts < $start_ts) {
      throw new \InvalidArgumentException(sprintf(
        'End "%s" is before Start "%s".',
        trim($row['end']),
        trim($row['start'])
      ));
    }

    return ['start' => $start_ts, 'end' => $end_ts, 'all_day' => $all_day];
  }

  /**
   * Splits a date-and-time cell into its date and its time.
   *
   * @param string $value
   *   The raw cell value: a date, a date and a time, or (for End) a time.
   * @param string $column
   *   The column label, for the error message.
   *
   * @return array|null
   *   'date' as Y-m-d and 'time' as H:i, either of which may be NULL, or NULL
   *   when the cell is empty.
   *
   * @throws \InvalidArgumentException
   *   If the date or time cannot be read.
   */
  protected function parseDateTime($value, $column) {
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }

    $time = $this->parseTime($value);
    if ($time !== NULL) {
      return ['date' => NULL, 'time' => $time];
    }

    // ISO 8601 joins the date and time with a T rather than a space.
    $normalised = preg_replace('/^(\d{4}-\d{1,2}-\d{1,2})T/i', '$1 ', $value);
    [$date_part, $time_part] = array_pad(preg_split('/\s+/', $normalised, 2), 2, '');

    try {
      $date = $this->parseDate($date_part, $column);
    }
    catch (\InvalidArgumentException $e) {
      throw new \InvalidArgumentException(sprintf(
        '%s "%s" is not a valid date and time. Use YYYY-MM-DD HH:MM or MM/DD/YYYY H:MM AM.',
        $column,
        $value
      ));
    }

    if ($time_part === '') {
      return ['date' => $date, 'time' => NULL];
    }

    $time = $this->parseTime($time_part);
    if ($time === NULL) {
      throw new \InvalidArgumentException(sprintf(
        '%s "%s" does not have a valid time. Use a time such as 14:00 or 2:00 PM.',
        $column,
        $value
      ));
    }

    return ['date' => $date, 'time' => $time];
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
   * Converts a date and time in the site's timezone to a UNIX timestamp.
   *
   * @param string $date
   *   The date, as Y-m-d.
   * @param string $time
   *   The time, as H:i.
   *
   * @return int
   *   The timestamp.
   */
  protected function timestamp($date, $time) {
    return (new \DateTimeImmutable($date . ' ' . $time, $this->timezone()))->getTimestamp();
  }

  /**
   * Formats a timestamp for the preview, in the site's timezone.
   *
   * @param int $timestamp
   *   The timestamp.
   * @param bool $all_day
   *   Whether the event is all day, in which case only the date is shown.
   *
   * @return string
   *   The formatted date, with its time unless the event is all day.
   */
  protected function formatTimestamp($timestamp, $all_day) {
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone($this->timezone())
      ->format($all_day ? 'Y-m-d' : self::DATETIME_FORMAT);
  }

  /**
   * Resolves the site's timezone.
   *
   * YaleSites does not let users pick their own timezone, so this is also the
   * timezone an editor's typed times are read in on the event form.
   *
   * @return \DateTimeZone
   *   The timezone.
   */
  protected function timezone() {
    if ($this->timezone === NULL) {
      $name = $this->configFactory->get('system.date')->get('timezone.default');
      $this->timezone = new \DateTimeZone($name ?: date_default_timezone_get());
    }

    return $this->timezone;
  }

  /**
   * Parses the Event Experience cell, which holds at most one term.
   *
   * @param string $value
   *   The raw cell value.
   *
   * @return string[]
   *   The term name, or no names when the cell is empty.
   *
   * @throws \InvalidArgumentException
   *   If more than one name is given, or the term does not exist.
   */
  protected function parseExperience($value) {
    $names = $this->parseTerms($value, 'experience', 'Event Experience');

    if (count($names) > 1) {
      throw new \InvalidArgumentException(sprintf(
        'Event Experience takes one value, such as In-person, Online or Hybrid, not "%s".',
        trim($value)
      ));
    }

    return $names;
  }

  /**
   * Removes the markup the Description's text format would not display.
   *
   * A post's Body renders through its text format, which drops an <img> or
   * embed. The event page prints the stored Description as it is, with no
   * format or filter, so anything left in it shows: an old site's image path
   * becomes a broken image. Running the format's own HTML filter here stores
   * only what the format allows, and the empty paragraphs that held an image
   * or a D7 token are removed so they do not show as blank lines.
   *
   * @param string $html
   *   The Description markup from parseRichText().
   *
   * @return string
   *   The filtered markup. Unchanged if the format cannot be loaded or does
   *   not limit HTML.
   */
  protected function filterDescription($html) {
    if ($html === '') {
      return '';
    }

    $format = $this->entityTypeManager->getStorage('filter_format')
      ->load($this->textFormat('field_event_description'));
    if (!$format || !$format->filters()->has('filter_html')) {
      return $html;
    }

    $filter = $format->filters()->get('filter_html');
    if (!$filter->status) {
      return $html;
    }

    $html = $filter->process($html, LanguageInterface::LANGCODE_NOT_SPECIFIED)->getProcessedText();

    return trim(preg_replace('/<p>(?:\s|&nbsp;|\x{00A0})*<\/p>/iu', '', $html));
  }

  /**
   * Parses the Event Website and Event Website Text cells.
   *
   * The link field accepts a path on this site as well as a full web address.
   *
   * @param string $url
   *   The raw Event Website cell.
   * @param string $text
   *   The raw Event Website Text cell.
   *
   * @return array
   *   'uri' for the link field, or an empty string, and the link 'text'.
   *
   * @throws \InvalidArgumentException
   *   If the address is not valid, or link text is given without one.
   */
  protected function parseEventWebsite($url, $text) {
    $url = trim($url);
    $text = trim($text);

    if ($url === '') {
      if ($text !== '') {
        throw new \InvalidArgumentException('Event Website Text needs an Event Website to link to.');
      }
      return ['uri' => '', 'text' => ''];
    }

    $uri = str_starts_with($url, '/') && !str_starts_with($url, '//')
      ? 'internal:' . $url
      : $this->parseUrl($url, 'Event Website');

    return [
      'uri' => $uri,
      'text' => $text !== '' ? $text : self::EVENT_WEBSITE_DEFAULT_TEXT,
    ];
  }

  /**
   * Checks a plain-text cell fits its field.
   *
   * @param string $value
   *   The raw cell value.
   * @param string $column
   *   The column label, for the error message.
   *
   * @return string
   *   The trimmed value.
   *
   * @throws \InvalidArgumentException
   *   If the value is longer than the field stores.
   */
  protected function parseString($value, $column) {
    $value = trim($value);

    if (mb_strlen($value) > self::STRING_MAX_LENGTH) {
      throw new \InvalidArgumentException(sprintf(
        '%s is %d characters; the limit is %d.',
        $column,
        mb_strlen($value),
        self::STRING_MAX_LENGTH
      ));
    }

    return $value;
  }

  /**
   * Resolves the Event Source term that marks an imported event.
   *
   * @return array
   *   The term id, or no ids if the term could not be created.
   */
  protected function eventSourceIds() {
    $term = $this->taxonomyResolver->findOrCreateTerm(self::EVENT_SOURCE, self::EVENT_SOURCE_VOCABULARY);

    return $term ? [$term->id()] : [];
  }

}
