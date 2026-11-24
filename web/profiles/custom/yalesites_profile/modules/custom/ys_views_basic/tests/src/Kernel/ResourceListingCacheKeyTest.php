<?php

namespace Drupal\Tests\ys_views_basic\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\views\Entity\View;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Keeps resource listings with identical settings from sharing rendered output.
 *
 * The resource scaffold view caches its output by view arguments. Two listing
 * blocks with the same settings pack the same arguments, so a URL scoping a
 * search to one listing handed its output (an empty message, say) to the
 * other (YaleSites-Internal#2104).
 *
 * @coversDefaultClass \Drupal\ys_views_basic\ViewsBasicManager
 * @group ys_views_basic
 * @group yalesites
 */
class ResourceListingCacheKeyTest extends YsKernelTestBase {

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
    'better_exposed_filters',
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node', 'taxonomy']);

    NodeType::create(['type' => 'resource', 'name' => 'Resource'])->save();
    NodeType::create(['type' => 'profile', 'name' => 'Profile'])->save();
    // The style plugin only honours a listing's view mode if it exists.
    EntityViewMode::create(['id' => 'node.card', 'targetEntityType' => 'node', 'label' => 'Card'])->save();

    $fields = [
      'field_category' => [
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'taxonomy_term'],
        'cardinality' => -1,
      ],
      'field_audience' => [
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'taxonomy_term'],
        'cardinality' => -1,
      ],
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

    // The view's taxonomy filters depend on their vocabularies existing.
    $vids = [
      'resource_category', 'audience', 'custom_vocab', 'academic_years',
      'areas_of_study', 'discipline', 'geographic_areas',
    ];
    foreach ($vids as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => $vid])->save();
    }

    // The scaffold view lives in site config, not the module.
    $path = $this->container->get('extension.list.module')->getPath('ys_views_basic');
    $sync = dirname($path, 3) . '/config/sync/views.view.views_basic_scaffold_resources.yml';
    View::create(Yaml::decode(file_get_contents($sync)))->save();

    $node = Node::create(['type' => 'resource', 'title' => 'Paper']);
    $node->save();
  }

  /**
   * Renders a resource listing block's view and returns its render array.
   *
   * @param string $block_uuid
   *   The host block's UUID.
   *
   * @return array
   *   The render array setupView() leaves in place of the view.
   */
  protected function setupListing(string $block_uuid): array {
    $manager = $this->container->get('ys_views_basic.views_basic_manager');
    $view = $manager->initView(['resource']);
    $manager->setupView($view, json_encode([
      'filters' => ['types' => ['resource']],
      'exposed_filter_options' => ['show_search_filter' => 'show_search_filter'],
      'search_fields' => ['title' => 'title'],
      'sort_by' => 'field_publish_date:DESC',
      'display' => 'all',
      'limit' => 0,
      'view_mode' => 'card',
    ]), $block_uuid);
    return $view;
  }

  /**
   * Listings with the same settings get different render cache keys.
   *
   * @covers ::setupView
   */
  public function testIdenticalListingsDoNotShareRenderCacheKeys() {
    $uuids = [
      '11111111-1111-4111-8111-111111111111',
      '22222222-2222-4222-8222-222222222222',
    ];
    $keys = [];
    foreach ($uuids as $uuid) {
      $build = $this->setupListing($uuid);
      $this->assertNotEmpty($build['#cache']['keys'], 'The view output is render cached.');
      $this->assertContains(ViewsBasicManager::listingKey($uuid), $build['#cache']['keys'], 'The cache key names the listing.');
      $keys[] = ViewsBasicManager::listingKey($uuid);
    }
    $this->assertNotSame($keys[0], $keys[1], 'The two blocks get different listing keys.');
  }

}
