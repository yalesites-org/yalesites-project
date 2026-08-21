<?php

namespace Drupal\Tests\ys_content_export\Unit;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_content_export\ContentExportBuilder;

/**
 * Unit tests for ContentExportBuilder.
 *
 * @coversDefaultClass \Drupal\ys_content_export\ContentExportBuilder
 * @group ys_content_export
 * @group yalesites
 */
class ContentExportBuilderTest extends UnitTestCase {

  /**
   * Tests that sanitizeCell neutralises CSV formula injection.
   *
   * @param string $value
   *   The raw cell value.
   * @param string $expected
   *   The expected safe value.
   *
   * @dataProvider sanitizeProvider
   * @covers ::sanitizeCell
   */
  public function testSanitizeCell(string $value, string $expected): void {
    $this->assertSame($expected, ContentExportBuilder::sanitizeCell($value));
  }

  /**
   * Provides cell values and their expected sanitised form.
   *
   * @return array
   *   Cases: [value, expected].
   */
  public static function sanitizeProvider(): array {
    return [
      'equals formula' => ['=1+1', "'=1+1"],
      'plus formula' => ['+1', "'+1"],
      'minus formula' => ['-1', "'-1"],
      'at formula' => ['@SUM(A1)', "'@SUM(A1)"],
      'tab prefix' => ["\tvalue", "'\tvalue"],
      'carriage return prefix' => ["\rvalue", "'\rvalue"],
      'ordinary text' => ['Hello world', 'Hello world'],
      'internal equals safe' => ['a=b', 'a=b'],
      'empty' => ['', ''],
    ];
  }

  /**
   * Tests the column map for each bundle.
   *
   * @covers ::getColumns
   */
  public function testGetColumns(): void {
    $page = ContentExportBuilder::getColumns('page');
    $this->assertSame(
      [
        'title',
        'url',
        'published',
        'cas_protected',
        'field_tags',
        'field_audience',
        'field_custom_vocab',
        'field_category',
      ],
      array_keys($page)
    );
    $this->assertSame('Title', $page['title']);
    $this->assertSame('URL', $page['url']);
    $this->assertSame('Published', $page['published']);
    $this->assertSame('CAS Protected', $page['cas_protected']);
    $this->assertSame('Category', $page['field_category']);

    $this->assertSame('Event Category', ContentExportBuilder::getColumns('event')['field_category']);
    $this->assertSame('Resource Category', ContentExportBuilder::getColumns('resource')['field_category']);

    $profile = ContentExportBuilder::getColumns('profile');
    $this->assertArrayHasKey('field_affiliation', $profile);
    $this->assertSame('Affiliation', $profile['field_affiliation']);
    $this->assertArrayNotHasKey('field_category', $profile);

    // Shared taxonomy columns appear on every bundle.
    foreach (['page', 'post', 'event', 'profile', 'resource'] as $bundle) {
      $columns = ContentExportBuilder::getColumns($bundle);
      $this->assertArrayHasKey('field_tags', $columns);
      $this->assertArrayHasKey('field_audience', $columns);
      $this->assertArrayHasKey('field_custom_vocab', $columns);
    }

    // Only events and resources carry a date column.
    $this->assertArrayNotHasKey('field_event_date', $page);
    $this->assertArrayNotHasKey('field_publish_date', $page);
    foreach (['post', 'profile'] as $bundle) {
      $columns = ContentExportBuilder::getColumns($bundle);
      $this->assertArrayNotHasKey('field_event_date', $columns);
      $this->assertArrayNotHasKey('field_publish_date', $columns);
    }
  }

  /**
   * Tests the date column sits immediately after Title, with the AC's header.
   *
   * The spreadsheet is meant to read in roughly the same order as the on-screen
   * Manage table, which puts the date next to the title.
   *
   * @param string $bundle
   *   The node bundle machine name.
   * @param string $key
   *   The expected date column key.
   * @param string $header
   *   The expected date column header.
   *
   * @dataProvider dateColumnProvider
   * @covers ::getColumns
   */
  public function testDateColumnFollowsTitle(string $bundle, string $key, string $header): void {
    $columns = ContentExportBuilder::getColumns($bundle);
    $this->assertSame(['title', $key], array_slice(array_keys($columns), 0, 2));
    $this->assertSame($header, $columns[$key]);
  }

  /**
   * Provides the bundles that gain a date column.
   *
   * @return array
   *   Cases: [bundle, column key, header].
   */
  public static function dateColumnProvider(): array {
    return [
      'events' => ['event', 'field_event_date', 'Dates'],
      'resources' => ['resource', 'field_publish_date', 'Resource Publication Date'],
    ];
  }

