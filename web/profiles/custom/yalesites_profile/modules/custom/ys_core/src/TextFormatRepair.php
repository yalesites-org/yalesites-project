<?php

namespace Drupal\ys_core;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;

/**
 * Repairs stored text formats a field's allowed_formats does not permit.
 *
 * A formatted-text field stores a format name alongside its value. When the
 * field instance restricts allowed_formats, core's
 * \Drupal\filter\Element\TextFormat::processFormat() intersects the current
 * user's usable formats with that list and then checks the stored format
 * against the result:
 *
 * @code
 * $formats = filter_formats($user);
 * if (isset($element['#allowed_formats'])) {
 *   $formats = array_intersect_key($formats, array_flip($element['#allowed_formats']));
 * }
 * $user_has_access = isset($formats[$element['#format']]);
 * @endcode
 *
 * So a value stored with any format outside the field's contract disables the
 * widget outright — "This field has been disabled because you do not have
 * sufficient permissions to edit it." — for every user lacking 'administer
 * filters', regardless of whether they could use that format elsewhere on the
 * site. Core's remedy is for an administrator to reassign the format, but no
 * YaleSites role holds 'administer filters' (it permits creating arbitrary
 * formats, a stored-XSS vector), so the reassignment has to happen here.
 *
 * The repair writes the format column directly rather than loading and saving
 * entities. That is deliberate: content_moderation's presave handler rewrites
 * an entity's publication status whenever the stored status disagrees with its
 * moderation state, and that branch is NOT guarded by isSyncing() —
 * @see \Drupal\content_moderation\Entity\Handler\ModerationHandler::onPresave().
 * Re-saving every Resource revision would therefore risk silently unpublishing
 * live pages wherever such a divergence exists, which is exactly the kind of
 * inconsistency an import that bypassed the platform's own importer can leave
 * behind. Writing one column touches nothing else: no new revisions, no change
 * of default revision, no moderation state change, no 'changed' timestamp.
 *
 * Only the format name is corrected; the stored value is never touched, so the
 * repair is reversible by restoring the previous format name.
 *
 * @see yalesites-org/YaleSites-Internal#1646
 */
class TextFormatRepair {

  /**
   * Field types that store a value alongside a text format name.
   *
   * Only these can carry an out-of-contract format, so only these are worth
   * scanning. 'string' and 'string_long' hold no format and are excluded.
   */
  const FORMATTED_FIELD_TYPES = ['text', 'text_long', 'text_with_summary'];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected $cacheTagsInvalidator;

  /**
   * The entity type bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * Constructs a new TextFormatRepair.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cache_tags_invalidator
   *   The cache tags invalidator.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    Connection $database,
    CacheTagsInvalidatorInterface $cache_tags_invalidator,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->database = $database;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
  }

  /**
   * Finds every field instance on an entity type that restricts its format.
   *
   * Discovering the list beats hardcoding it: a field instance that gains an
   * allowed_formats restriction later is covered without anyone remembering to
   * extend a literal array, and the set cannot silently drift from config.
   *
   * The original hardcoded list existed because discovery also picks up fields
   * whose contract is NARROWER than what is stored, where repairing would drop
   * markup. That is no longer a reason to hardcode: ::repairFieldStorage()
   * refuses a lossy repair unless it is explicitly allowed, and reports what it
   * held back.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return array
   *   Field machine names keyed by bundle, both sorted, e.g.
   *   ['resource' => ['field_abstract', 'field_citation']]. Bundles with no
   *   restricted field are omitted.
   */
  public function findRestrictedFields($entity_type_id) {
    $found = [];

    foreach (array_keys($this->entityTypeBundleInfo->getBundleInfo($entity_type_id)) as $bundle) {
      foreach ($this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle) as $field_name => $definition) {
        if (!in_array($definition->getType(), self::FORMATTED_FIELD_TYPES, TRUE)) {
          continue;
        }
        $allowed = $definition->getSetting('allowed_formats');
        if (!is_array($allowed) || $allowed === []) {
          continue;
        }
        $found[$bundle][] = $field_name;
      }
    }

    foreach ($found as &$field_names) {
      sort($field_names);
    }
    ksort($found);

