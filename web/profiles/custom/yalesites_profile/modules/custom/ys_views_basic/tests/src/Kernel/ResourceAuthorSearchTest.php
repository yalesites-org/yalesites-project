<?php

namespace Drupal\Tests\ys_views_basic\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\views\Entity\View;

/**
 * Runs the resource scaffold view's author search end to end.
 *
 * A resource with several authors must match each of them yet come back once:
 * joining the multi-value author fields into the main query multiplied rows,
 * which DISTINCT could not collapse because each join adds its own columns.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Plugin\views\filter\ResourceAuthorCombine
 * @group ys_views_basic
 * @group yalesites
 */
class ResourceAuthorSearchTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'field_group',
    'filter',
    'text',
    'node',
    'datetime',
    'path_alias',
    'taxonomy',
    'double_field',
    'views',
    'ys_views_basic',
  ];

  /**
   * {@inheritdoc}
   *
   * The site's scaffold view carries options from modules not installed here
   * (metatag, the custom pager's settings), which strict schema rejects.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * The resource with two affiliated and two non-affiliated authors.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $paper;

  /**
   * A resource with no authors.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $other;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node']);

    NodeType::create(['type' => 'resource', 'name' => 'Resource'])->save();
    NodeType::create(['type' => 'profile', 'name' => 'Profile'])->save();
    // The style plugin only honours a listing's view mode if it exists.
    EntityViewMode::create(['id' => 'node.card', 'targetEntityType' => 'node', 'label' => 'Card'])->save();

    $fields = [
      'field_publish_date' => ['type' => 'datetime', 'settings' => ['datetime_type' => 'date']],
      'field_authors' => ['type' => 'entity_reference', 'settings' => ['target_type' => 'node'], 'cardinality' => -1],
      'field_nonaffiliated_authors' => [
        'type' => 'double_field',
        'settings' => [
          'storage' => [
            'first' => ['type' => 'string', 'maxlength' => 100],
            'second' => ['type' => 'string', 'maxlength' => 100],
          ],
        ],
        'cardinality' => -1,
      ],
    ];
    foreach ($fields as $name => $storage) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node'] + $storage)->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'resource', 'label' => $name])->save();
    }

    // The scaffold view lives in site config, not the module.
    $path = $this->container->get('extension.list.module')->getPath('ys_views_basic');
    $sync = dirname($path, 3) . '/config/sync/views.view.views_basic_scaffold_resources.yml';
    View::create(Yaml::decode(file_get_contents($sync)))->save();

    $ada = Node::create(['type' => 'profile', 'title' => 'Ada Lovelace']);
    $ada->save();
    $grace = Node::create(['type' => 'profile', 'title' => 'Grace Hopper']);
    $grace->save();

    $this->paper = Node::create([
      'type' => 'resource',
      'title' => 'Paper',
      'field_authors' => [$ada->id(), $grace->id()],
      'field_nonaffiliated_authors' => [
        ['first' => 'Alan', 'second' => 'Turing'],
        ['first' => 'Edsger', 'second' => 'Dijkstra'],
      ],
    ]);
    $this->paper->save();
    $this->other = Node::create(['type' => 'resource', 'title' => 'Other']);
    $this->other->save();
  }

  /**
   * Runs the resource listing through setupView() and returns result nids.
   *
   * @param array $search_fields
   *   The block's stored search fields.
   * @param string $search
   *   The visitor's search text.
   *
   * @return int[]
   *   The result row nids, in order, duplicates kept.
   */
  protected function search(array $search_fields, string $search = ''): array {
    $manager = $this->container->get('ys_views_basic.views_basic_manager');
    $view = $manager->initView(['resource']);
    $executable = $view;
    $view->setExposedInput(['search' => $search]);
    $manager->setupView($view, json_encode([
      'filters' => ['types' => ['resource']],
      'exposed_filter_options' => ['show_search_filter' => 'show_search_filter'],
      'search_fields' => $search_fields,
      'sort_by' => 'field_publish_date:DESC',
      'display' => 'all',
      'limit' => 0,
      'view_mode' => 'card',
    ]));
    return array_map(fn ($row) => (int) $row->nid, $executable->result);
  }

  /**
   * Author search finds each author's resource exactly once.
   *
   * @covers ::query
   */
  public function testAuthorSearchNeverDuplicatesRows() {
    $with_authors = ['title' => 'title', 'authors' => 'authors'];
    $paper = (int) $this->paper->id();
    $other = (int) $this->other->id();

    $all = $this->search($with_authors);
    sort($all);
    $this->assertSame([$paper, $other], $all, 'Empty search lists each resource once.');

    $this->assertSame([$paper], $this->search($with_authors, 'Hopper'), 'Affiliated author name.');
    $this->assertSame([$paper], $this->search($with_authors, 'Dijkstra'), 'Non-affiliated last name.');
    $this->assertSame([$paper], $this->search($with_authors, 'Alan'), 'Non-affiliated first name.');
    $this->assertSame([$paper], $this->search($with_authors, 'Paper'), 'Title still searched.');
    $this->assertSame([], $this->search($with_authors, 'Nobody'), 'Unknown name.');

    // Authors alone: core's combine adds no condition without a real field.
    $this->assertSame([$paper], $this->search(['authors' => 'authors'], 'Turing'), 'Authors only.');
    $this->assertSame([], $this->search(['authors' => 'authors'], 'Other'), 'Authors only skips titles.');

    $this->assertSame([], $this->search(['title' => 'title'], 'Hopper'), 'Authors not selected.');
  }

}