  /**
   * Tests the Dates cell for an event.
   *
   * @param array $instances
   *   Stored field values, in delta order: each [value, end_value].
   * @param string $expected
   *   The expected cell output.
   *
   * @dataProvider eventDatesProvider
   * @covers ::cellValue
   */
  public function testEventDatesCell(array $instances, string $expected): void {
    $items = [];
    foreach ($instances as [$start, $end]) {
      $items[] = (object) ['value' => $start, 'end_value' => $end];
    }

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('field_event_date')->willReturn(TRUE);
    $node->method('get')->with('field_event_date')->willReturn($items);

    $method = new \ReflectionMethod(ContentExportBuilder::class, 'cellValue');
    $method->setAccessible(TRUE);
    $this->assertSame(
      $expected,
      $method->invoke(NULL, $node, 'field_event_date', $this->dateFormatter())
    );
  }

  /**
   * Provides stored event date instances and their expected cell output.
   *
   * Timestamps are the real values pulled from node__field_event_date on a
   * local database, so the recurring case also covers the fact that stored
   * delta order is not chronological.
   *
   * @return array
   *   Cases: [instances, expected].
   */
  public static function eventDatesProvider(): array {
    return [
      'single timed instance' => [
        [[2125173600, 2125177200]],
        'Tuesday, May 5th, 2037 6:00 pm EDT - 7:00 pm EDT',
      ],
      'all day, single day' => [
        [[1818043200, 1818129540]],
        'Thursday, August 12th, 2027 (All day)',
      ],
      'all day, spanning several days' => [
        [[2098670400, 2098929540]],
        'Thursday, July 3rd, 2036 - Saturday, July 5th, 2036 (All day)',
      ],
      'recurring, stored out of chronological order' => [
        [
          [2112127200, 2112130800],
          [1875308940, 1875312540],
          [1707058800, 1707062400],
        ],
        'Sunday, February 4th, 2024 10:00 am EST - 11:00 am EST, '
        . 'Monday, June 4th, 2029 7:09 pm EDT - 8:09 pm EDT, '
        . 'Friday, December 5th, 2036 5:00 pm EST - 6:00 pm EST',
      ],
      'timed instance spanning midnight' => [
        [[2112127200, 2112156000]],
        'Friday, December 5th, 2036 5:00 pm EST - Saturday, December 6th, 2036 1:00 am EST',
      ],
      'zero duration instance' => [
        [[2112127200, 2112127200]],
        'Friday, December 5th, 2036 5:00 pm EST',
      ],
      'missing end falls back to the start' => [
        [[2112127200, 0]],
        'Friday, December 5th, 2036 5:00 pm EST',
      ],
      'instance with no start timestamp is skipped' => [
        [[0, 0]],
        '',
      ],
      'no instances at all' => [
        [],
        '',
      ],
    ];
  }

  /**
   * Tests an event whose date field is absent exports an empty cell.
   *
   * @covers ::cellValue
   */
  public function testEventDatesCellWithoutField(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('field_event_date')->willReturn(FALSE);
    $node->expects($this->never())->method('get');

    $method = new \ReflectionMethod(ContentExportBuilder::class, 'cellValue');
    $method->setAccessible(TRUE);
    $this->assertSame(
      '',
      $method->invoke(NULL, $node, 'field_event_date', $this->dateFormatter())
    );
  }

  /**
   * Tests the Resource Publication Date cell.
   *
   * The field is date-only and stored as Y-m-d, which is already the format
   * the Manage Resources column renders, so the stored value is emitted as-is
   * with no timezone conversion that could shift the date by a day.
   *
   * @param bool $has_field
   *   Whether the node has the field.
   * @param string|null $value
   *   The stored date string, or NULL for an empty field.
   * @param string $expected
   *   The expected cell output.
   *
   * @dataProvider publishDateProvider
   * @covers ::cellValue
   */
  public function testPublishDateCell(bool $has_field, ?string $value, string $expected): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('field_publish_date')->willReturn($has_field);
    if ($has_field) {
      $node->method('get')->with('field_publish_date')->willReturn((object) ['value' => $value]);
    }

