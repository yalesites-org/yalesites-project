<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_views_basic\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Tests the save-time message for future-dated published posts.
 *
 * @group yalesites
 * @group ys_views_basic
 */
class PostFutureDateMessageTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'datetime',
    'node',
    'path_alias',
    'views',
    'ys_views_basic',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'field', 'filter']);
    NodeType::create(['type' => 'post', 'name' => 'Post'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
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
  }

  /**
   * Saves a post and returns the status messages it produced.
   */
  private function saveAndGetMessages(string $date, int $status = 1): array {
    \Drupal::messenger()->deleteAll();
    Node::create([
      'type' => 'post',
      'title' => 'Test',
      'status' => $status,
      'field_publish_date' => $date,
    ])->save();
    return array_map('strval', \Drupal::messenger()->messagesByType('status'));
  }

  /**
   * A past date gives no message.
   */
  public function testPastDateNoMessage(): void {
    $this->assertSame([], $this->saveAndGetMessages(date('Y-m-d', strtotime('-3 days'))));
  }

  /**
   * Today gives no message, because today's posts show in lists.
   */
  public function testTodayNoMessage(): void {
    $this->assertSame([], $this->saveAndGetMessages(date('Y-m-d')));
  }

  /**
   * A future date on a published post gives a message with the date.
   */
  public function testFutureDateMessage(): void {
    $future = date('Y-m-d', strtotime('+5 days'));
    $messages = $this->saveAndGetMessages($future);
    $this->assertCount(1, $messages);
    $this->assertStringContainsString('will not appear in Views', $messages[0]);
    $this->assertStringContainsString(date('F j, Y', strtotime($future)), $messages[0]);
  }

  /**
   * An unpublished future post gives no message, and updates also warn.
   */
  public function testUnpublishedSilentAndUpdateWarns(): void {
    $future = date('Y-m-d', strtotime('+5 days'));
    $this->assertSame([], $this->saveAndGetMessages($future, 0));
    $node = Node::create(['type' => 'post', 'title' => 'T', 'status' => 0, 'field_publish_date' => $future]);
    $node->save();
    \Drupal::messenger()->deleteAll();
    $node->setPublished()->save();
    $this->assertCount(1, \Drupal::messenger()->messagesByType('status'));
  }

}
