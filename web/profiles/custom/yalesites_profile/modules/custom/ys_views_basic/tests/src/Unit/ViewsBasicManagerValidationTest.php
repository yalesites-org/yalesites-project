<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Unit tests for the stored-parameter allowlisting helpers.
 *
 * ViewsBasicManager::setupView() reads its parameters from a JSON string.
 * These helpers restrict the content types, sort, and view mode it uses to
 * the values listed in ViewsBasicManager::ALLOWED_ENTITIES.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\ViewsBasicManager
 * @group ys_views_basic
 * @group yalesites
 */
class ViewsBasicManagerValidationTest extends UnitTestCase {

  /**
   * Known content types are kept in order; unknown ones are dropped.
   *
   * @covers ::getValidatedTypes
   */
  public function testUnknownTypesAreDropped() {
    $this->assertSame(['post', 'event'], ViewsBasicManager::getValidatedTypes(['post', 'not_a_type', 'event']));
  }

  /**
   * Non-string entries and non-array input yield no types.
   *
   * @covers ::getValidatedTypes
   */
  public function testNonStringTypesAreDropped() {
    $this->assertSame(['page'], ViewsBasicManager::getValidatedTypes([['post'], 7, NULL, 'page']));
    $this->assertSame([], ViewsBasicManager::getValidatedTypes('post'));
    $this->assertSame([], ViewsBasicManager::getValidatedTypes(NULL));
  }

  /**
   * A sort listed for the selected type is kept unchanged.
   *
   * @covers ::getValidatedSort
   */
  public function testValidSortIsKept() {
    $this->assertSame('field_publish_date:ASC', ViewsBasicManager::getValidatedSort(['post'], 'field_publish_date:ASC'));
    $this->assertSame('title:DESC', ViewsBasicManager::getValidatedSort(['page'], 'title:DESC'));
    $this->assertSame('field_last_name:DESC', ViewsBasicManager::getValidatedSort(['profile'], 'field_last_name:DESC'));
  }

  /**
   * An unknown sort falls back to the first sort listed for the type.
   *
   * @covers ::getValidatedSort
   */
  public function testUnknownSortFallsBackToDefault() {
    $this->assertSame('field_publish_date:DESC', ViewsBasicManager::getValidatedSort(['post'], 'not_a_field:ASC'));
    $this->assertSame('title:ASC', ViewsBasicManager::getValidatedSort(['page'], '(1):ASC'));
    $this->assertSame('field_event_date:DESC', ViewsBasicManager::getValidatedSort(['event'], 'node_field_data.nid:ASC'));
  }

  /**
   * A sort without a direction separator falls back to the default.
   *
   * @covers ::getValidatedSort
   */
  public function testSortWithoutDirectionFallsBackToDefault() {
    $this->assertSame('title:ASC', ViewsBasicManager::getValidatedSort(['page'], 'title'));
  }

  /**
   * A sort with an unsupported direction falls back to the default.
   *
   * @covers ::getValidatedSort
   */
  public function testSortWithUnknownDirectionFallsBackToDefault() {
    $this->assertSame('field_publish_date:DESC', ViewsBasicManager::getValidatedSort(['post'], 'field_publish_date:SIDEWAYS'));
  }

  /**
   * A sort listed only for a different type falls back to the default.
   *
   * @covers ::getValidatedSort
   */
  public function testSortFromAnotherTypeFallsBackToDefault() {
    $this->assertSame('field_publish_date:DESC', ViewsBasicManager::getValidatedSort(['post'], 'title:ASC'));
  }

  /**
   * Missing or non-string sorts fall back to the default.
   *
   * @covers ::getValidatedSort
   */
  public function testMissingSortFallsBackToDefault() {
    $this->assertSame('field_last_name:ASC', ViewsBasicManager::getValidatedSort(['profile'], NULL));
    $this->assertSame('field_last_name:ASC', ViewsBasicManager::getValidatedSort(['profile'], ['title:ASC']));
  }

  /**
   * With several types, a sort valid for any of them is kept.
   *
   * @covers ::getValidatedSort
   */
  public function testSortValidForAnySelectedTypeIsKept() {
    $this->assertSame('field_event_date:ASC', ViewsBasicManager::getValidatedSort(['post', 'event'], 'field_event_date:ASC'));
    $this->assertSame('field_publish_date:DESC', ViewsBasicManager::getValidatedSort(['post', 'event'], 'title:ASC'));
  }

  /**
   * Unknown types are ignored; with no known type there is no sort.
   *
   * @covers ::getValidatedSort
   */
  public function testSortWithNoKnownTypeIsEmpty() {
    $this->assertSame('title:ASC', ViewsBasicManager::getValidatedSort(['not_a_type', 'page'], 'not_a_field:ASC'));
    $this->assertSame('', ViewsBasicManager::getValidatedSort(['not_a_type'], 'title:ASC'));
  }

  /**
   * A view mode listed for the type is kept; others fall back to the first.
   *
   * @covers ::getValidatedViewMode
   */
  public function testUnknownViewModeFallsBackToFirst() {
    $this->assertSame('list_item', ViewsBasicManager::getValidatedViewMode(['post'], 'list_item'));
    $this->assertSame('directory', ViewsBasicManager::getValidatedViewMode(['profile'], 'directory'));
    $this->assertSame('card', ViewsBasicManager::getValidatedViewMode(['post'], 'directory'));
    $this->assertSame('card', ViewsBasicManager::getValidatedViewMode(['post'], 'not_a_view_mode'));
    $this->assertSame('card', ViewsBasicManager::getValidatedViewMode(['post'], NULL));
    $this->assertSame('', ViewsBasicManager::getValidatedViewMode(['not_a_type'], 'card'));
  }

  /**
   * The sortable field list is built from every type's sort options.
   *
   * @covers ::getAllowedSortFields
   */
  public function testAllowedSortFieldsCoverEveryType() {
    $fields = ViewsBasicManager::getAllowedSortFields();
    sort($fields);
    $this->assertSame(['field_event_date', 'field_last_name', 'field_publish_date', 'title'], $fields);
  }

}
