<?php

namespace Drupal\Tests\ys_content_export\Unit;

use Drupal\Tests\UnitTestCase;
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
      ['title', 'url', 'published', 'field_tags', 'field_audience', 'field_custom_vocab', 'field_category'],
      array_keys($page)
    );
    $this->assertSame('Title', $page['title']);
    $this->assertSame('URL', $page['url']);
    $this->assertSame('Published', $page['published']);
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

}
