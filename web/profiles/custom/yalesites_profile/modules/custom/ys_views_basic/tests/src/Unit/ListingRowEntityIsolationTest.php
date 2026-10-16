<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;

/**
 * Tests that each listing stamps its display flags on its own node copies.
 *
 * Two listings on one page load the same node objects from the entity static
 * cache. hook_views_pre_render() stamps each listing's per-block flags
 * (show_thumbnail, show_teaser_text, ...) onto those objects, and the node
 * templates read them only when the page renders, after every listing has run.
 * Without a copy per listing, the last listing's flags win on all of them
 * (yalesites-org/yalesites-project#1591).
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
   * Returns a view mock with the given id and one row per given entity.
   */
  private function viewWithRows(string $id, array $entities): ViewExecutable {
    $view = $this->createMock(ViewExecutable::class);
    $view->method('id')->willReturn($id);
    $view->result = array_map(fn($entity) => new ResultRow(['_entity' => $entity]), $entities);
    return $view;
  }

  /**
   * Two scaffold listings sharing a node each end up with their own copy.
   */
  public function testScaffoldListingsDoNotShareRowEntities(): void {
    $node = new \stdClass();
    $first = $this->viewWithRows('views_basic_scaffold_resources', [$node]);
    $second = $this->viewWithRows('views_basic_scaffold_resources', [$node]);

    ys_views_basic_views_post_execute($first);
    ys_views_basic_views_post_execute($second);

    $first->result[0]->_entity->show_thumbnail = 1;
    $second->result[0]->_entity->show_thumbnail = 0;

    $this->assertSame(1, $first->result[0]->_entity->show_thumbnail, 'The second listing must not overwrite the first listing\'s flag.');
    $this->assertNotSame($node, $first->result[0]->_entity);
  }

  /**
   * Views that are not listing scaffolds keep the shared entity.
   */
  public function testOtherViewsUntouched(): void {
    $node = new \stdClass();
    $view = $this->viewWithRows('content', [$node]);

    ys_views_basic_views_post_execute($view);

    $this->assertSame($node, $view->result[0]->_entity);
  }

}
