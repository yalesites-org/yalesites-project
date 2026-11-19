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
 * Tests that events sharing a start date list by title, then node ID.
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
   * Node IDs of the events sharing a start date, in creation order.
   *
   * @var int[]
   */
  private array $tiedNids = [];

  /**
   * Titles of every event, keyed by node ID.
   *
   * @var string[]
   */
  private array $titles = [];

  /**
   * Node ID of the event starting before the tied events.
   */
  private int $earlier;

  /**
   * Node ID of the event starting after the tied events.
   */
  private int $later;

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
    $this->later = $this->createEvent('Later', $tied + 86400);
    foreach ($this->tiedTitles() as $title) {
      $this->tiedNids[] = $this->createEvent($title, $tied);
    }
    $this->earlier = $this->createEvent('Earlier', $tied - 86400);
  }

  /**
   * Titles of the events sharing a start date, in creation order.
   *
   * Runs Z to A, so title order is the reverse of node ID order, and each
   * title is used twice, so the node ID has to break the tie between a pair.
   *
   * @return string[]
   *   The titles.
   */
  private function tiedTitles(): array {
    return array_map(
      fn($i) => sprintf('Tied %02d', intdiv(self::TIED - 1 - $i, 2)),
      range(0, self::TIED - 1),
    );
  }

  /**
   * Node IDs of the tied events, title A to Z, then node ID ascending.
   *
   * @return int[]
   *   The node IDs in the order a listing should show them.
   */
  private function tiedInTitleThenNidOrder(): array {
    $nids = $this->tiedNids;
    usort($nids, fn($a, $b) => [$this->titles[$a], $a] <=> [$this->titles[$b], $b]);
    return $nids;
  }

  /**
   * Creates a published event starting at the given timestamp.
   *
   * @return int
   *   The new node ID.
   */
  private function createEvent(string $title, int $start): int {
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
    $nid = (int) $node->id();
    $this->titles[$nid] = $title;
    return $nid;
  }

  /**
   * Runs the fixture view with the event sorts; returns the node IDs in order.
   *
   * @param string $direction
   *   The event date direction, ASC or DESC.
   * @param int $limit
   *   Items per page; 0 for all.
   * @param int $offset
   *   Items to skip.
   *
   * @return int[]
   *   The event node IDs in the order the view returned them.
   */
  private function listedNids(string $direction, int $limit = 0, int $offset = 0): array {
    $view = Views::getView('ys_views_basic_test_event_sort');
    $view->setDisplay('default');
    $view->getDisplay()->setOption('sorts', ViewsBasicManager::eventSorts($direction, FALSE));
    $view->setItemsPerPage($limit);
    $view->setOffset($offset);
    $this->executeView($view);
    $nids = [];
    foreach ($view->result as $row) {
      $nids[] = (int) $row->nid;
    }
    $view->destroy();
    return $nids;
  }

  /**
   * Soonest first: tied events list by title, then node ID.
   */
  public function testSoonestFirstBreaksTiesByTitleThenNodeId(): void {
    $expected = [$this->earlier, ...$this->tiedInTitleThenNidOrder(), $this->later];
    $this->assertSame($expected, $this->listedNids('ASC'));
    // The same listing loaded again gives the same order.
    $this->assertSame($expected, $this->listedNids('ASC'));
  }

  /**
   * Latest first: dates reverse, tied events still list title A to Z.
   */
  public function testLatestFirstBreaksTiesByTitleThenNodeId(): void {
    $expected = [$this->later, ...$this->tiedInTitleThenNidOrder(), $this->earlier];
    $this->assertSame($expected, $this->listedNids('DESC'));
    $this->assertSame($expected, $this->listedNids('DESC'));
  }

  /**
   * Paging through the tied events shows each one exactly once.
   */
  public function testPagesNeitherRepeatNorSkipTiedEvents(): void {
    foreach (['ASC', 'DESC'] as $direction) {
      $paged = [];
      for ($offset = 0; $offset < self::TIED + 2; $offset += 4) {
        $paged = array_merge($paged, $this->listedNids($direction, 4, $offset));
      }
      $this->assertSame($this->listedNids($direction), $paged, $direction);
    }
  }

}
