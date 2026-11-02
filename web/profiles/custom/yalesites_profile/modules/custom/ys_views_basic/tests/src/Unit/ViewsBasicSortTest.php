<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\ViewExecutable;
use Drupal\ys_views_basic\Plugin\views\sort\ViewsBasicSort;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests that the listing sort only orders by sorts a listing offers.
 *
 * The sort argument becomes an ORDER BY expression, and on the views/ajax
 * path it comes from the browser.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Plugin\views\sort\ViewsBasicSort
 *
 * @group yalesites
 */
class ViewsBasicSortTest extends UnitTestCase {

  /**
   * Runs the sort with the given sort argument; returns the ORDER BY calls.
   */
  protected function orderBy(mixed $sort): array {
    $calls = [];
    $query = $this->createMock(Sql::class);
    $query->method('ensureTable')->willReturn('node_field_data');
    $query->method('addTable')->willReturnCallback(fn($table) => $table);
    $query->method('addOrderBy')->willReturnCallback(
      function ($table, $field, $order) use (&$calls) {
        $calls[] = [$field, $order];
      }
    );
    $view = $this->createMock(ViewExecutable::class);
    $view->args = [];
    $view->args[ViewsBasicManager::viewArgumentIndex('sort')] = $sort;

    $plugin = (new \ReflectionClass(ViewsBasicSort::class))->newInstanceWithoutConstructor();
    $plugin->query = $query;
    $plugin->view = $view;
    $plugin->table = 'node_field_data';
    $plugin->relationship = NULL;
    $plugin->query();
    return $calls;
  }

  /**
   * Every offered sort orders the results.
   *
   * @covers ::query
   */
  public function testOfferedSortsOrder(): void {
    foreach (ViewsBasicManager::ALLOWED_ENTITIES as $definition) {
      foreach (array_keys($definition['sort_by']) as $sort) {
        [$field, $direction] = explode(':', $sort);
        $calls = $this->orderBy($sort);
        $this->assertCount(3, $calls, $sort);
        $expected = str_starts_with($field, 'field') ? "node__$field.{$field}_value" : "node_field_data.$field";
        $this->assertSame([$expected, $direction], $calls[1], $sort);
      }
    }
  }

  /**
   * An injected or unknown sort adds no ORDER BY at all, and does not throw.
   *
   * @covers ::query
   */
  public function testUnofferedSortIsIgnored(): void {
    $this->assertSame([], $this->orderBy('(SELECT IF(ASCII(SUBSTRING((SELECT pass FROM users_field_data WHERE uid=1),1,1))>64,SLEEP(5),0)):ASC'));
    $this->assertSame([], $this->orderBy('nid:ASC'));
    $this->assertSame([], $this->orderBy(['title:ASC']));
    $this->assertSame([], $this->orderBy(''));
  }

}
