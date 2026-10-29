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

/**
 * Runs a profile listing's keyword search through the scaffold view (#706).
 *
 * The scaffold view's combine filter lists only the title and teaser fields,
 * and the filter can only match fields the display has a handler for. The
 * search scope widens the search at render time by adding the other fields to
 * the display as excluded fields, so this runs the real view against real
 * field tables to prove those runtime fields are searched.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\ViewsBasicManager
 * @group ys_views_basic
 * @group yalesites
 */
class ProfileSearchScopeTest extends YsKernelTestBase {

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
   * The profile whose keyword is only in its department.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $department;

  /**
   * The profile whose keyword is only in its email.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $email;

  /**
   * The profile whose keyword is only in its position.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $position;

  /**
   * The profile whose keyword is only in its subtitle.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $subtitle;

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

    NodeType::create(['type' => 'profile', 'name' => 'Profile'])->save();
    // The style plugin only honours a listing's view mode if it exists.
    EntityViewMode::create(['id' => 'node.card', 'targetEntityType' => 'node', 'label' => 'Card'])->save();
    EntityViewMode::create(['id' => 'node.condensed', 'targetEntityType' => 'node', 'label' => 'Condensed'])->save();

    // The profile sort orders by last name; the teaser fields are on the
    // scaffold display; the rest are the fields the search scope adds.
    $fields = [
      'field_last_name' => 'string',
      'field_teaser_title' => 'string',
      'field_teaser_text' => 'text_long',
      'field_position' => 'string',
      'field_subtitle' => 'text',
      'field_department' => 'string',
      'field_pronouns' => 'string',
      'field_email' => 'email',
    ];
    foreach ($fields as $name => $type) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => $type])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'profile', 'label' => $name])->save();
    }

    // The view's taxonomy filters depend on their vocabularies existing.
    foreach (['post_category', 'page_category', 'affiliation', 'audience', 'custom_vocab'] as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => $vid])->save();
    }

    // The scaffold view lives in site config, not the module.
    $path = $this->container->get('extension.list.module')->getPath('ys_views_basic');
    $sync = dirname($path, 3) . '/config/sync/views.view.views_basic_scaffold.yml';
    View::create(Yaml::decode(file_get_contents($sync)))->save();

    $this->department = Node::create([
      'type' => 'profile',
      'title' => 'Ada Lovelace',
      'field_last_name' => 'Lovelace',
      'field_department' => 'Astronomy',
    ]);
    $this->department->save();
    $this->email = Node::create([
      'type' => 'profile',
      'title' => 'Grace Hopper',
      'field_last_name' => 'Hopper',
      'field_email' => 'zebrafish@example.com',
    ]);
    $this->email->save();
    $this->position = Node::create([
      'type' => 'profile',
      'title' => 'Alan Turing',
      'field_last_name' => 'Turing',
      'field_position' => 'Cryptanalyst',
    ]);
    $this->position->save();
    $this->subtitle = Node::create([
      'type' => 'profile',
      'title' => 'Edsger Dijkstra',
      'field_last_name' => 'Dijkstra',
      'field_subtitle' => 'Semaphores',
    ]);
    $this->subtitle->save();
  }

  /**
   * Runs the profile listing through setupView() and returns result nids.
   *
   * @param string $search
   *   The visitor's search text.
   * @param array $extra
   *   Params that replace the defaults (the scope, the display toggles, the
   *   view mode).
   *
   * @return int[]
   *   The result row nids, in order, duplicates kept.
   */
  protected function search(string $search, array $extra = []): array {
    $manager = $this->container->get('ys_views_basic.views_basic_manager');
    $view = $manager->initView(['profile']);
    // setupView() replaces $view with the rendered result, so keep the
    // executable to read its rows.
    $executable = $view;
    $view->setExposedInput(['search' => $search]);
    $manager->setupView($view, json_encode($extra + [
      'filters' => ['types' => ['profile']],
      'exposed_filter_options' => ['show_search_filter' => 'show_search_filter'],
      'sort_by' => 'field_last_name:ASC',
      'display' => 'all',
      'limit' => 0,
      'view_mode' => 'card',
    ]));
    return array_map(fn ($row) => (int) $row->nid, $executable->result);
  }

