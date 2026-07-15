<?php

namespace Drupal\Tests\ys_campus_groups\Unit;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Plugin\migrate\process\SkipOnEmpty;
use Drupal\migrate\Row;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the campus_groups_events migration against null taxonomy lookups.
 *
 * Regression coverage for yalesites-org/YaleSites-Internal#1394. A null or
 * empty source value flowing into one of the entity_generate/entity_lookup
 * steps reaches EntityLookup::query(), whose entity-query condition calls
 * Connection::escapeLike() -- which emits "addcslashes(): Passing null to
 * parameter #1" (a deprecation today, a hard TypeError on a future PHP). Each
 * affected field is guarded with a leading skip_on_empty (method: process) so
 * an empty value stops that field's pipeline before the lookup ever runs.
 *
 * @group ys_campus_groups
 * @group yalesites
 */
class CampusGroupsEventsNullGuardTest extends UnitTestCase {

  /**
   * Guarded event fields in campus_groups_events.yml keyed to their source.
   */
  const GUARDED_FIELDS = [
    'field_tags' => 'event_tags',
    'field_localist_event_type' => 'event_type',
    'field_category' => 'event_category',
    'field_localist_group' => 'event_group',
    'field_event_status' => 'event_status',
    'field_event_topics' => 'event_topics',
  ];

  /**
   * Every guarded field leads with skip_on_empty (method: process).
   *
   * This is the wiring that keeps a null value from reaching the lookup. If a
   * guard is dropped or reordered, the deprecated escapeLike(null) path becomes
   * reachable again and this test fails.
   *
   * @covers \Drupal\ys_campus_groups
   */
  public function testGuardedFieldsLeadWithSkipOnEmpty(): void {
    $migration = Yaml::parseFile(__DIR__ . '/../../../migrations/campus_groups_events.yml');
    $process = $migration['process'];

    foreach (self::GUARDED_FIELDS as $field => $source) {
      $this->assertIsArray($process[$field], "$field must be defined in the process pipeline");
      $this->assertTrue(
        array_is_list($process[$field]),
        "$field must be a sequence of steps (an unguarded single-plugin mapping is the regression)"
      );
      $first = $process[$field][0];
      $this->assertSame('skip_on_empty', $first['plugin'], "$field must guard with skip_on_empty first");
      $this->assertSame('process', $first['method'], "$field skip_on_empty must use method: process");
      $this->assertSame($source, $first['source'], "$field must guard its own source, $source");
      // A lookup/transform step must follow the guard.
      $this->assertArrayHasKey(1, $process[$field], "$field must run a step after the guard");
    }
  }

  /**
   * An empty source value stops the pipeline before the taxonomy lookup runs.
   *
   * @dataProvider emptyValues
   */
  public function testEmptyValueStopsPipelineBeforeLookup($value): void {
    $plugin = new SkipOnEmpty(['method' => 'process'], 'skip_on_empty', []);

    $result = $plugin->transform(
      $value,
      $this->createMock(MigrateExecutableInterface::class),
      new Row(),
      'field_category'
    );

    $this->assertNull($result);
    $this->assertTrue($plugin->isPipelineStopped(), 'The lookup step is skipped for an empty value.');
  }

  /**
   * A real source value passes through unchanged to the lookup.
   *
   * Confirms the guard is behavior-preserving: non-empty values are migrated
   * exactly as before.
   */
  public function testNonEmptyValuePassesThroughToLookup(): void {
    $plugin = new SkipOnEmpty(['method' => 'process'], 'skip_on_empty', []);

    $result = $plugin->transform(
      'Lecture',
      $this->createMock(MigrateExecutableInterface::class),
      new Row(),
      'field_category'
    );

    $this->assertSame('Lecture', $result);
    $this->assertFalse($plugin->isPipelineStopped(), 'A real value continues to the lookup.');
  }

  /**
   * Empty source values that would otherwise hit escapeLike(null).
   *
   * A missing XML element decodes to NULL; a present-but-empty one to ''.
   *
   * @return array<string, array<int, mixed>>
   *   Test cases.
   */
  public static function emptyValues(): array {
    return [
      'null (missing element)' => [NULL],
      'empty string (present but empty)' => [''],
    ];
  }

}
