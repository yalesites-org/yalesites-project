<?php

namespace Drupal\Tests\ys_content_export\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_content_export\ContentExportBuilder;

/**
 * Unit tests for ContentExportBuilder.
 *
 * @coversDefaultClass \Drupal\ys_content_export\ContentExportBuilder
 * @group ys_content_export
 * @group yalesites
 */
class ContentExportBuilderTest extends UnitTestCase {

  /**
   * Tests that sanitizeCell neutralises CSV formula injection.
   *
   * @param string $value
   *   The raw cell value.
   * @param string $expected
   *   The expected safe value.
   *
   * @dataProvider sanitizeProvider
   * @covers ::sanitizeCell
   */
  public function testSanitizeCell(string $value, string $expected): void {
    $this->assertSame($expected, ContentExportBuilder::sanitizeCell($value));
  }

  /**
   * Provides cell values and their expected sanitised form.
   *
   * @return array
   *   Cases: [value, expected].
   */
  public static function sanitizeProvider(): array {
    return [
      'equals formula' => ['=1+1', "'=1+1"],
      'plus formula' => ['+1', "'+1"],
      'minus formula' => ['-1', "'-1"],
      'at formula' => ['@SUM(A1)', "'@SUM(A1)"],
      'tab prefix' => ["\tvalue", "'\tvalue"],
      'carriage return prefix' => ["\rvalue", "'\rvalue"],
      'ordinary text' => ['Hello world', 'Hello world'],
      'internal equals safe' => ['a=b', 'a=b'],
      'empty' => ['', ''],
    ];
  }

  /**
   * Tests the column map for each bundle.
   *
   * @covers ::getColumns
   */
  public function testGetColumns(): void {
    $page = ContentExportBuilder::getColumns('page');
    $this->assertSame(
      [
        'title',
        'url',
        'published',
        'cas_protected',
        'field_tags',
        'field_audience',
        'field_custom_vocab',
        'field_category',
      ],
      array_keys($page)
    );
    $this->assertSame('Title', $page['title']);
    $this->assertSame('URL', $page['url']);
    $this->assertSame('Published', $page['published']);
    $this->assertSame('CAS Protected', $page['cas_protected']);
    $this->assertSame('Category', $page['field_category']);

    $this->assertSame('Event Category', ContentExportBuilder::getColumns('event')['field_category']);
    $this->assertSame('Resource Category', ContentExportBuilder::getColumns('resource')['field_category']);

    $profile = ContentExportBuilder::getColumns('profile');
    $this->assertArrayHasKey('field_affiliation', $profile);
    $this->assertSame('Affiliation', $profile['field_affiliation']);
    $this->assertArrayNotHasKey('field_category', $profile);

    // Shared taxonomy columns appear on every bundle.
    foreach (['page', 'post', 'event', 'profile', 'resource'] as $bundle) {
      $columns = ContentExportBuilder::getColumns($bundle);
      $this->assertArrayHasKey('field_tags', $columns);
      $this->assertArrayHasKey('field_audience', $columns);
      $this->assertArrayHasKey('field_custom_vocab', $columns);
    }
  }

  /**
   * Tests the CAS Protected cell renders the login-required flag as Yes/No.
   *
   * @param bool $has_field
   *   Whether the node has the field_login_required field.
   * @param mixed $value
   *   The stored boolean value when the field is present.
   * @param string $expected
   *   The expected cell output.
   *
   * @dataProvider casProtectedProvider
   * @covers ::cellValue
   */
  public function testCasProtectedCell(bool $has_field, $value, string $expected): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('field_login_required')->willReturn($has_field);
    if ($has_field) {
      // NodeInterface::get() has no return-type declaration, so a lightweight
      // object exposing ->value is enough to exercise the cell logic.
      $node->method('get')->with('field_login_required')->willReturn((object) ['value' => $value]);
    }

    $method = new \ReflectionMethod(ContentExportBuilder::class, 'cellValue');
    $method->setAccessible(TRUE);
    $this->assertSame($expected, $method->invoke(NULL, $node, 'cas_protected'));
  }

  /**
   * Tests taxonomy cells join term names with ", " (matches on-screen).
   *
   * The Manage views render multi-value taxonomy columns comma-separated, so
   * the CSV export uses the same separator instead of a semicolon.
   *
   * @covers ::cellValue
   */
  public function testTaxonomyCellJoinsTermsWithComma(): void {
    $term_a = $this->createMock(EntityInterface::class);
    $term_a->method('label')->willReturn('Alpha');
    $term_b = $this->createMock(EntityInterface::class);
    $term_b->method('label')->willReturn('Beta');

    $field_list = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $field_list->method('referencedEntities')->willReturn([$term_a, $term_b]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('field_tags')->willReturn(TRUE);
    $node->method('get')->with('field_tags')->willReturn($field_list);

    $method = new \ReflectionMethod(ContentExportBuilder::class, 'cellValue');
    $method->setAccessible(TRUE);
    $this->assertSame('Alpha, Beta', $method->invoke(NULL, $node, 'field_tags'));
  }

  /**
   * Provides login-required states and their expected cell output.
   *
   * @return array
   *   Cases: [has_field, value, expected].
   */
  public static function casProtectedProvider(): array {
    return [
      'protected on' => [TRUE, '1', 'Yes'],
      'protected off' => [TRUE, '0', 'No'],
      'field absent' => [FALSE, NULL, 'No'],
    ];
  }

}
