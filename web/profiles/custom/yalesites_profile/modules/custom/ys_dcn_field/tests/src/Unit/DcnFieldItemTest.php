<?php

namespace Drupal\Tests\ys_dcn_field\Unit;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_dcn_field\Plugin\Field\FieldType\DcnFieldItem;

/**
 * Unit tests for the DcnFieldItem field type static methods.
 *
 * @coversDefaultClass \Drupal\ys_dcn_field\Plugin\Field\FieldType\DcnFieldItem
 *
 * @group yalesites
 * @group ys_dcn_field
 */
class DcnFieldItemTest extends UnitTestCase {

  /**
   * Tests the database schema definition.
   *
   * @covers ::schema
   */
  public function testSchema() {
    $field_definition = $this->createMock(FieldStorageDefinitionInterface::class);
    $schema = DcnFieldItem::schema($field_definition);

    // Two columns: a taxonomy target ID and a text identifier.
    $this->assertArrayHasKey('columns', $schema);
    $this->assertArrayHasKey('dcn_type_target_id', $schema['columns']);
    $this->assertArrayHasKey('dcn_identifier', $schema['columns']);

    // The taxonomy target ID is an unsigned integer.
    $this->assertSame('int', $schema['columns']['dcn_type_target_id']['type']);
    $this->assertTrue($schema['columns']['dcn_type_target_id']['unsigned']);

    // The identifier is a 255 character varchar.
    $this->assertSame('varchar', $schema['columns']['dcn_identifier']['type']);
    $this->assertSame(255, $schema['columns']['dcn_identifier']['length']);

    // An index and a foreign key are defined on the taxonomy target ID.
    $this->assertSame(
      ['dcn_type_target_id'],
      $schema['indexes']['dcn_type_target_id']
    );
    $this->assertSame(
      'taxonomy_term_data',
      $schema['foreign keys']['dcn_type_target_id']['table']
    );
    $this->assertSame(
      ['dcn_type_target_id' => 'tid'],
      $schema['foreign keys']['dcn_type_target_id']['columns']
    );
  }

  /**
   * Tests the default field settings.
   *
   * @covers ::defaultFieldSettings
   */
  public function testDefaultFieldSettings() {
    $settings = DcnFieldItem::defaultFieldSettings();
    $this->assertSame('dcn_types', $settings['dcn_type_vocabulary']);
  }

}
