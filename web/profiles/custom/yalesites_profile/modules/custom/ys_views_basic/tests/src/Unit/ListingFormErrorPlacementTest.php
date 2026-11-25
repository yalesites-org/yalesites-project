<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormElementHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\EventViewWidget;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\PostViewWidget;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\ResourceViewWidget;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests that a validation error on the listing form can be placed.
 *
 * Inline Form Errors places each error with
 * FormElementHelper::getElementByName(), which implodes the #parents of every
 * element it walks past. The listing widget's #after_build passes add layout
 * wrappers after FormBuilder has assigned #parents, so a wrapper without one
 * turned any validation error (an empty "Items" limit, say) into a 500
 * ("implode(): Argument #1 ($array) must be of type array, string given").
 *
 * The form is built from the widget's real build methods, given #parents the
 * way FormBuilder::doBuildForm() assigns them, and then run through the
 * #after_build callbacks the widget registered, so a new wrapper added to any
 * of those passes is covered too.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase
 *
 * @group ys_views_basic
 * @group yalesites
 */
class ListingFormErrorPlacementTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Builds a listing widget of the given class bound to the given bundle.
   */
  private function widget(string $class, string $bundle): ViewsBasicWidgetBase {
    $vocabulary = $this->createMock('Drupal\taxonomy\VocabularyInterface');
    $vocabulary->method('label')->willReturn('Custom Vocab');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($vocabulary);
    $storage->method('loadMultiple')->willReturn([]);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturn($storage);

    $manager = $this->createMock(ViewsBasicManager::class);
    $manager->method('getTaxonomyParents')->willReturn(['' => 'All']);
    $manager->method('sortByList')->willReturn(['date' => 'Date']);
    // A new block: ResourceViewWidget reads search_fields' default as a list.
    $manager->method('getDefaultParamValue')->willReturnCallback(
      fn ($type) => $type === 'search_fields' ? ViewsBasicManager::RESOURCE_DEFAULT_SEARCH_FIELDS : NULL
    );

    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getTargetBundle')->willReturn($bundle);

    $widget = new $class(
      'widget',
      [],
      $field_definition,
      [],
      [],
      $manager,
      $entity_type_manager,
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
   * Assigns #parents the way FormBuilder::doBuildForm() does.
   */
  private function assignParents(array &$element): void {
    $element += ['#tree' => FALSE, '#parents' => []];
    foreach (Element::children($element) as $key) {
      $child = &$element[$key];
      if (!isset($child['#tree'])) {
        $child['#tree'] = $element['#tree'];
      }
      if (!isset($child['#parents'])) {
        $child['#parents'] = $child['#tree'] && $element['#tree'] ? array_merge($element['#parents'], [$key]) : [$key];
      }
      $this->assignParents($child);
      unset($child);
    }
  }

  /**
   * Builds the listing form, then runs the registered #after_build passes.
   */
  private function builtForm(string $class, string $bundle): array {
    $item = (object) ['params' => NULL];
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('offsetGet')->willReturn($item);
    $items->method('getEntity')->willReturn(NULL);
    $selectors = [
      'display_ajax' => ':input[name="display"]',
      'show_category_filter_selector' => ':input[name="category"]',
      'show_custom_vocab_filter_selector' => ':input[name="custom_vocab"]',
      'show_search_filter_selector' => ':input[name="search"]',
      'pinned_to_top_selector' => ':input[name="pinned"]',
    ];

    $widget = $this->widget($class, $bundle);
    $form = ['#form_selectors' => $selectors];
    $this->invoke($widget, 'initSelectionContainers', [&$form]);
    $this->invoke($widget, 'buildFieldDisplayOptions', [&$form, $items, 0]);
    $this->invoke($widget, 'buildEntitySpecificOptions', [&$form, $items, 0]);
    $this->invoke($widget, 'buildExposedFilterControls', [&$form, $items, 0, $selectors]);
    $this->invoke($widget, 'buildTermIncludeExclude', [&$form, $items, 0]);
    $this->invoke($widget, 'buildSortControl', [&$form, $items, 0]);
    $this->invoke($widget, 'buildPinnedControls', [&$form, $items, 0, $selectors]);
    $this->invoke($widget, 'buildDisplayControls', [&$form, $items, 0, $selectors]);

    // Nested under the block form as on the Layout Builder configure form.
    $complete = [
      '#tree' => TRUE,
      'settings' => ['block_form' => $form],
    ];
    $this->assignParents($complete);

    $form_state = $this->createMock(FormStateInterface::class);
    foreach (Element::children($complete['settings']['block_form']['group_user_selection']) as $group) {
      $element = &$complete['settings']['block_form']['group_user_selection'][$group];
      foreach ($element['#after_build'] ?? [] as $callback) {
        $element = call_user_func($callback, $element, $form_state);
      }
      unset($element);
    }
    return $complete;
  }

  /**
   * Collects the path of every child whose #parents is not an array.
   */
  private function badParents(array $element, array $path = []): array {
    $bad = [];
    foreach (Element::children($element) as $key) {
      $child_path = array_merge($path, [$key]);
      if (!is_array($element[$key]['#parents'] ?? NULL)) {
        $bad[] = implode('/', $child_path);
      }
      $bad = array_merge($bad, $this->badParents($element[$key], $child_path));
    }
    return $bad;
  }

  /**
   * Data provider: each listing widget with its own after-build wrappers.
   */
  public static function widgetProvider(): array {
    return [
      'post card' => [PostViewWidget::class, 'post_card'],
      'event card' => [EventViewWidget::class, 'event_card'],
      'resource card' => [ResourceViewWidget::class, 'resource_card'],
    ];
  }

  /**
   * Every element left by the #after_build passes has array #parents.
   *
   * @covers ::buildExposedFilterAccordion
   * @covers ::groupFieldDisplayRow
   * @covers ::groupTermOperatorRows
   * @covers ::groupPinnedRow
   *
   * @dataProvider widgetProvider
   */
  public function testEveryElementHasArrayParents(string $class, string $bundle) {
    $form = $this->builtForm($class, $bundle);

    $this->assertSame([], $this->badParents($form));
  }

  /**
   * Inline Form Errors can find the limit field to attach its error to.
   *
   * @dataProvider widgetProvider
   */
  public function testLimitErrorCanBePlaced(string $class, string $bundle) {
    $form = $this->builtForm($class, $bundle);
    $name = 'settings][block_form][group_user_selection][options][limit';

    $element = FormElementHelper::getElementByName($name, $form);

    $this->assertSame('number', $element['#type'] ?? NULL);
    // A name nothing carries walks the whole form without failing.
    $this->assertSame([], FormElementHelper::getElementByName('no][such][element', $form));
  }

}
