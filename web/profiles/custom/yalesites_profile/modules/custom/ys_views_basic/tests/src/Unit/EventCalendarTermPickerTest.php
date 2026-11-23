<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\EventCalendarDefaultWidget;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests the Event Calendar tag pickers use grouped, plain-name options.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Plugin\Field\FieldWidget\EventCalendarDefaultWidget
 *
 * @group yalesites
 */
class EventCalendarTermPickerTest extends UnitTestCase {

  /**
   * Include and exclude pickers share vocabulary-grouped options.
   *
   * @covers ::buildTermFilters
   */
  public function testTermPickersAreGroupedWithPlainNames() {
    $grouped = ['Event Category' => [1 => 'Lecture']];
    $manager = $this->createMock(ViewsBasicManager::class);
    $manager->expects($this->once())
      ->method('getTagsForVocabularies')
      ->with(['event_category', 'audience', 'custom_vocab', 'tags'])
      ->willReturn($grouped);
    $manager->expects($this->once())
      ->method('addStoredTermOptions')
      ->with($grouped, [1, 2])
      ->willReturnArgument(0);

    $widget = new EventCalendarDefaultWidget(
      'event_calendar_default_widget',
      [],
      $this->createMock(FieldDefinitionInterface::class),
      [],
      [],
      $manager,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->getConfigFactoryStub([]),
    );
    $widget->setStringTranslation($this->getStringTranslationStub());

    $item = (object) ['params' => json_encode(['terms_include' => ['1'], 'terms_exclude' => ['2']])];
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('offsetGet')->willReturn($item);

    $form = [];
    $ref = new \ReflectionMethod($widget, 'buildTermFilters');
    $ref->setAccessible(TRUE);
    $ref->invokeArgs($widget, [&$form, $items, 0]);

    foreach (['terms_include', 'terms_exclude'] as $key) {
      $this->assertSame($grouped, $form['group_user_selection']['filter_and_sort'][$key]['#options']);
    }
    $this->assertSame([1], $form['group_user_selection']['filter_and_sort']['terms_include']['#default_value']);
  }

}