    return $found;
  }

  /**
   * Returns the text formats a field instance permits.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle name.
   * @param string $field_name
   *   The field machine name.
   *
   * @return array
   *   The permitted format names, or an empty array when the field is absent
   *   or places no restriction on the format.
   */
  public function getAllowedFormats($entity_type_id, $bundle, $field_name) {
    $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
    if (!isset($definitions[$field_name])) {
      return [];
    }

    $allowed = $definitions[$field_name]->getSetting('allowed_formats');

    return is_array($allowed) ? array_values($allowed) : [];
  }

  /**
   * Returns the format a stored value should be repaired to.
   *
   * @param string|null $stored_format
   *   The format currently stored on the value.
   * @param array $allowed_formats
   *   The formats the field permits, as returned by ::getAllowedFormats().
   *
   * @return string|null
   *   The format to store instead, or NULL when the value needs no repair.
   */
  public function getRepairFormat($stored_format, array $allowed_formats) {
    // An unrestricted field accepts whatever is stored.
    if ($allowed_formats === []) {
      return NULL;
    }

    // An empty value carries no format to correct. Assigning one here would
    // invent data rather than repair it.
    if ($stored_format === NULL || $stored_format === '') {
      return NULL;
    }

    // Already within the contract. Note this accepts ANY permitted format, not
    // just the first one, so a deliberate second choice is preserved.
    if (in_array($stored_format, $allowed_formats, TRUE)) {
      return NULL;
    }

    // Out of contract. The field permits exactly one format in every case this
    // targets; where it permits several, the first is the only defensible
    // default, matching how core picks a default via filter_default_format().
    return reset($allowed_formats);
  }

  /**
   * Returns the HTML tags that re-rendering under another format would remove.
   *
   * Answers the question the reviewer actually cares about — "does correcting
   * this format change what the reader sees?" — by running the value through
   * the real filter pipeline both ways and diffing the tags that survive. That
   * is more truthful than comparing the two formats' allowed_html settings,
   * which ignores every other filter in the chain (escaping, autop, the
   * platform's own line-break filter).
   *
   * Only removals count. A target format that ADDS markup is not a loss, so
   * additions are ignored. That asymmetry is deliberate but not free: a value
   * stored under a STRICTER format than the field's contract — plain_text on a
   * basic_html field, say — will be repaired without comment, and text that
   * renders escaped today will start rendering as markup. It is filtered
   * markup either way (this platform has no full_html), so the risk is
   * content fidelity rather than security, and repairing is still what
   * unlocks the widget. Revisit if a report ever comes in from that direction.
   *
   * @param string $value
   *   The stored text.
   * @param string $stored_format
   *   The format the value renders under today.
   * @param string $repair_format
   *   The format it would render under after the repair.
   * @param string $langcode
   *   The value's language code.
   *
   * @return string[]
   *   The markup tokens that would disappear, alphabetically. A token is an
   *   element name ('a') or an element's attribute ('p@class'). Empty when the
   *   repair is lossless.
   */
  public function droppedTags($value, $stored_format, $repair_format, $langcode = '') {
    if (trim((string) $value) === '') {
      return [];
    }

    // Only markup the author actually wrote can be lost. Filters synthesise
    // markup as well as strip it — filter_autop wraps bare text in <p>,
    // filter_url turns a bare URL into <a> — and the two formats do not enable
    // the same filters. heading_html has no filter_autop while restricted_html
    // does, so without this every plain-text teaser would look like it was
    // about to lose a <p> it never had, and the whole repair would stall on
    // false positives.
    $authored = $this->markupTokens((string) $value);
    if ($authored === []) {
      return [];
    }

    $before = $this->renderedTokens($value, $stored_format, $langcode);
    $after = $this->renderedTokens($value, $repair_format, $langcode);

    $dropped = array_intersect(array_diff($before, $after), $authored);

    // Losing an element implies losing its attributes, so reporting
    // "a, a@href, a@target" is noise. Keep the element and drop the rest.
    $dropped = array_values(array_filter(
      $dropped,
      static function ($token) use ($dropped) {
        $element = strstr($token, '@', TRUE);
        return $element === FALSE || !in_array($element, $dropped, TRUE);
      }
    ));
    sort($dropped);

    return $dropped;
  }

  /**
   * Returns the markup tokens surviving a render under a given format.
   *
   * @param string $value
   *   The stored text.
   * @param string $format
   *   The text format to render under.
   * @param string $langcode
   *   The value's language code.
   *
   * @return string[]
   *   The distinct markup tokens.
   */
  protected function renderedTokens($value, $format, $langcode) {
    // check_markup() is procedural, but it is the only entry point that runs
    // the whole configured filter chain; reimplementing it would be the thing
    // that makes this measurement untrue. filter is guaranteed present here:
    // without it no field could carry an allowed_formats restriction at all.
    //
    // A format that has been deleted or disabled renders as the empty string
    // (\Drupal\filter\Element\ProcessedText::preRenderText()), so such a value
    // reports no tokens and every repair of it measures as lossless. That is
    // correct rather than a hole: the value renders as nothing today, so the
    // repair can only put markup back.
    return $this->markupTokens((string) check_markup($value, $format, $langcode));
  }

  /**
   * Returns the distinct markup tokens in a fragment of HTML.
   *
   * Attributes are tokens in their own right because a format can permit an
   * element while stripping what qualifies it: heading_html allows <p> but no
   * attributes, so repairing a right-aligned paragraph to it keeps the <p> and
   * silently loses the alignment. Comparing element names alone would call that
   * lossless.
   *
   * @param string $html
   *   The markup to inspect.
   *
   * @return string[]
   *   Distinct tokens, each an element name ('p') or an element's attribute
   *   ('p@class'), lowercased.
   */
  protected function markupTokens($html) {
    if (trim($html) === '') {
      return [];
    }

    // Parsing beats a regex here: it sees hyphenated element names, ignores
    // stray angle brackets in text, and reaches attributes at all.
    $body = Html::load($html)->getElementsByTagName('body')->item(0);
    if ($body === NULL) {
      return [];
    }

    $tokens = [];
    foreach ($body->getElementsByTagName('*') as $element) {
      $name = strtolower($element->nodeName);
      $tokens[] = $name;
      foreach ($element->attributes as $attribute) {
        $tokens[] = $name . '@' . strtolower($attribute->nodeName);
      }
    }

    return array_values(array_unique($tokens));
  }

  /**
   * Corrects out-of-contract stored formats for one field on one bundle.
   *
   * A repair that would drop markup is only carried out when $allow_lossy says
   * the loss has been accepted for this field. Otherwise it is reported and
   * left in place, because the decision belongs to a human.
   *
   * Note that leaving a value alone also leaves its widget disabled — the bug
   * this repairs. Deferring is therefore not the "safe" option in general, only
   * the correct one where nobody has yet agreed to lose the markup.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle name.
   * @param string $field_name
   *   The field machine name.
   * @param bool $allow_lossy
   *   Whether to repair values whose rendering loses markup as a result.
   *
   * @return \Drupal\ys_core\TextFormatRepairResult
   *   The rows corrected and the rows deferred.
   */
  public function repairFieldStorage($entity_type_id, $bundle, $field_name, $allow_lossy = FALSE) {
    $allowed_formats = $this->getAllowedFormats($entity_type_id, $bundle, $field_name);
    if ($allowed_formats === []) {
      return new TextFormatRepairResult();
    }

    $tables = $this->getFieldTables($entity_type_id, $field_name);
    if ($tables === []) {
      return new TextFormatRepairResult();
    }

    $columns = $this->getFieldColumns($entity_type_id, $field_name);
    $format_column = $columns['format'];
    // text_with_summary renders its summary under the same format, so the
    // summary has to be weighed too or a teaser could lose a link unnoticed.
    $text_columns = array_intersect_key($columns, array_flip(['value', 'summary']));

    $entity_ids = [];
    $repaired = 0;
    $deferred = [];

    foreach ($tables as $table) {
      // Out-of-contract rows are a small minority, so they are inspected one at
      // a time: whether a repair is lossy depends on the individual value, and
      // a set-based UPDATE cannot make a per-value decision.
      $rows = $this->database->select($table, 't')
        ->fields('t', array_merge(
          ['entity_id', 'revision_id', 'delta', 'langcode', 'deleted', $format_column],
          array_values($text_columns)
        ))
        ->condition('bundle', $bundle)
        ->condition($format_column, $allowed_formats, 'NOT IN')
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      foreach ($rows as $row) {
        $stored_format = $row[$format_column];
        $repair_format = $this->getRepairFormat($stored_format, $allowed_formats);
        if ($repair_format === NULL) {
          continue;
        }

        // Measuring the loss means two full filter runs, so skip it entirely
        // when the answer cannot change the outcome.
        $dropped = [];
        if (!$allow_lossy) {
          $text = implode("\n", array_map(
            static fn($column) => (string) ($row[$column] ?? ''),
            $text_columns
          ));
          $dropped = $this->droppedTags($text, $stored_format, $repair_format, $row['langcode']);
        }

        if ($dropped !== []) {
          $deferred[] = [
            'entity_id' => (int) $row['entity_id'],
            'revision_id' => (int) $row['revision_id'],
            'from' => $stored_format,
            'to' => $repair_format,
            'dropped' => $dropped,
          ];
          continue;
        }

        // Collect the affected entities before rewriting, so their render
        // caches can be invalidated: writing the column directly bypasses the
        // entity API, which would otherwise do this.
        $entity_ids[(int) $row['entity_id']] = TRUE;

        $repaired += $this->database->update($table)
          ->fields([$format_column => $repair_format])
          ->condition('bundle', $bundle)
          ->condition('entity_id', $row['entity_id'])
          ->condition('revision_id', $row['revision_id'])
          ->condition('delta', $row['delta'])
          ->condition('langcode', $row['langcode'])
          ->condition('deleted', $row['deleted'])
          ->execute();
      }
    }

    $this->invalidateEntityCacheTags($entity_type_id, array_keys($entity_ids));

    return new TextFormatRepairResult($repaired, $deferred);
  }

  /**
   * Returns the database columns backing a field, keyed by property name.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $field_name
   *   The field machine name.
   *
   * @return array
   *   Column names keyed by field property, e.g.
   *   ['value' => 'field_abstract_value', 'format' => 'field_abstract_format'].
   */
  protected function getFieldColumns($entity_type_id, $field_name) {
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $storage_definition = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id)[$field_name];

    $columns = [];
    foreach (array_keys($storage_definition->getColumns()) as $property) {
      $columns[$property] = $storage->getTableMapping()->getFieldColumnName($storage_definition, $property);
    }

    return $columns;
  }

  /**
   * Returns the dedicated data and revision tables backing a field.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $field_name
   *   The field machine name.
   *
   * @return array
   *   The table names, or an empty array when the field is not stored in
   *   dedicated tables (a shared-table field has no bundle column to scope by).
   */
  protected function getFieldTables($entity_type_id, $field_name) {
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    if (!$storage instanceof SqlEntityStorageInterface) {
      return [];
    }

    $storage_definitions = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id);
    if (!isset($storage_definitions[$field_name])) {
      return [];
    }

    // The dedicated-table methods below live on DefaultTableMapping, not on
    // TableMappingInterface, so an exotic custom mapping has to be declined
    // rather than assumed.
    $table_mapping = $storage->getTableMapping();
    if (!$table_mapping instanceof DefaultTableMapping) {
      return [];
    }

    $storage_definition = $storage_definitions[$field_name];
    if (!$table_mapping->requiresDedicatedTableStorage($storage_definition)) {
      return [];
    }

    $tables = [$table_mapping->getDedicatedDataTableName($storage_definition)];
    if ($this->entityTypeManager->getDefinition($entity_type_id)->isRevisionable()) {
      $tables[] = $table_mapping->getDedicatedRevisionTableName($storage_definition);
    }

    return $tables;
  }

  /**
   * Invalidates the cache tags of the entities whose rows were rewritten.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param array $entity_ids
   *   The affected entity IDs.
   */
  protected function invalidateEntityCacheTags($entity_type_id, array $entity_ids) {
    if ($entity_ids === []) {
      return;
    }

    $tags = [];
    foreach ($entity_ids as $entity_id) {
      $tags[] = $entity_type_id . ':' . $entity_id;
    }
    $this->cacheTagsInvalidator->invalidateTags($tags);
  }

}
