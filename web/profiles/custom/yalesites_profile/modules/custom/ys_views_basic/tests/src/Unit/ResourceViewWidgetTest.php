<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\ResourceViewWidget;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests ResourceViewWidget (#1723): the resource listing's own options.
 *
 * Every option the old ys_views_content_resources widget offered has to stay
 * selectable under the same label, so these tests pin the option keys and
 * labels, and which design options offer them, against that widget.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ResourceViewWidget
 *
 * @group yalesites
 */
class ResourceViewWidgetTest extends UnitTestCase {

  /**
   * Builds a ResourceViewWidget bound to the given bundle.
   */
  private function widget(string $bundle, ?ViewsBasicManager $manager = NULL): ResourceViewWidget {
    if ($manager === NULL) {
      // Mirrors the real manager's fallbacks for the two keys this widget
      // reads without stored params.
      $manager = $this->createMock(ViewsBasicManager::class);
      $manager->method('getDefaultParamValue')->willReturnCallback(
        fn($type) => $type === 'search_fields' ? ViewsBasicManager::RESOURCE_DEFAULT_SEARCH_FIELDS : []
      );
    }
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getTargetBundle')->willReturn($bundle);
    $widget = new ResourceViewWidget(
      'resource_view_widget',
      [],
      $field_definition,
      [],
      [],
      $manager,
      $this->createMock(EntityTypeManagerInterface::class),
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
   * Builds field items whose delta 0 carries the given params.
   */
  private function items(?string $params, bool $new = FALSE): FieldItemListInterface {
    $item = (object) ['params' => $params];
    $block = $this->createMock(EntityInterface::class);
    $block->method('isNew')->willReturn($new);
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('offsetGet')->willReturn($item);
    $items->method('getEntity')->willReturn($block);
    return $items;
  }

  /**
   * Casts an options map's labels to strings for comparison.
   */
  private function labels(array $options): array {
    return array_map('strval', $options);
  }

  /**
   * The widget reports the resource content type.
   *
   * @covers ::getContentType
   */
  public function testGetContentType() {
    $this->assertSame('resource', $this->invoke($this->widget('resource_card'), 'getContentType'));
  }

  /**
   * The category option keeps the old widget's singular label.
   *
   * @covers ::buildCategoryLabel
   */
  public function testCategoryLabel() {
    $this->assertSame('Show Category', (string) $this->invoke($this->widget('resource_card'), 'buildCategoryLabel'));
  }

  /**
   * The category filter reads the resource_category vocabulary.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::getCategoryVocabulary
   */
  public function testCategoryVocabulary() {
    $this->assertSame('resource_category', $this->invoke($this->widget('resource_card'), 'getCategoryVocabulary'));
  }

  /**
   * The tag selects cover the vocabularies the resource view filters on.
   *
   * The old widget bounded its tag list to these eight vocabularies
   * (ViewsContentResourcesManager::ALLOWED_TAG_VOCABULARIES).
   *
   * @covers ::getTagVocabularies
   */
  public function testTagVocabularies() {
    $vocabularies = $this->invoke($this->widget('resource_card'), 'getTagVocabularies');
    $this->assertSame('resource_category', $vocabularies[0], 'The category vocabulary groups first, as for every listing.');
    $expected = [
      'academic_years',
      'areas_of_study',
      'audience',
      'custom_vocab',
      'discipline',
      'geographic_areas',
      'resource_category',
      'tags',
    ];
    sort($vocabularies);
    $this->assertSame($expected, $vocabularies);
  }

  /**
   * All nine exposed filters are offered, under the old labels.
   *
   * @covers ::getExposedFilterOptions
   */
  public function testExposedFilterOptions() {
    $widget = $this->widget('resource_card');
    // The custom vocabulary label is memoized on the base; seed it so the test
    // does not need a vocabulary storage.
    $ref = new \ReflectionProperty($widget, 'customVocabularyLabel');
    $ref->setAccessible(TRUE);
    $ref->setValue($widget, 'Custom Vocab');

    $this->assertSame([
      'show_search_filter' => 'Show Search',
      'show_year_filter' => 'Show Year',
      'show_category_filter' => 'Show Category',
      'show_custom_vocab_filter' => 'Show Custom Vocab',
      'show_audience_filter' => 'Show Audience',
      'show_academic_year_filter' => 'Show Academic Year',
      'show_discipline_filter' => 'Show Discipline',
      'show_areas_of_study_filter' => 'Show Areas of Study',
      'show_geographic_areas_filter' => 'Show Geographic Areas',
    ], $this->labels($this->invoke($widget, 'getExposedFilterOptions')));

    // The shared "titles only" help text is wrong here: resource search runs
    // across whichever fields the editor picks.
    $this->assertArrayNotHasKey('show_search_filter', $this->invoke($widget, 'getExposedFilterDescriptions'));
  }

  /**
   * The search-field picker sits in the search filter's settings row.
   *
   * @covers ::buildExposedFilterAccordion
   */
  public function testSearchFieldsLiveInTheSearchFilterRow() {
    $element = [
      'exposed_filter_options' => [
        'show_search_filter' => ['#type' => 'checkbox'],
      ],
      'search_fields' => ['#type' => 'checkboxes'],
    ];
    $built = ResourceViewWidget::buildExposedFilterAccordion($element, $this->createMock(FormStateInterface::class));
    $this->assertArrayNotHasKey('search_fields', $built);
    $this->assertArrayHasKey('search_fields', $built['exposed_filter_options']['show_search_filter__row']['settings']);
  }

  /**
   * Card, portrait grid and list offer the teaser image, category and tags.
   *
   * @covers ::buildFieldDisplayOptions
   */
  public function testFieldDisplayOptionsOnDetailedDesigns() {
    foreach (['resource_card', 'resource_portrait_grid', 'resource_list_item'] as $bundle) {
      $form = [];
      $this->invoke($this->widget($bundle), 'buildFieldDisplayOptions', [&$form, $this->items(NULL), 0]);
      $options = $this->labels($form['group_user_selection']['entity_and_view_mode']['field_options']['#options']);
      $this->assertSame([
        'show_categories' => 'Show Category',
        'show_tags' => 'Show Tags',
        'show_thumbnail' => 'Show Teaser Image',
      ], $options, "$bundle field display options");
    }
  }

  /**
   * Condensed offers only the category, as the old widget did.
   *
   * The resource condensed template renders neither the tags nor an image, so
   * the old widget hid everything but "Show Category" in that design.
   *
   * @covers ::buildFieldDisplayOptions
   */
  public function testFieldDisplayOptionsOnCondensed() {
    $form = [];
    $this->invoke($this->widget('resource_condensed'), 'buildFieldDisplayOptions', [&$form, $this->items(NULL), 0]);
    $this->assertSame(
      ['show_categories' => 'Show Category'],
      $this->labels($form['group_user_selection']['entity_and_view_mode']['field_options']['#options'])
    );
  }

  /**
   * The six resource details are offered wherever a template renders them.
   *
   * @covers ::buildEntitySpecificOptions
   */
  public function testResourceFieldOptions() {
    foreach (['resource_card', 'resource_portrait_grid', 'resource_list_item'] as $bundle) {
      $form = [];
      $this->invoke($this->widget($bundle), 'buildEntitySpecificOptions', [&$form, $this->items(NULL), 0]);
      $element = $form['group_user_selection']['entity_and_view_mode']['resource_field_options'] ?? NULL;
      $this->assertIsArray($element, "$bundle offers the resource details");
      $this->assertSame([
        'show_teaser_text' => 'Show Teaser Text',
        'show_discipline' => 'Show Discipline',
        'show_journal_name' => 'Show Journal/Publication Name',
        'show_journal_issue' => 'Show Journal/Publication Issue',
        'show_authors' => 'Show Authors',
        'show_publish_date' => 'Show Publish Date',
      ], $this->labels($element['#options']));
      $this->assertSame(ViewsBasicManager::RESOURCE_FIELD_OPTIONS, array_keys($element['#options']));
    }

    $form = [];
    $this->invoke($this->widget('resource_condensed'), 'buildEntitySpecificOptions', [&$form, $this->items(NULL), 0]);
    $this->assertArrayNotHasKey('resource_field_options', $form['group_user_selection']['entity_and_view_mode']);
  }

  /**
   * A new block defaults to showing the teaser text, as the old widget did.
   *
   * @covers ::buildEntitySpecificOptions
   */
  public function testNewBlockDefaultsToTeaserText() {
    $form = [];
    $this->invoke($this->widget('resource_card'), 'buildEntitySpecificOptions', [&$form, $this->items(NULL, TRUE), 0]);
    $this->assertSame(['show_teaser_text'], $form['group_user_selection']['entity_and_view_mode']['resource_field_options']['#default_value']);

    // An existing block keeps exactly what it stored, even when that is none.
    $manager = $this->createMock(ViewsBasicManager::class);
    $manager->method('getDefaultParamValue')->willReturnMap([
      ['resource_field_options', '{}', []],
      ['search_fields', '{}', ['title' => 'title']],
    ]);
    $form = [];
    $args = [&$form, $this->items('{}'), 0];
    $this->invoke($this->widget('resource_card', $manager), 'buildEntitySpecificOptions', $args);
    $this->assertSame([], $form['group_user_selection']['entity_and_view_mode']['resource_field_options']['#default_value']);
  }

  /**
   * The search-field picker offers the fields the old widget offered.
   *
   * @covers ::buildEntitySpecificOptions
   */
  public function testSearchFieldsElement() {
    foreach (['resource_card', 'resource_condensed'] as $bundle) {
      $form = [];
      $this->invoke($this->widget($bundle), 'buildEntitySpecificOptions', [&$form, $this->items(NULL), 0]);
      $element = $form['group_user_selection']['entity_and_view_mode']['search_fields'];
      $this->assertSame([
        'title' => 'Title',
        'field_teaser_text' => 'Teaser Text',
        'field_teaser_title' => 'Teaser Title',
        'field_journal_publication_name' => 'Journal/Publication Name',
        'authors' => 'Authors',
      ], $this->labels($element['#options']), "$bundle search fields");
      $this->assertSame(['title', 'field_teaser_text', 'field_teaser_title'], $element['#default_value']);
      $this->assertSame([[ResourceViewWidget::class, 'validateSearchFields']], $element['#element_validate']);
    }
  }

  /**
   * The save path stores the resource details and the search fields.
   *
   * @covers ::massageEntitySpecificParams
   */
  public function testMassageEntitySpecificParams() {
    $form = [
      'group_user_selection' => [
        'entity_and_view_mode' => [
          'display_row' => [
            'result_content' => [
              'resource_field_options' => [
                '#input' => TRUE,
                '#value' => ['show_authors' => 'show_authors'],
              ],
            ],
          ],
          'exposed_filter_options' => [
            'show_search_filter__row' => [
              'settings' => [
                'search_fields' => [
                  '#input' => TRUE,
                  '#value' => ['title' => 'title'],
                ],
              ],
            ],
          ],
        ],
      ],
    ];
    $param_data = [];
    $args = [&$param_data, $form, $this->createMock(FormStateInterface::class)];
    $this->invoke($this->widget('resource_card'), 'massageEntitySpecificParams', $args);

    $this->assertSame(['show_authors' => 'show_authors'], $param_data['resource_field_options']);
    $this->assertSame(['title' => 'title'], $param_data['search_fields']);
  }

  /**
   * A condensed listing, which offers no resource details, stores none.
   *
   * @covers ::massageEntitySpecificParams
   */
  public function testMassageEntitySpecificParamsDefaultsToEmptyArrays() {
    $param_data = [];
    $args = [&$param_data, [], $this->createMock(FormStateInterface::class)];
    $this->invoke($this->widget('resource_condensed'), 'massageEntitySpecificParams', $args);

    $this->assertSame([], $param_data['resource_field_options']);
    $this->assertSame([], $param_data['search_fields']);
  }

  /**
   * Search enabled with no search field picked is a validation error.
   *
   * @covers ::validateSearchFields
   */
  public function testValidateSearchFieldsErrorsWhenSearchEnabledWithNoneSelected() {
    $element = ['#parents' => ['group_user_selection', 'entity_and_view_mode', 'search_fields'], '#value' => []];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')
      ->with(['group_user_selection', 'entity_and_view_mode', 'exposed_filter_options', 'show_search_filter'])
      ->willReturn(1);
    $form_state->expects($this->once())->method('setError');

    ResourceViewWidget::validateSearchFields($element, $form_state);
  }

  /**
   * At least one search field picked passes, and so does search switched off.
   *
   * @covers ::validateSearchFields
   */
  public function testValidateSearchFieldsPasses() {
    $picked = ['#parents' => ['entity_and_view_mode', 'search_fields'], '#value' => ['title' => 'title']];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->willReturn(1);
    $form_state->expects($this->never())->method('setError');
    ResourceViewWidget::validateSearchFields($picked, $form_state);

    $none = ['#parents' => ['entity_and_view_mode', 'search_fields'], '#value' => []];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->willReturn(0);
    $form_state->expects($this->never())->method('setError');
    ResourceViewWidget::validateSearchFields($none, $form_state);
  }

}
