<?php

namespace Drupal\Tests\ys_views_basic\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\views\Entity\View;

/**
 * Runs a post listing's keyword search against category term names (#706).
 *
 * The keyword search matches a post's category names in either search
 * scope, never its tag names. A post in two categories must still come back
 * once: joining the multi-value category field into the main query would
 * multiply its rows, which DISTINCT cannot collapse.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Plugin\views\filter\CategoryCombine
 * @group ys_views_basic
 * @group yalesites
 */
class PostCategorySearchTest extends YsKernelTestBase {

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
   * The post in two categories and one tag.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $categorized;

  /**
   * A post with no terms.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $plain;

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

    NodeType::create(['type' => 'post', 'name' => 'Post'])->save();
    // The style plugin only honours a listing's view mode if it exists.
    EntityViewMode::create(['id' => 'node.card', 'targetEntityType' => 'node', 'label' => 'Card'])->save();

    // The view's taxonomy filters depend on their vocabularies existing.
    foreach (['post_category', 'page_category', 'affiliation', 'audience', 'custom_vocab', 'tags'] as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => $vid])->save();
    }

    // Post listings leave out posts without a past publish date.
    $fields = [
      'field_teaser_title' => ['string', []],
      'field_teaser_text' => ['text_long', []],
      'field_publish_date' => ['datetime', ['datetime_type' => 'date']],
      'field_category' => ['entity_reference', ['target_type' => 'taxonomy_term']],
      'field_tags' => ['entity_reference', ['target_type' => 'taxonomy_term']],
    ];
    foreach ($fields as $name => [$type, $settings]) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
        'settings' => $settings,
        'cardinality' => $type === 'entity_reference' ? -1 : 1,
      ])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'post', 'label' => $name])->save();
    }

    // The scaffold view lives in site config, not the module.
    $path = $this->container->get('extension.list.module')->getPath('ys_views_basic');
    $sync = dirname($path, 3) . '/config/sync/views.view.views_basic_scaffold.yml';
    View::create(Yaml::decode(file_get_contents($sync)))->save();

    $astrophysics = Term::create(['vid' => 'post_category', 'name' => 'Astrophysics']);
    $astrophysics->save();
    $botany = Term::create(['vid' => 'post_category', 'name' => 'Botany']);
    $botany->save();
    $zoology = Term::create(['vid' => 'tags', 'name' => 'Zoology']);
    $zoology->save();
    $hidden = Term::create(['vid' => 'post_category', 'name' => 'Cryptozoology', 'status' => 0]);
    $hidden->save();

    $this->categorized = Node::create([
      'type' => 'post',
      'title' => 'Field notes',
      'field_category' => [$astrophysics->id(), $botany->id(), $hidden->id()],
      'field_tags' => [$zoology->id()],
      'field_publish_date' => '2020-01-01',
    ]);
    $this->categorized->save();
    $this->plain = Node::create([
      'type' => 'post',
      'title' => 'Lab report',
      'field_publish_date' => '2020-01-02',
    ]);
    $this->plain->save();
  }

  /**
   * Runs the post listing through setupView() and returns result nids.
   *
   * @param string $search
   *   The visitor's search text.
   * @param array $extra
   *   Params that replace the defaults.
   *
   * @return int[]
   *   The result row nids, in order, duplicates kept.
   */
  protected function search(string $search, array $extra = []): array {
    $manager = $this->container->get('ys_views_basic.views_basic_manager');
    $view = $manager->initView(['post']);
    // setupView() replaces $view with the rendered result, so keep the
    // executable to read its rows.
    $executable = $view;
    $view->setExposedInput(['search' => $search]);
    $manager->setupView($view, json_encode($extra + [
      'filters' => ['types' => ['post']],
      'exposed_filter_options' => ['show_search_filter' => 'show_search_filter'],
      'sort_by' => 'title:ASC',
      'display' => 'all',
      'limit' => 0,
      'view_mode' => 'card',
    ]));
    return array_map(fn ($row) => (int) $row->nid, $executable->result);
  }

  /**
   * Either scope finds a post by each of its category names, once.
   *
   * @covers ::query
   */
  public function testCategoryNamesAreSearchedInEitherScope() {
    $post = (int) $this->categorized->id();
    foreach (['shown', 'all'] as $scope) {
      $this->assertSame([$post], $this->search('Astrophysics', ['search_scope' => $scope]), "First category, $scope scope.");
      $this->assertSame([$post], $this->search('botany', ['search_scope' => $scope]), "Second category, any case, $scope scope.");
    }
    $this->assertSame([$post], $this->search('Astrophysics'), 'A block saved before the scope existed.');
  }

  /**
   * Tag names and unpublished category names are not searched.
   *
   * @covers ::query
   */
  public function testTagsAndUnpublishedCategoriesAreNotSearched() {
    foreach (['shown', 'all'] as $scope) {
      $this->assertSame([], $this->search('Zoology', ['search_scope' => $scope]), "Tag, $scope scope.");
    }
    $this->assertSame([], $this->search('Cryptozoology'), 'An unpublished category.');
  }

  /**
   * Titles are still searched, and nothing is listed twice.
   *
   * @covers ::query
   */
  public function testTitlesStillSearchedAndNoDuplicates() {
    $this->assertSame([(int) $this->plain->id()], $this->search('Lab'));
    $this->assertSame([(int) $this->categorized->id()], $this->search('Field notes'));

    $expected = [(int) $this->categorized->id(), (int) $this->plain->id()];
    foreach (['shown', 'all'] as $scope) {
      $everyone = $this->search('', ['search_scope' => $scope]);
      sort($everyone);
      $this->assertSame($expected, $everyone, "Every post once, $scope scope.");
    }
  }

}
