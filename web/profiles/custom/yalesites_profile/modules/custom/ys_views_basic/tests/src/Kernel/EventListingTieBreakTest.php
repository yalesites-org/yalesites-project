<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_views_basic\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\views\Kernel\ViewsKernelTestBase;
use Drupal\views\Tests\ViewTestData;
use Drupal\views\Views;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests that events sharing a start date list in node ID order.
 *
 * Runs the sorts an events listing gets (ViewsBasicManager::eventSorts())
 * against real event nodes (yalesites-org/YaleSites-Internal#1969).
 *
 * @group yalesites
 * @group ys_views_basic
 */
class EventListingTieBreakTest extends ViewsKernelTestBase {

  // This test needs ViewsKernelTestBase, so it cannot extend YsKernelTestBase.
  // These three must stay in sync with that class - see its docblock for why
  // they move together, and KernelTestIsolationContractTest, which enforces it.
  /**
   * {@inheritdoc}
   */
  protected $runTestInSeparateProcess = FALSE;

  /**
   * {@inheritdoc}
   */
  protected $backupGlobals = FALSE;

  /**
   * {@inheritdoc}
   */
  protected $backupStaticAttributes = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'text',
    'filter',
    'datetime',
    'options',
    'smart_date',
    'node',
    'path_alias',
    'ys_views_basic',
    'ys_views_basic_test',
  ];

  /**
   * {@inheritdoc}
   */
  public static $testViews = ['ys_views_basic_test_event_sort'];

  /**
   * How many events share the start date.
   */
  private const TIED = 30;

  /**
   * Node IDs of the events, keyed by title.
   *
   * @var int[]
   */
  private array $nids = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp($import_test_views = TRUE): void {
    parent::setUp(FALSE);

    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'field', 'filter']);

    NodeType::create(['type' => 'event', 'name' => 'Event'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_event_date',
      'entity_type' => 'node',
      'type' => 'smartdate',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_event_date',
      'entity_type' => 'node',
      'bundle' => 'event',
      'label' => 'Event Date',
    ])->save();

    ViewTestData::createTestViews(static::class, ['ys_views_basic_test']);

    // Created in this order, so node IDs ascend down the list. Most events
    // share one start date; one starts earlier and one later. The later
    // event is created first so it has the lowest node ID: the date, not the
    // node ID, must decide between different start dates.
    $tied = strtotime('2005-03-07 10:00:00 UTC');
    $this->createEvent('Later', $tied + 86400);
    foreach ($this->tiedTitles() as $title) {
      $this->createEvent($title, $tied);
    }
    $this->createEvent('Earlier', $tied - 86400);
  }

  /**
   * Titles of the events sharing a start date, in creation order.
   *
   * @return string[]
   *   The titles.
   */
  private function tiedTitles(): array {
    return array_map(fn($i) => sprintf('Tied %02d', $i), range(1, self::TIED));
  }

  /**
   * Creates a published event starting at the given timestamp.
   */
  private function createEvent(string $title, int $start): void {
    $node = Node::create([
      'type' => 'event',
      'title' => $title,
      'status' => 1,
      'field_event_date' => [
        'value' => $start,
        'end_value' => $start + 3600,
        'duration' => 60,
        'timezone' => '',
      ],
    ]);
    $node->save();
    $this->nids[$title] = (int) $node->id();
  }

  /**
   * Runs the fixture view with the event sorts; returns the titles in order.
   *
   * @param string $direction
   *   The event date direction, ASC or DESC.
   * @param int $limit
   *   Items per page; 0 for all.
   * @param int $offset
   *   Items to skip.
   *
   * @return string[]
   *   The event titles in the order the view returned them.
   */
  private function listedTitles(string $direction, int $limit = 0, int $offset = 0): array {
    $view = Views::getView('ys_views_basic_test_event_sort');
    $view->setDisplay('default');
    $view->getDisplay()->setOption('sorts', ViewsBasicManager::eventSorts($direction, FALSE));
    $view->setItemsPerPage($limit);
    $view->setOffset($offset);
    $this->executeView($view);
    $byNid = array_flip($this->nids);
    $titles = [];
    foreach ($view->result as $row) {
      $titles[] = $byNid[(int) $row->nid];
    }
    $view->destroy();
    return $titles;
  }

  /**
   * Soonest first: tied events list in node ID order.
   */
  public function testSoonestFirstBreaksTiesByNodeId(): void {
    $expected = ['Earlier', ...$this->tiedTitles(), 'Later'];
    $this->assertSame($expected, $this->listedTitles('ASC'));
    // The same listing loaded again gives the same order.
    $this->assertSame($expected, $this->listedTitles('ASC'));
  }

  /**
   * Latest first: dates reverse, tied events still list in node ID order.
   */
  public function testLatestFirstBreaksTiesByNodeId(): void {
    $this->assertSame(['Later', ...$this->tiedTitles(), 'Earlier'], $this->listedTitles('DESC'));
  }

  /**
   * Paging through the tied events shows each one exactly once.
   */
  public function testPagesNeitherRepeatNorSkipTiedEvents(): void {
    foreach (['ASC', 'DESC'] as $direction) {
      $paged = [];
      for ($offset = 0; $offset < self::TIED + 2; $offset += 4) {
        $paged = array_merge($paged, $this->listedTitles($direction, 4, $offset));
      }
      $this->assertSame($this->listedTitles($direction), $paged, $direction);
    }
  }

}
