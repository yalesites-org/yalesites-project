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

/**
 * Tests that the post_publish_date filter hides future-dated posts.
 *
 * @group yalesites
 * @group ys_views_basic
 */
class PostPublishDateFilterTest extends ViewsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'text',
    'filter',
    'datetime',
    'node',
    'path_alias',
    'ys_views_basic',
    'ys_views_basic_test',
  ];

  /**
   * {@inheritdoc}
   */
  public static $testViews = ['ys_views_basic_test_publish_date'];

  /**
   * {@inheritdoc}
   */
  protected function setUp($import_test_views = TRUE): void {
    parent::setUp(FALSE);

    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'field', 'filter']);

    NodeType::create(['type' => 'post', 'name' => 'Post'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_publish_date',
      'entity_type' => 'node',
      'type' => 'datetime',
      'settings' => ['datetime_type' => 'date'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_publish_date',
      'entity_type' => 'node',
      'bundle' => 'post',
      'label' => 'Publish Date',
    ])->save();

    ViewTestData::createTestViews(static::class, ['ys_views_basic_test']);

    $this->createPost('Past post', date('Y-m-d', strtotime('-5 days')));
    $this->createPost('Today post', date('Y-m-d'));
    $this->createPost('Future post', date('Y-m-d', strtotime('+5 days')));
  }

  /**
   * Creates a published post with a given publish date.
   */
  private function createPost(string $title, string $publish_date): void {
    Node::create([
      'type' => 'post',
      'title' => $title,
      'status' => 1,
      'field_publish_date' => $publish_date,
    ])->save();
  }

  /**
   * Executes the fixture view and returns the resulting node titles.
   *
   * @param array $args
   *   View arguments; index 0 is the content-type machine name.
   *
   * @return string[]
   *   The titles of the nodes in the result set.
   */
  private function executedTitles(array $args): array {
    $view = Views::getView('ys_views_basic_test_publish_date');
    $this->executeView($view, $args);
    $titles = [];
    foreach ($view->result as $row) {
      $titles[] = $row->_entity->label();
    }
    return $titles;
  }

  /**
   * A post listing hides posts whose publish date is in the future.
   */
  public function testFutureDatedPostsExcludedForPostListing(): void {
    $titles = $this->executedTitles(['post']);
    $this->assertContains('Past post', $titles);
    $this->assertContains('Today post', $titles);
    $this->assertNotContains('Future post', $titles, 'A future-dated post must not appear in a post listing.');
  }

  /**
   * The filter is inert when the listing is not a post listing.
   */
  public function testFilterInertForNonPostListing(): void {
    $titles = $this->executedTitles(['page']);
    // A non-post listing must be untouched: the cutoff adds no condition, so
    // every post — including the future-dated one — remains.
    $this->assertContains('Past post', $titles);
    $this->assertContains('Today post', $titles);
    $this->assertContains('Future post', $titles, 'The publish-date cutoff must not affect non-post listings.');
  }

}
