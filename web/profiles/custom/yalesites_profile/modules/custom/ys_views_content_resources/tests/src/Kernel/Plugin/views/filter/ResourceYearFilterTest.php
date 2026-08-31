<?php

namespace Drupal\Tests\ys_views_content_resources\Kernel\Plugin\views\filter;

use Drupal\Core\Cache\Cache;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\ys_views_content_resources\Plugin\views\filter\ResourceYearFilter;

/**
 * Kernel tests for ResourceYearFilter::generateYearOptions().
 *
 * Exercises the real database query and cache backend against actual
 * "resource" nodes, since the method's logic (a raw SUBSTRING() query
 * plus permanent caching keyed to the node_list:resource cache tag) is not
 * meaningfully testable with mocks.
 *
 * @coversDefaultClass \Drupal\ys_views_content_resources\Plugin\views\filter\ResourceYearFilter
 * @group ys_views_content_resources
 * @group yalesites
 */
class ResourceYearFilterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'datetime',
    'path_alias',
    'ys_views_content_resources',
  ];

  /**
   * The filter plugin under test.
   *
   * @var \Drupal\ys_views_content_resources\Plugin\views\filter\ResourceYearFilter
   */
  protected $filter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);

    NodeType::create(['type' => 'resource', 'name' => 'Resource'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_publish_date',
      'entity_type' => 'node',
      'type' => 'datetime',
      'settings' => ['datetime_type' => 'date'],
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_publish_date',
      'entity_type' => 'node',
      'bundle' => 'resource',
      'label' => 'Publish date',
    ])->save();

    $this->filter = ResourceYearFilter::create(
      $this->container,
      [],
      'resource_year_filter',
      []
    );
  }

  /**
   * Creates and saves a resource node with a given publish date.
   *
   * @param string $date
   *   A date in 'Y-m-d' format.
   */
  protected function createResourceNode(string $date): void {
    Node::create([
      'type' => 'resource',
      'title' => "Resource published $date",
      'field_publish_date' => $date,
    ])->save();
  }

  /**
   * Returns only the distinct years present, newest first.
   *
   * @covers ::generateYearOptions
   */
  public function testGenerateYearOptionsReturnsDistinctYearsNewestFirst() {
    $this->createResourceNode('2021-05-01');
    $this->createResourceNode('2023-01-15');
    // A second 2023 node should not produce a duplicate entry.
    $this->createResourceNode('2023-11-30');

    $options = $this->filter->generateYearOptions();

    $this->assertSame(['2023' => '2023', '2021' => '2021'], $options);
  }

  /**
   * Returns an empty array when there are no resource nodes yet.
   *
   * @covers ::generateYearOptions
   */
  public function testGenerateYearOptionsReturnsEmptyArrayWithNoNodes() {
    $this->assertSame([], $this->filter->generateYearOptions());
  }

  /**
   * The computed options are cached permanently under the expected cache ID.
   *
   * @covers ::generateYearOptions
   */
  public function testGenerateYearOptionsCachesResultUnderExpectedId() {
    $this->createResourceNode('2022-06-01');

    $options = $this->filter->generateYearOptions();

    $cid = 'ys_views_content_resources:resource_year_filter:options';
    $cache = $this->container->get('cache.default');
    $cached = $cache->get($cid);

    $this->assertNotFalse($cached);
    $this->assertSame($options, $cached->data);
    $this->assertSame(Cache::PERMANENT, $cached->expire);
  }

  /**
   * Saving a new resource node auto-invalidates the permanent cache entry.
   *
   * The options are cached permanently, but Drupal's own entity storage
   * invalidates the `node_list:resource` cache tag as part of saving any
   * resource node, so a second call picks up the new year without this
   * filter needing to invalidate anything itself.
   *
   * @covers ::generateYearOptions
   */
  public function testGenerateYearOptionsPicksUpNodeSavedAfterFirstCall() {
    $this->createResourceNode('2020-01-01');
    $firstResult = $this->filter->generateYearOptions();
    $this->assertSame(['2020' => '2020'], $firstResult);

    $this->createResourceNode('2024-01-01');
    $secondResult = $this->filter->generateYearOptions();

    $this->assertSame(['2024' => '2024', '2020' => '2020'], $secondResult);
  }

}
