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
      $this->getConfigFactoryStub(['ys_core.site' => ['font_pairing' => 'yalenew']]),
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

    $time_period = $form['group_user_selection']['options']['event_time_period'] ?? NULL;
    $this->assertIsArray($time_period);
    $this->assertSame('future', $time_period['#default_value']);
    $this->assertArrayHasKey('past', $time_period['#options']);
    $this->assertArrayHasKey('all', $time_period['#options']);
  }

  /**
   * The term selects are scoped to this widget's vocabularies (#1316).
   *
   * They also carry no leftover entity_autocomplete keys from before Chosen.
   *
   * @covers ::buildTermIncludeExclude
   */
  public function testTermSelectsAreVocabularyScopedChosenSelects() {
    $grouped = ['Event Category' => [1 => 'Lecture']];
    $this->manager->expects($this->once())
      ->method('getTagsForVocabularies')
      ->with(['event_category', 'tags', 'audience', 'custom_vocab'])
      ->willReturn($grouped);

    $item = (object) ['params' => NULL];
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('offsetGet')->willReturn($item);

    $form = [];
    $this->invoke($this->widget('event_card'), 'buildTermIncludeExclude', [&$form, $items, 0]);

    foreach (['terms_include', 'terms_exclude'] as $key) {
      $element = $form['group_user_selection']['filter_and_sort'][$key];
      $this->assertSame($grouped, $element['#options']);
      $this->assertTrue($element['#chosen']);
      $this->assertArrayNotHasKey('#tags', $element);
      $this->assertArrayNotHasKey('#target_type', $element);
    }
  }

  /**
   * Each term list gets its own always-rendered, disable-when-empty operator.
   *
   * Replaces the old single shared term_operator: include_operator and
   * exclude_operator are independent, short "Any"/"All" segments (not the
   * icon-card class, and not full-sentence labels — the sentence now wraps
   * the control via #prefix/#suffix instead), always rendered rather than
   * hidden, disabled when their paired select has no terms yet (there are
   * none when $params is NULL), and default to "+" (any/OR) when no block
   * params exist yet.
   *
   * Disabling is a plain #attributes flag, not Form API's #disabled — see
   * buildTermOperator()'s docblock for why using #disabled here would
   * silently discard whatever a user picks in the JS-enabled control on
   * every new block's first save.
   *
   * @covers ::buildTermIncludeExclude
   * @covers ::buildTermOperator
   */
  public function testTermOperatorsAreIndependentDisabledWhenEmptyAndDefaultToOr() {
    $item = (object) ['params' => NULL];
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('offsetGet')->willReturn($item);

    $form = [];
    $this->invoke($this->widget('event_card'), 'buildTermIncludeExclude', [&$form, $items, 0]);

    $filter_and_sort = $form['group_user_selection']['filter_and_sort'];

    foreach (['include' => 'Show', 'exclude' => 'Hide'] as $variant => $verb) {
      $wrapper = $filter_and_sort["{$variant}_operator_wrapper"];
      $this->assertSame('container', $wrapper['#type']);
      $this->assertContains("vb-term-operator--$variant", $wrapper['#attributes']['class']);

      $radios = $wrapper["{$variant}_operator"];
      $this->assertSame('radios', $radios['#type']);
      $this->assertSame('+', $radios['#default_value'], 'Defaults to "any" (OR).');
      $this->assertArrayNotHasKey('#disabled', $radios, "$variant operator never uses Form API #disabled.");
      $this->assertSame('disabled', $radios['#attributes']['disabled'], "$variant operator starts disabled: no terms selected yet.");
      $this->assertSame('Any', (string) $radios['#options']['+']);
      $this->assertSame('All', (string) $radios['#options'][',']);
      $this->assertSame('invisible', $radios['#title_display']);
      $this->assertStringContainsString("$verb content tagged with", (string) $radios['#title']);
      $this->assertStringContainsString('of these', (string) $radios['#suffix']);
    }

    // Include and exclude are independent elements, not the same shared
    // control the old single term_operator was.
    $this->assertNotSame(
      $filter_and_sort['include_operator_wrapper'],
      $filter_and_sort['exclude_operator_wrapper']
    );
  }

  /**
   * An operator is enabled, not disabled, once its list has terms (#1316).
   *
   * The initial disabled state has to come from the stored params, not just
   * always default to disabled, so an edited block with existing terms
   * doesn't render its operator disabled on first paint before JS attaches.
   * setUp()'s manager mock stubs getDefaultParamValue() to blanket-return
   * [] regardless of args, which can't distinguish terms_include from
   * terms_exclude, so this test reconfigures its own manager mock with a
   * map instead of reusing that shared stub.
   *
   * @covers ::buildTermIncludeExclude
   */
  public function testTermOperatorEnabledWhenTermsAlreadyPresent() {
    $this->manager = $this->createMock(ViewsBasicManager::class);
    $this->manager->method('getDefaultParamValue')
      ->willReturnMap([
        ['terms_include', 'irrelevant', [1]],
        ['terms_exclude', 'irrelevant', []],
        ['include_operator', 'irrelevant', '+'],
        ['exclude_operator', 'irrelevant', '+'],
      ]);

    $item = (object) ['params' => 'irrelevant'];
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('offsetGet')->willReturn($item);

    $form = [];
    $this->invoke($this->widget('event_card'), 'buildTermIncludeExclude', [&$form, $items, 0]);

    $filter_and_sort = $form['group_user_selection']['filter_and_sort'];
    $this->assertArrayNotHasKey(
      'disabled',
      $filter_and_sort['include_operator_wrapper']['include_operator']['#attributes'],
      'Include operator is enabled: terms_include already has a term.'
    );
    $this->assertSame(
      'disabled',
      $filter_and_sort['exclude_operator_wrapper']['exclude_operator']['#attributes']['disabled'],
      'Exclude operator stays disabled: terms_exclude is empty.'
    );
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
        'options' => [
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
