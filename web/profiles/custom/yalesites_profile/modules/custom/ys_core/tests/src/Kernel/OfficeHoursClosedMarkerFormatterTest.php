<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Tests\ys_core\Traits\ReadsProfileConfigTrait;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Tests that no Office Hours formatter prints the Closed control's marker.
 *
 * The Closed control (js/office-hours-widget.js) stores a reserved value in
 * the day's comment. Atomic's Office Hours block template drops it, but any
 * other render of the field (a View, another display, a markdown feed) goes
 * through the contrib formatters, which would print it as the day's note.
 *
 * @group ys_core
 */
class OfficeHoursClosedMarkerFormatterTest extends YsKernelTestBase {

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
    'ys_core',
  ];

  /**
   * The marker the widget writes. Must match CLOSED_MARKER in the JS.
   */
  const MARKER = '__ys_office_hours_closed__';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('user');

    FieldStorageConfig::create([
      'field_name' => 'field_office_hours',
      'entity_type' => 'entity_test',
      'type' => 'office_hours',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
      'settings' => $this->readConfig('field.storage.block_content.field_office_hours')['settings'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_office_hours',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ])->save();
  }

  /**
   * Neither the list nor the table formatter prints the marker.
   *
   * An editor's own note on the same day still prints.
   */
  public function testFormattersNeverPrintTheMarker(): void {
    $entity = EntityTest::create([
      'field_office_hours' => [
        ['day' => 1, 'starthours' => NULL, 'endhours' => NULL, 'comment' => self::MARKER],
        ['day' => 2, 'starthours' => 900, 'endhours' => 1700, 'comment' => self::MARKER],
        ['day' => 2, 'starthours' => 1800, 'endhours' => 1900, 'comment' => 'By appointment'],
      ],
    ]);
    $settings = $this->readConfig('core.entity_view_display.block_content.office_hours.default')['content']['field_office_hours']['settings'];

    foreach (['office_hours', 'office_hours_table'] as $formatter) {
      $build = $entity->get('field_office_hours')->view([
        'type' => $formatter,
        'label' => 'hidden',
        'settings' => $settings,
      ]);
      $html = (string) $this->container->get('renderer')->renderRoot($build);

      $this->assertStringNotContainsString(self::MARKER, $html, "$formatter printed the marker");
      $this->assertStringContainsString('By appointment', $html, "$formatter dropped the editor's note");
    }
  }

}
