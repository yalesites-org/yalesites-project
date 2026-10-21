<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;
use Drupal\ys_views_basic\ListingRowDisplay;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests that each listing's rows render with that listing's display flags.
 *
 * Two listings on one page load the same node objects from the entity static
 * cache, and the node templates read the per-block flags (show_thumbnail,
 * card_heading_level, ...) off the node only when the page renders, after
 * every listing has run. Stamping them on the node per listing let the last
 * listing's flags win on all of them (yalesites-org/yalesites-project#1591).
 * Each row now carries its own flags and puts them on the node only while it
 * renders, so no listing clones its result nodes.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\ListingRowDisplay
 *
 * @group yalesites
 */
class ListingRowEntityIsolationTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/../../../ys_views_basic.module';
  }

  /**
   * Returns a row build the way the entity view builder makes it.
   */
  private function rowBuild(object $node): array {
    return [
      '#node' => $node,
      '#pre_render' => ['entity_view_builder_build'],
    ];
  }

  /**
   * Renders a row build the way the Renderer does, minus the markup.
   *
   * Runs this module's callbacks in order, records what the node holds while
   * the row "renders" (between #pre_render and #post_render), and lets a
   * nested render run in that window.
   *
   * @return array
   *   The flags the node held during the row's render.
   */
  private function render(array $build, array $read, ?callable $nested = NULL): array {
    foreach ($build['#pre_render'] as $callback) {
      if (is_array($callback) && $callback[0] === ListingRowDisplay::class) {
        $build = call_user_func($callback, $build);
      }
    }
    $node = $build['#node'];
    if ($nested) {
      $nested();
    }
    $seen = [];
    foreach ($read as $name) {
      $seen[$name] = $node->{$name} ?? NULL;
    }
    foreach ($build['#post_render'] as $callback) {
      call_user_func($callback, 'markup', $build);
    }
    return $seen;
  }

  /**
   * Two listings sharing one node each render it with their own flags.
   *
   * @covers ::attach
   * @covers ::preRender
   * @covers ::postRender
   */
  public function testListingsSharingNodeKeepOwnFlags(): void {
    $node = new \stdClass();
    // Both listings attach their flags before either row renders, as on a
    // real page.
    $first = ListingRowDisplay::attach($this->rowBuild($node), ['show_thumbnail' => 1, 'card_heading_level' => '3']);
    $second = ListingRowDisplay::attach($this->rowBuild($node), ['show_thumbnail' => 0, 'card_heading_level' => '2']);

    $read = ['show_thumbnail', 'card_heading_level'];
    $this->assertSame(['show_thumbnail' => 1, 'card_heading_level' => '3'], $this->render($first, $read));
    $this->assertSame(['show_thumbnail' => 0, 'card_heading_level' => '2'], $this->render($second, $read));
    $this->assertFalse(isset($node->show_thumbnail), 'The shared node holds no listing flag once its rows have rendered.');
  }

  /**
   * The flags go ahead of the entity view builder's own #pre_render.
   *
   * @covers ::attach
   */
  public function testAttachRunsBeforeTheBuild(): void {
    $build = ListingRowDisplay::attach($this->rowBuild(new \stdClass()), []);
    $this->assertSame([ListingRowDisplay::class, 'preRender'], $build['#pre_render'][0]);
    $this->assertSame('entity_view_builder_build', $build['#pre_render'][1]);
    $this->assertSame([[ListingRowDisplay::class, 'postRender']], $build['#post_render']);

    $bare = ListingRowDisplay::attach(['#node' => new \stdClass()], []);
    $this->assertSame([[ListingRowDisplay::class, 'preRender']], $bare['#pre_render'], 'A build without #pre_render gets one.');
  }

  /**
   * A nested render of the same node restores the outer row's flags.
   *
   * @covers ::preRender
   * @covers ::postRender
   */
  public function testNestedRenderOfTheSameNodeRestoresOuterFlags(): void {
    $node = new \stdClass();
    $node->pin_label = 'Existing';
    $outer = ListingRowDisplay::attach($this->rowBuild($node), ['show_tags' => 1, 'pin_label' => 'Featured']);
    $inner = ListingRowDisplay::attach($this->rowBuild($node), ['show_tags' => 0, 'pin_label' => NULL]);

    $seen_inner = [];
    $seen_outer = $this->render($outer, ['show_tags', 'pin_label'], function () use ($inner, &$seen_inner) {
      $seen_inner = $this->render($inner, ['show_tags', 'pin_label']);
    });

    $this->assertSame(['show_tags' => 0, 'pin_label' => NULL], $seen_inner, 'NULL leaves the property unset while the row renders.');
    $this->assertSame(['show_tags' => 1, 'pin_label' => 'Featured'], $seen_outer);
    $this->assertSame('Existing', $node->pin_label, 'What the node held before is put back.');
    $this->assertFalse(isset($node->show_tags));
  }

  /**
   * A row with no node, or no flags, passes through untouched.
   *
   * @covers ::preRender
   * @covers ::postRender
   */
  public function testRowWithoutNodeIsLeftAlone(): void {
    $element = [ListingRowDisplay::FLAGS_KEY => ['show_tags' => 1]];
    $this->assertSame($element, ListingRowDisplay::preRender($element));
    $this->assertSame('markup', ListingRowDisplay::postRender('markup', $element));

    $node = new \stdClass();
    $this->assertSame(['#node' => $node], ListingRowDisplay::preRender(['#node' => $node]));
  }

  /**
   * The flags come from the listing's own view arguments.
   *
   * @covers ::flagsFromArgs
   * @covers ::pinLabelFromArgs
   */
  public function testFlagsFromArgs(): void {
    $args = array_fill(0, count(ViewsBasicManager::VIEW_ARGUMENT_ORDER), '');
    $args[ViewsBasicManager::viewArgumentIndex('field_display_options')] = json_encode([
      'show_categories' => 1,
      'show_tags' => 0,
      'show_thumbnail' => 1,
      'block_has_heading' => 1,
    ]);
    $args[ViewsBasicManager::viewArgumentIndex('post_field_display_options')] = json_encode(['show_eyebrow' => 1]);
    $pin = ['pinned_to_top' => TRUE, 'pin_label' => 'Pinned'];
    $args[ViewsBasicManager::viewArgumentIndex('pin_settings')] = json_encode($pin);
    $resource_options = array_fill_keys(ViewsBasicManager::RESOURCE_FIELD_OPTIONS, 1);
    $args[ViewsBasicManager::viewArgumentIndex('resource_field_display_options')] = json_encode($resource_options);

    $flags = ListingRowDisplay::flagsFromArgs('views_basic_scaffold', $args);
    $this->assertSame(1, $flags['show_categories']);
    $this->assertSame(1, $flags['show_eyebrow']);
    $this->assertSame(0, $flags['hide_add_to_calendar'], 'A missing argument falls back to off.');
    $this->assertSame(0, $flags['show_department'], 'Profile options fall back to off.');
    $this->assertSame('3', $flags['card_heading_level']);
    $this->assertArrayNotHasKey('show_category', $flags, 'Only resource listings get the resource names.');
    $this->assertSame('Pinned', ListingRowDisplay::pinLabelFromArgs($args));

    $resource_flags = ListingRowDisplay::flagsFromArgs('views_basic_scaffold_resources', $args);
    $this->assertSame(1, $resource_flags['show_category']);
    foreach (ViewsBasicManager::RESOURCE_FIELD_OPTIONS as $option) {
      $this->assertSame(1, $resource_flags[$option]);
    }

    // Garbage arguments yield the all-off set rather than an error.
    $garbage = ListingRowDisplay::flagsFromArgs('views_basic_scaffold', ['x', 'y']);
    $this->assertSame(0, $garbage['show_thumbnail']);
    $this->assertSame('2', $garbage['card_heading_level']);
    $this->assertSame('', ListingRowDisplay::pinLabelFromArgs(['x']));
  }

  /**
   * Only sticky nodes are pinned rows.
   *
   * @covers ::isSticky
   */
  public function testIsSticky(): void {
    $sticky = $this->createMock(NodeInterface::class);
    $sticky->method('isSticky')->willReturn(TRUE);
    $plain = $this->createMock(NodeInterface::class);
    $plain->method('isSticky')->willReturn(FALSE);
    $this->assertTrue(ListingRowDisplay::isSticky($sticky));
    $this->assertFalse(ListingRowDisplay::isSticky($plain));
    $this->assertFalse(ListingRowDisplay::isSticky(new \stdClass()));
  }

  /**
   * Returns a view mock with the given id, one row per entity, and args.
   */
  private function viewWithRows(string $id, array $entities, array $args = []): ViewExecutable {
    $view = $this->createMock(ViewExecutable::class);
    $view->method('id')->willReturn($id);
    $view->result = array_map(fn($entity) => new ResultRow(['_entity' => $entity]), $entities);
    $view->args = $args;
    return $view;
  }

  /**
   * Running a listing no longer clones its result nodes.
   *
   * @covers \ys_views_basic_views_post_execute
   */
  public function testPostExecuteDoesNotCloneRowEntities(): void {
    $node = new \stdClass();
    $view = $this->viewWithRows('views_basic_scaffold_resources', [$node]);

    ys_views_basic_views_post_execute($view);

    $this->assertSame($node, $view->result[0]->_entity);
  }

  /**
   * A listing that does not page drops its pager after running.
   *
   * @covers \ys_views_basic_views_post_execute
   */
  public function testPostExecuteDropsPagerOfNonPagingListing(): void {
    $settings = ViewsBasicManager::viewArgumentIndex('original_settings');
    $args = array_fill(0, count(ViewsBasicManager::VIEW_ARGUMENT_ORDER), '');

    $args[$settings] = json_encode(['filters' => ['types' => ['post']], 'display' => 'all']);
    $all = $this->viewWithRows('views_basic_scaffold', [], $args);
    $all->pager = new \stdClass();
    ys_views_basic_views_post_execute($all);
    $this->assertFalse(isset($all->pager));

    $args[$settings] = json_encode(['filters' => ['types' => ['post']], 'display' => 'pager']);
    $paged = $this->viewWithRows('views_basic_scaffold', [], $args);
    $paged->pager = new \stdClass();
    ys_views_basic_views_post_execute($paged);
    $this->assertTrue(isset($paged->pager));

    // Unusable params leave the pager alone; pre_view already failed the view.
    $bad = $this->viewWithRows('views_basic_scaffold', [], ['a', 'b']);
    $bad->pager = new \stdClass();
    ys_views_basic_views_post_execute($bad);
    $this->assertTrue(isset($bad->pager));

    $other = $this->viewWithRows('content', [], $args);
    $other->pager = new \stdClass();
    ys_views_basic_views_post_execute($other);
    $this->assertTrue(isset($other->pager), 'Other views keep their pager.');
  }

}
