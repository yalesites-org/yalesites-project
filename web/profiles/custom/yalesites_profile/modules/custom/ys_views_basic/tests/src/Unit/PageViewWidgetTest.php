<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\PageViewWidget;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests PageViewWidget (#1166): the simplest content type, no extra controls.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Plugin\Field\FieldWidget\PageViewWidget
 *
 * @group yalesites
 */
class PageViewWidgetTest extends UnitTestCase {

  /**
   * Builds a PageViewWidget bound to the given bundle.
   */
  private function widget(string $bundle): PageViewWidget {
    $vocabulary = $this->createMock('Drupal\taxonomy\VocabularyInterface');
    $vocabulary->method('label')->willReturn('Custom Vocab');
    $vocab_storage = $this->createMock(EntityStorageInterface::class);
    $vocab_storage->method('load')->willReturn($vocabulary);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturn($vocab_storage);

    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getTargetBundle')->willReturn($bundle);
    $widget = new PageViewWidget(
      'page_view_widget',
      [],
      $field_definition,
      [],
      [],
      $this->createMock(ViewsBasicManager::class),
      $entity_type_manager,
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
   * The widget reports the page content type and bundle-driven view modes.
   *
   * @covers ::getContentType
   */
  public function testContentTypeAndViewMode() {
    $this->assertSame('page', $this->invoke($this->widget('page_card'), 'getContentType'));
    $this->assertSame('list_item', $this->invoke($this->widget('page_list_item'), 'getViewMode'));
    $this->assertSame('condensed', $this->invoke($this->widget('page_condensed'), 'getViewMode'));
  }

  /**
   * Pages add no content-type-specific form controls.
   *
   * @covers ::buildEntitySpecificOptions
   */
  public function testNoEntitySpecificControls() {
    $items = $this->createMock(FieldItemListInterface::class);
    $form = ['existing' => TRUE];
    $this->invoke($this->widget('page_card'), 'buildEntitySpecificOptions', [&$form, $items, 0]);
    $this->assertSame(['existing' => TRUE], $form, 'buildEntitySpecificOptions adds nothing for pages.');
  }

  /**
   * Pages do not expose the post-only year filter.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::getExposedFilterOptions
   */
  public function testExposedFilterOptionsExcludeYear() {
    $options = $this->invoke($this->widget('page_card'), 'getExposedFilterOptions');
    $this->assertArrayNotHasKey('show_year_filter', $options);
    $this->assertArrayHasKey('show_category_filter', $options);
  }

}