  /**
   * The shown scope searches the department only once it is shown.
   *
   * @covers ::setupView
   */
  public function testShownScopeFollowsTheDepartmentToggle() {
    $department = (int) $this->department->id();

    $this->assertSame([], $this->search('Astronomy'), 'A block saved before the scope existed does not search a hidden department.');
    $this->assertSame([], $this->search('Astronomy', ['search_scope' => 'shown']), 'Hidden department.');
    $this->assertSame([], $this->search('Astronomy', [
      'search_scope' => 'shown',
      'profile_field_options' => ['show_department' => 0],
    ]), 'Unchecked department.');
    $this->assertSame([$department], $this->search('Astronomy', [
      'search_scope' => 'shown',
      'profile_field_options' => ['show_department' => 'show_department'],
    ]), 'Shown department.');
  }

  /**
   * The shown scope always searches the position, which every card renders.
   *
   * @covers ::setupView
   */
  public function testShownScopeSearchesPosition() {
    $this->assertSame([(int) $this->position->id()], $this->search('Cryptanalyst'));
  }

  /**
   * The all scope searches hidden fields, the email included.
   *
   * @covers ::setupView
   */
  public function testAllScopeSearchesHiddenFields() {
    $all = ['search_scope' => 'all'];
    $this->assertSame([(int) $this->department->id()], $this->search('Astronomy', $all));
    $this->assertSame([(int) $this->email->id()], $this->search('zebrafish', $all));
    $this->assertSame([], $this->search('zebrafish', ['search_scope' => 'shown']), 'The shown scope never searches the email.');
  }

  /**
   * The shown scope searches the email once the card shows it.
   *
   * @covers ::setupView
   */
  public function testShownScopeSearchesShownEmail() {
    $this->assertSame([(int) $this->email->id()], $this->search('zebrafish', [
      'search_scope' => 'shown',
      'profile_field_options' => ['show_email' => 'show_email'],
    ]));
  }

  /**
   * A condensed listing searches only what its cards render.
   *
   * The condensed design shows the title and position, not the subtitle, and
   * ignores People options left over from an earlier design.
   *
   * @covers ::setupView
   */
  public function testCondensedShownScopeSkipsHiddenFields() {
    $condensed = ['view_mode' => 'condensed', 'search_scope' => 'shown'];
    $this->assertSame([(int) $this->subtitle->id()], $this->search('Semaphores'), 'A card listing searches the subtitle.');
    $this->assertSame([], $this->search('Semaphores', $condensed), 'A condensed listing does not.');
    $this->assertSame([], $this->search('Astronomy', $condensed + [
      'profile_field_options' => ['show_department' => 'show_department'],
    ]), 'A stale department flag is ignored.');
    $this->assertSame([(int) $this->position->id()], $this->search('Cryptanalyst', $condensed), 'The position is still searched.');
    $this->assertSame([(int) $this->subtitle->id()], $this->search('Semaphores', [
      'view_mode' => 'condensed',
      'search_scope' => 'all',
    ]), 'The all scope still searches it.');
  }

  /**
   * Titles are still searched, and an empty search lists everyone once.
   *
   * @covers ::setupView
   */
  public function testTitlesStillSearchedAndNoDuplicates() {
    $all = ['search_scope' => 'all'];
    $this->assertSame([(int) $this->email->id()], $this->search('Grace', $all));
    $this->assertSame([(int) $this->email->id()], $this->search('Grace'));

    $everyone = $this->search('', $all);
    sort($everyone);
    $expected = [
      (int) $this->department->id(),
      (int) $this->email->id(),
      (int) $this->position->id(),
      (int) $this->subtitle->id(),
    ];
    sort($expected);
    $this->assertSame($expected, $everyone);
  }

}
