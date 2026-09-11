<?php

namespace Drupal\Tests\ys_alert\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_alert\AlertManager;
use Drupal\ys_alert\Plugin\Block\AlertBlock;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\ys_alert\Plugin\Block\AlertBlock
 *
 * @group yalesites
 * @group ys_alert
 */
class AlertBlockTest extends UnitTestCase {

  /**
   * The alert manager service mock.
   *
   * @var \Drupal\ys_alert\AlertManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $alertManager;

  /**
   * The AlertBlock plugin under test.
   *
   * @var \Drupal\ys_alert\Plugin\Block\AlertBlock
   */
  protected $alertBlock;

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    $this->alertManager = $this->createMock(AlertManager::class);

    $this->alertBlock = new AlertBlock(
      [],
      'alert_block',
      ['provider' => 'ys_alert', 'admin_label' => 'Alert block'],
      $this->alertManager
    );

    // AccessResult::allowedIfHasPermission() adds a cache context, which
    // asserts its tokens are valid against the cache_contexts_manager
    // service. Provide a minimal container so that assertion passes.
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * @covers ::build
   */
  public function testBuild() {
    $alert = [
      'id' => 1660263375,
      'status' => 1,
      'type' => 'announcement',
      'headline' => 'Optional banner for displaying an announcement',
      'message' => 'Additional text goes here',
      'link_title' => 'Yale',
      'link_url' => 'https://www.yale.edu',
    ];
    $this->alertManager->method('getAlert')->willReturn($alert);

    $expected = [
      '#theme' => 'ys_alert',
      '#id' => 1660263375,
      '#status' => 1,
      '#type' => 'announcement',
      '#headline' => 'Optional banner for displaying an announcement',
      '#message' => 'Additional text goes here',
      '#link_title' => 'Yale',
      '#link_url' => 'https://www.yale.edu',
    ];
    // Returns a render array built from the current alert data.
    $this->assertEquals($expected, $this->alertBlock->build());
  }

  /**
   * @covers ::blockAccess
   */
  public function testBlockAccessForbiddenWhenAlertDisabled() {
    $this->alertManager->method('showAlert')->willReturn(FALSE);
    $account = $this->createMock(AccountInterface::class);

    // The block is forbidden outright when there is no active alert,
    // regardless of the viewing account's permissions.
    $access = $this->invokeBlockAccess($account);
    $this->assertTrue($access->isForbidden());
  }

  /**
   * @covers ::blockAccess
   */
  public function testBlockAccessAllowedWithPermission() {
    $this->alertManager->method('showAlert')->willReturn(TRUE);
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->with('access content')->willReturn(TRUE);

    // Access is allowed when there is an active alert and the account has
    // the 'access content' permission.
    $access = $this->invokeBlockAccess($account);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * @covers ::blockAccess
   */
  public function testBlockAccessNotAllowedWithoutPermission() {
    $this->alertManager->method('showAlert')->willReturn(TRUE);
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->with('access content')->willReturn(FALSE);

    // Access is not granted when the account lacks the 'access content'
    // permission, even with an active alert.
    $access = $this->invokeBlockAccess($account);
    $this->assertFalse($access->isAllowed());
  }

  /**
   * @covers ::create
   */
  public function testCreate() {
    $container = new ContainerBuilder();
    $container->set('ys_alert.manager', $this->alertManager);

    $block = AlertBlock::create(
      $container,
      [],
      'alert_block',
      ['provider' => 'ys_alert', 'admin_label' => 'Alert block']
    );

    // The block is created with the alert manager service pulled from the
    // container.
    $this->assertInstanceOf(AlertBlock::class, $block);
    $reflection = new \ReflectionClass($block);
    $property = $reflection->getProperty('alertManager');
    $property->setAccessible(TRUE);
    $this->assertSame($this->alertManager, $property->getValue($block));
  }

  /**
   * Invokes the protected blockAccess() method via reflection.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  protected function invokeBlockAccess(AccountInterface $account) {
    $reflection = new \ReflectionClass($this->alertBlock);
    $method = $reflection->getMethod('blockAccess');
    $method->setAccessible(TRUE);
    return $method->invoke($this->alertBlock, $account);
  }

}
