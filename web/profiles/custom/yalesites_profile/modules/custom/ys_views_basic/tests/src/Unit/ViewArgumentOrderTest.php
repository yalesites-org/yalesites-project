<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Pins the scaffold view argument order (#1648).
 *
 * The scaffold views declare only two real contextual filters, so everything
 * ViewsBasicManager::setupView() passes beyond those is a positional side
 * channel read back by hook_views_pre_render(), hook_views_pre_view() and the
 * style plugin. That makes the order a contract between files that never
 * reference each other.
 *
 * This exists because adding an argument broke it once: `original_settings`
 * used to be the final argument, and hook_views_pre_view() recovered it with
 * `end($args)` rather than by index. Appending `profile_field_display_options`
 * after it therefore handed setupView() the wrong JSON on every exposed-filter
 * and pager AJAX request, silently dropping each listing's stored display,
 * sort, view mode and filters. PHP 8 warns rather than fatals there, so it
 * degraded into a quietly wrong listing instead of an obvious error.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\ViewsBasicManager
 *
 * @group yalesites
 */
class ViewArgumentOrderTest extends UnitTestCase {

  /**
   * The argument order every positional reader in the module depends on.
   *
   * Appending a new name here is safe. Reordering or removing one is not, and
   * failing this test is the intended way to find that out.
   */
  const EXPECTED_ORDER = [
    'type',
    'terms_include',
    'terms_exclude',
    'sort',
    'view',
    'items',
    'event_time_period',
    'offset',
    'field_display_options',
    'event_field_display_options',
    'post_field_display_options',
    'pin_settings',
    'original_settings',
    'profile_field_display_options',
  ];

  /**
   * The argument list is exactly the expected order.
   *
   * @covers ::viewArgumentIndex
   */
  public function testArgumentOrderIsPinned() {
    $this->assertSame(self::EXPECTED_ORDER, ViewsBasicManager::VIEW_ARGUMENT_ORDER);
  }

  /**
   * Every argument name resolves to its own distinct index.
   *
   * @covers ::viewArgumentIndex
   */
  public function testEveryArgumentResolvesToItsOwnIndex() {
    $indexes = [];
    foreach (self::EXPECTED_ORDER as $position => $name) {
      $indexes[] = ViewsBasicManager::viewArgumentIndex($name);
      $this->assertSame($position, ViewsBasicManager::viewArgumentIndex($name), "$name index");
    }
    $this->assertSame($indexes, array_unique($indexes), 'No two arguments share an index.');
  }

  /**
   * The params JSON is NOT the last argument, so end($args) cannot find it.
   *
   * This is the assertion that would have caught the regression: it fails the
   * moment someone reintroduces the assumption that the params JSON is the
   * final argument.
   *
   * @covers ::viewArgumentIndex
   */
  public function testOriginalSettingsIsNotTheFinalArgument() {
    $this->assertNotSame(
      count(ViewsBasicManager::VIEW_ARGUMENT_ORDER) - 1,
      ViewsBasicManager::viewArgumentIndex('original_settings'),
      'original_settings is not last, so it must be read by index, never with end($args).'
    );
  }

  /**
   * An unknown argument name throws rather than silently resolving to 0.
   *
   * @covers ::viewArgumentIndex
   */
  public function testUnknownArgumentThrows() {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown Views Basic view argument "nope".');
    ViewsBasicManager::viewArgumentIndex('nope');
  }

}
