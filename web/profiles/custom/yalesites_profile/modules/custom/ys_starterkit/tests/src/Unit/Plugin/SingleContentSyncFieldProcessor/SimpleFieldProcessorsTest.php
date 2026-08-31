<?php

namespace Drupal\Tests\ys_starterkit\Unit\Plugin\SingleContentSyncFieldProcessor;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\single_content_sync\Plugin\SingleContentSyncFieldProcessor\SimpleField;
use Drupal\single_content_sync\SingleContentSyncFieldProcessorInterface;
use Drupal\ys_starterkit\Plugin\SingleContentSyncFieldProcessor\Embed;
use Drupal\ys_starterkit\Plugin\SingleContentSyncFieldProcessor\Markup;
use Drupal\ys_starterkit\Plugin\SingleContentSyncFieldProcessor\SmartDate;
use Drupal\ys_starterkit\Plugin\SingleContentSyncFieldProcessor\ViewsBasicParams;

/**
 * Unit tests for the ys_starterkit single_content_sync field processors.
 *
 * Embed, Markup, SmartDate and ViewsBasicParams add no logic of their own --
 * each is an empty subclass of single_content_sync's SimpleField, whose
 * export/import behavior is exercised here via the ys_starterkit class that
 * is actually instantiated in production.
 *
 * @group yalesites
 * @group ys_starterkit
 */
class SimpleFieldProcessorsTest extends UnitTestCase {

  /**
   * Data provider of the ys_starterkit field processor classes.
   *
   * @return array
   *   Sets of arguments for the test methods.
   */
  public static function fieldProcessorProvider(): array {
    return [
      'embed' => [Embed::class],
      'markup' => [Markup::class],
      'smartdate' => [SmartDate::class],
      'views_basic_params' => [ViewsBasicParams::class],
    ];
  }

  /**
   * Each processor is a SimpleField / SingleContentSyncFieldProcessor.
   *
   * @dataProvider fieldProcessorProvider
   */
  public function testExtendsSimpleField(string $class): void {
    $plugin = new $class([], 'test_id', []);
    $this->assertInstanceOf(SimpleField::class, $plugin);
    $this->assertInstanceOf(SingleContentSyncFieldProcessorInterface::class, $plugin);
  }

  /**
   * Tests exportFieldValue() returns the field's raw value.
   *
   * @dataProvider fieldProcessorProvider
   * @covers \Drupal\single_content_sync\Plugin\SingleContentSyncFieldProcessor\SimpleField::exportFieldValue
   */
  public function testExportFieldValueReturnsFieldValue(string $class): void {
    $plugin = new $class([], 'test_id', []);
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('getValue')->willReturn([['value' => 'foo']]);

    $this->assertSame([['value' => 'foo']], $plugin->exportFieldValue($field));
  }

  /**
   * Tests exportFieldValue() returns an empty array for an empty field.
   *
   * @dataProvider fieldProcessorProvider
   * @covers \Drupal\single_content_sync\Plugin\SingleContentSyncFieldProcessor\SimpleField::exportFieldValue
   */
  public function testExportFieldValueWithEmptyField(string $class): void {
    $plugin = new $class([], 'test_id', []);
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('getValue')->willReturn([]);

    $this->assertSame([], $plugin->exportFieldValue($field));
  }

  /**
   * Tests importFieldValue() sets the given value on the entity field.
   *
   * @dataProvider fieldProcessorProvider
   * @covers \Drupal\single_content_sync\Plugin\SingleContentSyncFieldProcessor\SimpleField::importFieldValue
   */
  public function testImportFieldValueSetsFieldOnEntity(string $class): void {
    $plugin = new $class([], 'test_id', []);
    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->expects($this->once())
      ->method('set')
      ->with('field_test', [['value' => 'bar']]);

    $plugin->importFieldValue($entity, 'field_test', [['value' => 'bar']]);
  }

  /**
   * Tests importFieldValue() sets an empty value on the entity field.
   *
   * @dataProvider fieldProcessorProvider
   * @covers \Drupal\single_content_sync\Plugin\SingleContentSyncFieldProcessor\SimpleField::importFieldValue
   */
  public function testImportFieldValueWithEmptyValue(string $class): void {
    $plugin = new $class([], 'test_id', []);
    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->expects($this->once())
      ->method('set')
      ->with('field_test', []);

    $plugin->importFieldValue($entity, 'field_test', []);
  }

}
