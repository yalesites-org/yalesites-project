<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests the fail-closed update hook for sites left mid-migration.
 *
 * The removed automatic ai_engine cutover marked chat enabled before it had
 * provisioned an index, and relied on cron to retry. ys_beacon_update_10015()
 * clears that stranded flag so the stored state matches what the site actually
 * serves (yalesites-org/YaleSites-Internal#1459), and leaves every legitimate
 * state alone.
 *
 * @group ys_beacon
 */
class BeaconStrandedChatUpdateTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/ys_beacon.install';
  }

  /**
   * Puts a config factory and logger into the container for the update hook.
   *
   * @param \Drupal\Core\Config\Config $beacon
   *   The editable ys_beacon.settings config.
   * @param bool $expect_warning
   *   Whether the hook is expected to log a warning.
   */
  private function setContainer(Config $beacon, bool $expect_warning): void {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($beacon);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($expect_warning ? $this->once() : $this->never())->method('warning');

    $container = new ContainerBuilder();
    $container->set('config.factory', $factory);
    $container->set('logger.factory', $this->loggerFactory($logger));
    \Drupal::setContainer($container);
  }

  /**
   * A logger factory whose ys_beacon channel is the given logger.
   */
  private function loggerFactory(LoggerInterface $logger): object {
    $factory = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['get'])
      ->getMock();
    $factory->method('get')->with('ys_beacon')->willReturn($logger);
    return $factory;
  }

  /**
   * Builds a ys_beacon.settings double with the given stored values.
   *
   * @param array $values
   *   The stored settings values.
   * @param bool $is_new
   *   Whether the config object reports itself as not yet created.
   *
   * @return \Drupal\Core\Config\Config
   *   The config double.
   */
  private function settings(array $values, bool $is_new = FALSE): Config {
    $config = $this->createMock(Config::class);
    $config->method('isNew')->willReturn($is_new);
    $config->method('get')->willReturnCallback(fn (string $key) => $values[$key] ?? NULL);
    return $config;
  }

  /**
   * Chat marked on with no index is cleared and logged.
   */
  public function testClearsStrandedChatFlag(): void {
    $beacon = $this->settings([
      'enable_chat' => TRUE,
      'azure_index_name' => '',
      'read_only' => FALSE,
    ]);
    $beacon->expects($this->once())->method('set')->with('enable_chat', FALSE)->willReturnSelf();
    $beacon->expects($this->once())->method('save')->willReturnSelf();
    $this->setContainer($beacon, TRUE);

    ys_beacon_update_10015();
  }

  /**
   * A configured site keeps its chat widget.
   */
  public function testLeavesConfiguredSiteAlone(): void {
    $beacon = $this->settings([
      'enable_chat' => TRUE,
      'azure_index_name' => 'my-index',
      'read_only' => FALSE,
    ]);
    $beacon->expects($this->never())->method('set');
    $beacon->expects($this->never())->method('save');
    $this->setContainer($beacon, FALSE);

    ys_beacon_update_10015();
  }

  /**
   * A read-only borrower has no index of its own and is left alone.
   */
  public function testLeavesReadOnlyBorrowerAlone(): void {
    $beacon = $this->settings([
      'enable_chat' => TRUE,
      'azure_index_name' => '',
      'read_only' => TRUE,
    ]);
    $beacon->expects($this->never())->method('set');
    $beacon->expects($this->never())->method('save');
    $this->setContainer($beacon, FALSE);

    ys_beacon_update_10015();
  }

  /**
   * A site with chat already off is left alone.
   */
  public function testLeavesDisabledSiteAlone(): void {
    $beacon = $this->settings([
      'enable_chat' => FALSE,
      'azure_index_name' => '',
      'read_only' => FALSE,
    ]);
    $beacon->expects($this->never())->method('set');
    $beacon->expects($this->never())->method('save');
    $this->setContainer($beacon, FALSE);

    ys_beacon_update_10015();
  }

  /**
   * A site without Beacon settings is never given them.
   */
  public function testNeverCreatesSettings(): void {
    $beacon = $this->settings([], TRUE);
    $beacon->expects($this->never())->method('set');
    $beacon->expects($this->never())->method('save');
    $this->setContainer($beacon, FALSE);

    ys_beacon_update_10015();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    parent::tearDown();
    \Drupal::unsetContainer();
  }

}
