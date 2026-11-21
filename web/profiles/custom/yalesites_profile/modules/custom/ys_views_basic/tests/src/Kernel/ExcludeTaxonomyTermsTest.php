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
 * Runs a post listing's "hide content tagged" filter with Any and All (#2325).
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Plugin\views\filter\ExcludeTaxonomyTerms
 * @group ys_views_basic
 * @group yalesites
 */
class ExcludeTaxonomyTermsTest extends YsKernelTestBase {

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
   * The hidden terms, A then B.
   *
   * @var \Drupal\taxonomy\Entity\Term[]
   */
  protected $terms = [];

  /**
   * Post nids keyed by which hidden terms they carry: a, b, ab, none.
   *
   * @var int[]
   */
  protected $posts = [];

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

    foreach (['a' => 'Alpha', 'b' => 'Beta'] as $key => $name) {
      $this->terms[$key] = Term::create(['vid' => 'post_category', 'name' => $name]);
      $this->terms[$key]->save();
    }
    $a = $this->terms['a']->id();
    $b = $this->terms['b']->id();
    $carried = ['a' => [$a], 'b' => [$b], 'ab' => [$a, $b], 'none' => []];
    $day = 1;
    foreach ($carried as $key => $tids) {
      $node = Node::create([
        'type' => 'post',
        'title' => "Post $key",
        'field_category' => $tids,
        'field_publish_date' => '2020-01-0' . $day++,
      ]);
      $node->save();
      $this->posts[$key] = (int) $node->id();
    }
  }

  /**
   * Runs the post listing and returns the result nids, sorted.
   *
   * @param array $extra
   *   Params merged over the defaults.
   *
   * @return int[]
   *   The result row nids.
   */
  protected function listing(array $extra = []): array {
    $manager = $this->container->get('ys_views_basic.views_basic_manager');
    $view = $manager->initView(['post']);
    $executable = $view;
    $manager->setupView($view, json_encode($extra + [
      'filters' => ['types' => ['post']],
      'sort_by' => 'title:ASC',
      'display' => 'all',
      'limit' => 0,
      'view_mode' => 'card',
    ]));
    $nids = array_map(fn ($row) => (int) $row->nid, $executable->result);
    sort($nids);
    return $nids;
  }

  /**
   * Returns the expected nids for the named posts, sorted.
   */
  protected function expected(string ...$keys): array {
    $nids = array_map(fn ($key) => $this->posts[$key], $keys);
    sort($nids);
    return $nids;
  }

  /**
   * Both terms hidden with All hides only the post that carries both.
   *
   * @covers ::query
   */
  public function testAllHidesOnlyPostsWithEveryTerm() {
    $filters = [
      'types' => ['post'],
      'terms_exclude' => [$this->terms['a']->id(), $this->terms['b']->id()],
    ];
    $actual = $this->listing(['filters' => $filters, 'exclude_operator' => ',']);
    $this->assertSame($this->expected('a', 'b', 'none'), $actual);
  }

  /**
   * Both terms hidden with Any hides every post that carries either term.
   *
   * @covers ::query
   */
  public function testAnyHidesPostsWithEitherTerm() {
    $filters = [
      'types' => ['post'],
      'terms_exclude' => [$this->terms['a']->id(), $this->terms['b']->id()],
    ];
    $this->assertSame($this->expected('none'), $this->listing(['filters' => $filters, 'exclude_operator' => '+']));
    $this->assertSame($this->expected('none'), $this->listing(['filters' => $filters]), 'No stored operator means Any.');
  }

  /**
   * One hidden term gives the same result whichever operator is stored.
   *
   * @covers ::query
   */
  public function testOneTermIgnoresOperator() {
    $filters = [
      'types' => ['post'],
      'terms_exclude' => [$this->terms['a']->id()],
    ];
    foreach (['+', ','] as $operator) {
      $actual = $this->listing(['filters' => $filters, 'exclude_operator' => $operator]);
      $this->assertSame($this->expected('b', 'none'), $actual, "Operator $operator.");
    }
  }

}
