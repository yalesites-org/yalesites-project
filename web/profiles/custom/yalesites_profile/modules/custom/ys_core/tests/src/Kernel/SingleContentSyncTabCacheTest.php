<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests that the single content sync tab is hidden per user, cache-safely.
 *
 * The tab is removed for everyone but user 1, so the tab list varies by the
 * viewing account and must declare the `user` cache context.
 *
 * @group ys_core
 * @group yalesites
 */
class SingleContentSyncTabCacheTest extends YsKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'ys_core'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
  }

  /**
   * Builds local tasks data containing a single content sync tab.
   */
  protected function tabs(): array {
    return [
      'tabs' => [
        0 => [
          'entity.node.canonical' => ['#link' => []],
          'single_content_sync.export' => ['#link' => []],
        ],
      ],
    ];
  }

  /**
   * Runs the alter hook and returns the data and cacheability.
   */
  protected function runAlter(): array {
    $data = $this->tabs();
    $cacheability = new CacheableMetadata();
    ys_core_menu_local_tasks_alter($data, 'entity.node.canonical', $cacheability);
    return [$data, $cacheability];
  }

  /**
   * User 1 keeps the tab, another admin loses it, and both vary by user.
   */
  public function testTabVisibilityVariesByUser(): void {
    $first = $this->createUser([], NULL, TRUE);
    $this->assertEquals(1, $first->id());
    $this->setCurrentUser($first);
    [$data, $cacheability] = $this->runAlter();
    $this->assertArrayHasKey('single_content_sync.export', $data['tabs'][0]);
    $this->assertContains('user', $cacheability->getCacheContexts());

    $other = $this->createUser([], NULL, TRUE);
    $this->setCurrentUser($other);
    [$data, $cacheability] = $this->runAlter();
    $this->assertArrayNotHasKey('single_content_sync.export', $data['tabs'][0]);
    $this->assertArrayHasKey('entity.node.canonical', $data['tabs'][0]);
    $this->assertContains('user', $cacheability->getCacheContexts());
  }

}
