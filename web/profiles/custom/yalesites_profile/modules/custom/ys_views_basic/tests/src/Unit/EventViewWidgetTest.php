<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\EventViewWidget;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests EventViewWidget's event-specific form contributions (#1165).
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Plugin\Field\FieldWidget\EventViewWidget
 *
 * @group yalesites
 */
class EventViewWidgetTest extends UnitTestCase {

  /**
   * The mocked views basic manager.
   *
   * @var \Drupal\ys_views_basic\ViewsBasicManager
   */
  protected $manager;

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->manager = $this->createMock(ViewsBasicManager::class);
    $this->manager->method('getDefaultParamValue')->willReturn([]);

    $vocabulary = $this->createMock('Drupal\taxonomy\VocabularyInterface');
    $vocabulary->method('label')->willReturn('Custom Vocab');
    $vocab_storage = $this->createMock(EntityStorageInterface::class);
    $vocab_storage->method('load')->willReturn($vocabulary);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')->willReturn($vocab_storage);
  }

  /**
   * Builds an EventViewWidget bound to the given bundle.
   */
  private function widget(string $bundle): EventViewWidget {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getTargetBundle')->willReturn($bundle);
    $widget = new EventViewWidget(
      'event_view_widget',
      [],
      $field_definition,
      [],
      [],
      $this->manager,
      $this->entityTypeManager,
    );
    $widget->setStringTranslation($this->getStringTranslationStub());
    return $widget;
  }

  /**
   * Invokes a protected method on the widget.
   */
  private function invoke(object $object, string $method, array $args = []) {
    $ref = new \ReflectionMethod($object, $method);
    $ref->setAccessible(TRUE);
    return $ref->invokeArgs($object, $args);
  }

  /**
   * The widget reports the event content type.
   *
   * @covers ::getContentType
   */
  public function testGetContentType() {
    $this->assertSame('event', $this->invoke($this->widget('event_card'), 'getContentType'));
  }

  /**
   * Events do NOT expose the post-only year filter.
   *
   * @covers ::getExposedFilterOptions
   */
  public function testExposedFilterOptionsExcludeYear() {
    $options = $this->invoke($this->widget('event_card'), 'getExposedFilterOptions');
    $this->assertArrayNotHasKey('show_year_filter', $options, 'The year filter is post-only.');
    $this->assertArrayHasKey('show_category_filter', $options);
    $this->assertArrayHasKey('show_audience_filter', $options);
  }

  /**
   * The event field options and time period are added with no #states gating.
   *
   * @covers ::buildEntitySpecificOptions
   */
  public function testBuildEntitySpecificOptions() {
    $item = (object) ['params' => NULL];
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('offsetGet')->willReturn($item);

    $form = [];
    $this->invoke($this->widget('event_card'), 'buildEntitySpecificOptions', [&$form, $items, 0]);

    $event_options = $form['group_user_selection']['entity_and_view_mode']['event_field_options'] ?? NULL;
    $this->assertIsArray($event_options);
    $this->assertArrayHasKey('hide_add_to_calendar', $event_options['#options']);

    $time_period = $form['group_user_selection']['entity_specific']['event_time_period'] ?? NULL;
    $this->assertIsArray($time_period);
    $this->assertSame('future', $time_period['#default_value']);
    $this->assertArrayHasKey('past', $time_period['#options']);
    $this->assertArrayHasKey('all', $time_period['#options']);
  }

  /**
   * The save path stores event_field_options and the event time period.
   *
   * @covers ::massageEntitySpecificParams
   */
  public function testMassageEntitySpecificParams() {
    $form = [
      'group_user_selection' => [
        'entity_and_view_mode' => [
          'event_field_options' => ['#value' => ['hide_add_to_calendar' => 'hide_add_to_calendar']],
        ],
        'entity_specific' => [
          'event_time_period' => ['#value' => 'past'],
        ],
      ],
    ];
    $param_data = ['filters' => ['types' => ['event']]];
    $form_state = $this->createMock(FormStateInterface::class);
    $this->invoke($this->widget('event_card'), 'massageEntitySpecificParams', [&$param_data, $form, $form_state]);

    $this->assertSame(['hide_add_to_calendar' => 'hide_add_to_calendar'], $param_data['event_field_options']);
    $this->assertSame('past', $param_data['filters']['event_time_period']);
  }

}
