<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Plugin\Action\MetatagValueSetAction;

/**
 * Tests access control on the AI-indexing metatag action.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Plugin\Action\MetatagValueSetAction
 */
class MetatagValueSetActionAccessTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // allowedIfHasPermission()/entity access add cache contexts, validated
    // against the cache_contexts_manager service.
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $this->createMock(CacheContextsManager::class));
    \Drupal::setContainer($container);
  }

  /**
   * Builds the action with the chat toggle set.
   */
  private function action(bool $chatEnabled): MetatagValueSetAction {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->with('enable_chat')->willReturn($chatEnabled);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('ys_beacon.settings')->willReturn($settings);
    return new MetatagValueSetAction([], 'ys_beacon_test', [], $configFactory);
  }

  /**
   * Builds an entity whose 'update' access resolves to $updateAllowed.
   */
  private function entity(bool $updateAllowed): ContentEntityInterface {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('access')
      ->with('update', $this->anything(), TRUE)
      ->willReturn($updateAllowed ? AccessResult::allowed() : AccessResult::forbidden());
    return $entity;
  }

  /**
   * Builds an account with or without the manage permission.
   */
  private function account(bool $hasPermission): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->with('manage ys beacon settings')
      ->willReturn($hasPermission);
    return $account;
  }

  /**
   * Denied outright when the chat service is disabled.
   *
   * @covers ::access
   */
  public function testDeniedWhenChatDisabled(): void {
    $this->assertFalse($this->action(FALSE)->access($this->entity(TRUE), $this->account(TRUE)));
  }

  /**
   * Denied without the manage permission, even with entity update access.
   *
   * @covers ::access
   */
  public function testDeniedWithoutManagePermission(): void {
    $this->assertFalse($this->action(TRUE)->access($this->entity(TRUE), $this->account(FALSE)));
  }

  /**
   * Denied without entity update access, even with the permission.
   *
   * @covers ::access
   */
  public function testDeniedWhenUpdateForbidden(): void {
    $this->assertFalse($this->action(TRUE)->access($this->entity(FALSE), $this->account(TRUE)));
  }

  /**
   * Allowed with update access and the permission while chat is enabled.
   *
   * @covers ::access
   */
  public function testAllowedWithUpdateAndPermissionWhenEnabled(): void {
    $this->assertTrue($this->action(TRUE)->access($this->entity(TRUE), $this->account(TRUE)));
  }

}