    $method = new \ReflectionMethod(ContentExportBuilder::class, 'cellValue');
    $method->setAccessible(TRUE);
    $this->assertSame(
      $expected,
      $method->invoke(NULL, $node, 'field_publish_date', $this->dateFormatter())
    );
  }

  /**
   * Provides resource publication date states and expected cell output.
   *
   * @return array
   *   Cases: [has_field, stored value, expected].
   */
  public static function publishDateProvider(): array {
    return [
      'date present' => [TRUE, '2026-04-01', '2026-04-01'],
      'field empty' => [TRUE, NULL, ''],
      'field absent' => [FALSE, NULL, ''],
    ];
  }

  /**
   * Tests a date cell is sanitised like every other cell.
   *
   * Guards the AC that both new columns pass through sanitizeCell(), so a date
   * value can never be read as a spreadsheet formula.
   *
   * @covers ::getRow
   */
  public function testDateCellIsSanitised(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('label')->willReturn('A resource');
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn('/resource/a-resource');
    $node->method('toUrl')->willReturn($url);
    $node->method('isPublished')->willReturn(TRUE);
    $node->method('hasField')->willReturnMap([
      ['field_login_required', FALSE],
      ['field_publish_date', TRUE],
      ['field_tags', FALSE],
      ['field_audience', FALSE],
      ['field_custom_vocab', FALSE],
      ['field_category', FALSE],
    ]);
    // A leading "-" would otherwise be evaluated as a formula by Excel.
    $node->method('get')->with('field_publish_date')
      ->willReturn((object) ['value' => '-2026-04-01']);

    $row = ContentExportBuilder::getRow($node, 'resource', $this->dateFormatter());
    $this->assertSame("'-2026-04-01", $row[1]);
  }

  /**
   * Builds a date formatter that renders in the site timezone.
   *
   * Mirrors the platform's system.date settings: America/New_York, with
   * per-user timezones off. Unit tests get no container, so the two event date
   * formats are resolved from their known config patterns.
   *
   * @return \Drupal\Core\Datetime\DateFormatterInterface
   *   A date formatter test double.
   */
  protected function dateFormatter(): DateFormatterInterface {
    $patterns = [
      'event_date_only' => 'l, F jS, Y',
      'event_time_only' => 'g:i a T',
    ];
    $formatter = $this->createMock(DateFormatterInterface::class);
    $formatter->method('format')->willReturnCallback(
      function ($timestamp, $type = 'medium', $format = '', $timezone = NULL) use ($patterns) {
        $date = new \DateTime('@' . $timestamp);
        $date->setTimezone(new \DateTimeZone($timezone ?? 'America/New_York'));
        return $date->format($patterns[$type] ?? $format);
      }
    );
    return $formatter;
  }

  /**
   * Tests the CAS Protected cell renders the login-required flag as Yes/No.
   *
   * @param bool $has_field
   *   Whether the node has the field_login_required field.
   * @param mixed $value
   *   The stored boolean value when the field is present.
   * @param string $expected
   *   The expected cell output.
   *
   * @dataProvider casProtectedProvider
   * @covers ::cellValue
   */
  public function testCasProtectedCell(bool $has_field, $value, string $expected): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('field_login_required')->willReturn($has_field);
    if ($has_field) {
      // NodeInterface::get() has no return-type declaration, so a lightweight
      // object exposing ->value is enough to exercise the cell logic.
      $node->method('get')->with('field_login_required')->willReturn((object) ['value' => $value]);
    }

    $method = new \ReflectionMethod(ContentExportBuilder::class, 'cellValue');
    $method->setAccessible(TRUE);
    $this->assertSame($expected, $method->invoke(NULL, $node, 'cas_protected', $this->dateFormatter()));
  }

  /**
   * Tests taxonomy cells join term names with ", " (matches on-screen).
   *
   * The Manage views render multi-value taxonomy columns comma-separated, so
   * the CSV export uses the same separator instead of a semicolon.
   *
   * @covers ::cellValue
   */
  public function testTaxonomyCellJoinsTermsWithComma(): void {
    $term_a = $this->createMock(EntityInterface::class);
    $term_a->method('label')->willReturn('Alpha');
    $term_b = $this->createMock(EntityInterface::class);
    $term_b->method('label')->willReturn('Beta');

    $field_list = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $field_list->method('referencedEntities')->willReturn([$term_a, $term_b]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('field_tags')->willReturn(TRUE);
    $node->method('get')->with('field_tags')->willReturn($field_list);

    $method = new \ReflectionMethod(ContentExportBuilder::class, 'cellValue');
    $method->setAccessible(TRUE);
    $this->assertSame('Alpha, Beta', $method->invoke(NULL, $node, 'field_tags', $this->dateFormatter()));
  }

  /**
   * Provides login-required states and their expected cell output.
   *
   * @return array
   *   Cases: [has_field, value, expected].
   */
  public static function casProtectedProvider(): array {
    return [
      'protected on' => [TRUE, '1', 'Yes'],
      'protected off' => [TRUE, '0', 'No'],
      'field absent' => [FALSE, NULL, 'No'],
    ];
  }

}
