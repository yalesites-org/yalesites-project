<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Tests\ys_core\Traits\ReadsProfileConfigTrait;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\office_hours\OfficeHoursDateHelper;

/**
 * Tests the header semantics of the Office Hours table formatter.
 *
 * The Office Hours block renders the schedule with the contrib module's
 * "Table" formatter, and that formatter built a header row that did not
 * describe the table it sat on top of. Three things were wrong, all invisible
 * in normal use because the header row is visually-hidden:
 *
 * - No header cell carried a scope attribute, so nothing told assistive
 *   technology which header belonged to which cell.
 * - The day cell was a plain <td>, so even with column headers an hours cell
 *   was announced with its column name and nothing tied it to its day - which
 *   in a schedule is the association that carries the meaning.
 * - The first header cell declared colspan="3" while the body rows have one
 *   cell per column, so a three-column table declared five.
 * - The header and the row each decided separately whether a 'Day' column
 *   existed, on conditions that did not match, so at day_format 'none' with
 *   exceptions or seasons on the header declared a column no row filled.
 *
 * All four are fixed by a patch on drupal/office_hours declared in the
 * profile's composer.json, so these tests also assert that patch is applied
 * and fail if a dependency resolve ever drops it.
 *
 * They are NOT a CI gate, and nothing here should be read as one: this repo's
 * `.ci/test/static/run` calls `composer unit-test`, which is
 * `echo 'No unit test step defined.'`, so PHPUnit runs locally only.
 *
 * WCAG 2.1 AA, 1.3.1 Info and Relationships.
 *
 * @group ys_core
 * @group yalesites
 */
class OfficeHoursTableHeaderTest extends YsKernelTestBase {

