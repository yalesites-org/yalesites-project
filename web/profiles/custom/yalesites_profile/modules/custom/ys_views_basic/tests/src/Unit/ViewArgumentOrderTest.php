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

  /**
   * The order content_resources packs, which is NOT this module's order.
   *
   * Copied from ViewsContentResourcesManager::setupView()'s $view_args literal
   * (ys_views_content_resources, ~line 410) because it is a local array in
   * another module with no constant to reference. Restated here rather than
   * left implicit: the style plugin, the pager and the sort are shared between
   * the two views, so this list is half of the contract every positional read
   * in this module is subject to.
   *
   * If this drifts from the real one, the tests below stop describing reality
   * — so it is deliberately spelled out where a reader of this module will
   * trip over it.
   */
  const CONTENT_RESOURCES_ORDER = [
    'type',
    'terms_include',
    'terms_exclude',
    'sort',
    'view',
    'items',
    'offset',
    'field_display_options',
    'pin_settings',
    'original_settings',
  ];

  /**
   * The two views agree up to 'items' and diverge immediately after.
   *
   * This is the whole reason ::SCAFFOLD_VIEWS exists. A shared plugin reading
   * an index at or below 'items' is safe on both views; anything above it is
   * reading a different argument depending on which view is running, and must
   * be gated on the view id.
   */
  public function testTheTwoArgumentOrdersDivergeAfterItems() {
    $shared = array_slice(self::EXPECTED_ORDER, 0, 6);
    $this->assertSame(
      $shared,
      array_slice(self::CONTENT_RESOURCES_ORDER, 0, 6),
      'The two views must agree on the first six arguments; shared plugins rely on it.'
    );
    $this->assertSame(
      ['type', 'terms_include', 'terms_exclude', 'sort', 'view', 'items'],
      $shared,
      'The safe-to-share prefix is these six names.'
    );

    // And from index 6 they are different arguments entirely.
    $this->assertNotSame(
      self::EXPECTED_ORDER[6],
      self::CONTENT_RESOURCES_ORDER[6],
      'Index 6 is event_time_period here and offset there; a shared plugin cannot read it blind.'
    );
    $this->assertSame('event_time_period', self::EXPECTED_ORDER[6]);
    $this->assertSame('offset', self::CONTENT_RESOURCES_ORDER[6]);
  }

  /**
   * CHARACTERIZATION: the pager's offset index means the wrong thing elsewhere.
   *
   * ViewsBasicFullPager::offset() reads the 'offset' index, which is 7 in this
   * module's order. On the content_resources view index 7 is
   * field_display_options — a JSON blob — so `(int) $args[7]` there is `(int)
   * '{"show_thumbnail":1,...}'`, which PHP evaluates to 0. That view does set
   * a real offset (ViewsContentResourcesManager::setupView() passes
   * `'offset' => $paramsDecoded['offset'] ?? 0`), at ITS index 6.
   *
   * Net effect: an author-set offset is silently ignored on every
   * content_resources listing, while the scaffold views honour it correctly.
   * The pager is not gated on ::SCAFFOLD_VIEWS, so nothing catches it.
   *
   * This test pins the arithmetic that makes that true rather than the buggy
   * output, so it documents the defect without asserting it is desirable. The
   * paired GAP test below states the behaviour we actually want.
   *
   * Found during the 2026-09-14 PR review remediation; not fixed here because
   * the fix is a behaviour change on a view this PR does not otherwise touch.
   */
  public function testCharacterizationPagerOffsetIndexCollidesOnContentResources() {
    $offsetIndex = ViewsBasicManager::viewArgumentIndex('offset');
    $this->assertSame(7, $offsetIndex, 'offset is index 7 in the scaffold order.');

    // The same index on the other view names a different argument.
    $this->assertSame(
      'field_display_options',
      self::CONTENT_RESOURCES_ORDER[$offsetIndex],
      'Index 7 is field_display_options on content_resources, not offset.'
    );

    // ...and casting that argument's shape to int is how the offset becomes 0.
    $this->assertSame(0, (int) json_encode(['show_thumbnail' => 1]));
  }

  /**
   * GAP: a shared reader should resolve 'offset' per view, not per module.
   *
   * Skipped until the collision characterized above is fixed. The fix is a
   * design choice rather than a patch, which is why it is not made here:
   * either the shared plugins gate every read above 'items' on the view id and
   * resolve the index per view, or content_resources pads its argument list so
   * the two orders line up. Both change behaviour on a view outside this PR's
   * scope.
   *
   * @see self::testCharacterizationPagerOffsetIndexCollidesOnContentResources
   */
  public function testGapOffsetResolvesPerViewForSharedReaders() {
    $this->markTestSkipped(
      'GAP: ViewsBasicFullPager::offset() reads the scaffold index on every view, '
      . 'so content_resources silently offsets by 0. Needs a per-view resolver or a '
      . 'padded argument list; tracked as a follow-up from the 2026-09-14 review.'
    );
  }

}