  use ReadsProfileConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'datetime',
    'entity_test',
    'field',
    'office_hours',
    'system',
    'user',
  ];

  /**
   * The field name used by the Office Hours block.
   */
  protected const FIELD_NAME = 'field_office_hours';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system']);
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('user');

    FieldStorageConfig::create([
      'field_name' => self::FIELD_NAME,
      'entity_type' => 'entity_test',
      'type' => 'office_hours',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
      // The settings the Office Hours block ships with, so the formatter is
      // exercised in the shape editors actually get: exceptions on, seasons
      // off, comments on.
      'settings' => $this->readConfig('field.storage.block_content.field_office_hours')['settings'],
    ])->save();

    FieldConfig::create([
      'field_name' => self::FIELD_NAME,
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Hours',
    ])->save();
  }

  /**
   * Every hours cell is reachable from both a column and a row header.
   *
   * The column headers name what each column holds; the row header is the day.
   * Only both together let assistive technology announce "Monday, Time slot,
   * 9:00 am-5:00 pm" rather than a bare run of times.
   */
  public function testEveryHoursCellHasColumnAndRowHeaders(): void {
    $tables = $this->tables($this->renderSchedule($this->everyRowShape()));
    $this->assertNotEmpty($tables, 'The formatter should render at least one table');

    foreach ($tables as $index => $table) {
      $headers = $this->headerCells($table);
      $this->assertNotEmpty($headers, "Table $index should have a header row");

      foreach ($headers as $header) {
        $this->assertSame(
          'col',
          $header->getAttribute('scope'),
          "Every header cell of table $index should declare scope=\"col\""
        );
      }

      $rows = $this->bodyRows($table);
      $this->assertNotEmpty($rows, "Table $index should have body rows");

      foreach ($rows as $row_index => $row) {
        $day = $this->cells($row)[0];
        $this->assertSame(
          'th',
          $day->tagName,
          "The day cell of table $index row $row_index should be a header cell"
        );
        $this->assertSame(
          'row',
          $day->getAttribute('scope'),
          "The day cell of table $index row $row_index should declare scope=\"row\""
        );
      }
    }
  }

  /**
   * The declared table shape matches the rendered one, in every row shape.
   *
   * A header row that declares more columns than the body fills describes a
   * table that is not there, which undermines the same success criterion the
   * missing scope does. One fixture covers every row the formatter builds
   * differently, and the assertion walks every table it produces - including
   * the second one the exception header starts.
   */
  public function testHeaderColumnCountMatchesBodyRowsInEveryRowShape(): void {
    $html = $this->renderSchedule($this->everyRowShape());
    $tables = $this->tables($html);

    $this->assertGreaterThan(
      1,
      count($tables),
      'An exception day should start a second table'
    );

    $this->assertShapeIsConsistent($html);
  }

  /**
   * The shape still holds when the day label is hidden.
   *
   * Setting day_format to 'none' is a supported option on the formatter's
   * settings form, and with exceptions or seasons enabled it used to be the
   * one reachable configuration where the header and the rows disagreed: the
   * header emitted a 'Day' cell that no row filled. Both sides now read one
   * shared flag, so they cannot drift apart again.
   *
   * Not reachable on YaleSites today - the shipped display sets
   * day_format 'long' and editors cannot change formatter settings - but the
   * patch is meant to go upstream, where every supported option counts.
   */
  public function testHeaderShapeHoldsWithTheDayLabelHidden(): void {
    $html = $this->renderSchedule($this->everyRowShape(), ['day_format' => 'none']);

    $this->assertShapeIsConsistent($html);

    foreach ($this->tables($html) as $index => $table) {
      $this->assertCount(
        2,
        $this->headerCells($table),
        "Table $index should drop the Day column entirely, leaving slots and comments"
      );
    }
  }

  /**
   * Asserts every rendered table declares as many columns as its rows fill.
   */
  protected function assertShapeIsConsistent(string $html): void {
    $tables = $this->tables($html);
    $this->assertNotEmpty($tables, 'The formatter should render at least one table');

    foreach ($tables as $index => $table) {
      // Sum colspans rather than counting cells: one of the defects under test
      // was a header cell with colspan="3" beside two ordinary ones, which a
      // flat cell count reads as three-against-three and passes. Do not reduce
      // this to assertCount() - it would stop detecting that bug.
      $declared = $this->columnsSpannedBy($this->headerCells($table));

      $rows = $this->bodyRows($table);
      $this->assertNotEmpty($rows, "Table $index should have body rows");

      foreach ($rows as $row_index => $row) {
        $this->assertSame(
          $declared,
          $this->columnsSpannedBy($this->cells($row)),
          "Table $index header declares $declared columns, body row $row_index does not match"
        );
      }
    }
  }

  /**
   * The exceptions section title is the table's caption, escaped exactly once.
   *
   * Moving the title out of a header cell is what lets the colspan go, so the
   * caption has to keep rendering or the title silently disappears from the
   * page. The title also arrives already escaped and wrapped in
   * TranslatableMarkup, so it must reach the template as MarkupInterface -
   * coerced to a plain string it would be escaped a second time and an
   * ampersand would render as '&amp;'.
   */
  public function testSectionTitleRendersAsCaptionEscapedOnce(): void {
    $title = 'Holidays & Closures';
    $html = $this->renderSchedule($this->everyRowShape(), ['exceptions' => ['title' => $title]]);

    $tables = $this->tables($html);
    $exceptions = end($tables);
    $caption = $exceptions->getElementsByTagName('caption')->item(0);

    $this->assertNotNull($caption, 'The exceptions table should have a caption');
    $this->assertSame($title, $caption->textContent);
  }

  /**
   * Counts the columns a row of cells occupies, honouring colspan.
   *
   * @param \DOMElement[] $cells
   *   The cells of one row.
   *
   * @return int
   *   The number of columns spanned.
   */
  protected function columnsSpannedBy(array $cells): int {
    $columns = 0;
    foreach ($cells as $cell) {
      $columns += max(1, (int) $cell->getAttribute('colspan'));
    }

    return $columns;
  }

  /**
   * Renders the field with the production formatter settings.
   *
   * @param array $values
   *   Office hours field values.
   * @param array $overrides
   *   Formatter settings to override. An 'exceptions' key is merged into the
   *   shipped sub-array rather than replacing it, because the formatter
   *   back-fills that sub-array's own defaults.
   *
   * @return string
   *   The rendered markup.
   */
  protected function renderSchedule(array $values, array $overrides = []): string {
    $entity = EntityTest::create([
      'name' => 'Office hours',
      self::FIELD_NAME => array_values($values),
    ]);

    $display = $this->readConfig('core.entity_view_display.block_content.office_hours.default');
    $settings = $display['content']['field_office_hours']['settings'];
    if (isset($overrides['exceptions'])) {
      $overrides['exceptions'] += $settings['exceptions'];
    }
    $settings = $overrides + $settings;

    $build = $entity->get(self::FIELD_NAME)->view([
      'type' => 'office_hours_table',
      'label' => 'hidden',
      'settings' => $settings,
    ]);

    return (string) \Drupal::service('renderer')->renderRoot($build);
  }

  /**
   * One fixture covering every row the formatter builds differently.
   *
   * A plain open day, a day with two slots, a day carrying an editor comment,
   * a closed day (label but no hours), an all-day day, and a holiday - the
   * last of which starts the second table.
   */
  protected function everyRowShape(): array {
    return [
      ['day' => 1, 'starthours' => 900, 'endhours' => 1200],
      ['day' => 1, 'starthours' => 1300, 'endhours' => 1700],
      ['day' => 2, 'starthours' => 900, 'endhours' => 1700, 'comment' => 'Front desk only'],
      ['day' => 3, 'starthours' => NULL, 'endhours' => NULL, 'comment' => 'By appointment'],
      ['day' => 4, 'starthours' => 0, 'endhours' => 2400],
      [
        'day' => strtotime(date(OfficeHoursDateHelper::DATE_STORAGE_FORMAT, strtotime('+30 days'))),
        'starthours' => NULL,
        'endhours' => NULL,
        'comment' => 'Holiday',
      ],
    ];
  }

  /**
   * Returns every table element in the rendered markup.
   *
   * @return \DOMElement[]
   *   The table elements, in document order.
   */
  protected function tables(string $html): array {
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $document->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return iterator_to_array($document->getElementsByTagName('table'));
  }

  /**
   * Returns the header cells of a table's header row.
   *
   * @return \DOMElement[]
   *   The cells, in document order.
   */
  protected function headerCells(\DOMElement $table): array {
    $head = $table->getElementsByTagName('thead')->item(0);
    return $head ? $this->cells($head->getElementsByTagName('tr')->item(0)) : [];
  }

  /**
   * Returns the body rows of a table.
   *
   * @return \DOMElement[]
   *   The rows, in document order.
   */
  protected function bodyRows(\DOMElement $table): array {
    $body = $table->getElementsByTagName('tbody')->item(0);
    return $body ? iterator_to_array($body->getElementsByTagName('tr')) : [];
  }

  /**
   * Returns the th and td cells of one row, in document order.
   *
   * @return \DOMElement[]
   *   The cells.
   */
  protected function cells(?\DOMElement $row): array {
    if (!$row) {
      return [];
    }

    $cells = [];
    foreach ($row->childNodes as $child) {
      if ($child instanceof \DOMElement && in_array($child->tagName, ['th', 'td'], TRUE)) {
        $cells[] = $child;
      }
    }

    return $cells;
  }

}
