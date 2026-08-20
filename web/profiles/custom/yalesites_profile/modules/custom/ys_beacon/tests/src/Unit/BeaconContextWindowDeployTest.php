<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests the deploy hook that raises the stored model context window.
 *
 * The value cannot travel through config: ys_beacon.settings is config-ignored
 * (ys_beacon*) and absent from config/sync, so a raised default in
 * config/install reaches new installs only and config:import has nothing to
 * correct an existing site with. That makes this hook the only thing that moves
 * a live site off Haiku's 200k, which is worth pinning down.
 *
 * The interesting half is what it refuses to do: the routed model is a per-site
 * Portkey decision this code cannot observe, so an operator who set their own
 * window must keep it.
 *
 * @group ys_beacon
 */
class BeaconContextWindowDeployTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/ys_beacon.deploy.php';
  }

  /**
   * A site still on the old default is raised to the Sonnet 5 window.
   */
  public function testRaisesTheSupersededDefault(): void {
    $config = $this->createMock(Config::class);
    $config->method('get')->with('model_context_window')->willReturn(200000);
    $config->expects($this->once())
      ->method('set')
      ->with('model_context_window', 1000000)
      ->willReturnSelf();
    $config->expects($this->once())->method('save')->willReturnSelf();
    $this->containerWith($config);

    $this->assertSame(
      'Raised the Beacon model context window from 200000 to 1000000 tokens for Claude Sonnet 5.',
      (string) ys_beacon_deploy_10003(),
    );
  }

  /**
   * A window an operator chose deliberately is left alone.
   */
  public function testLeavesSiteSpecificWindowAlone(): void {
    $config = $this->createMock(Config::class);
    $config->method('get')->with('model_context_window')->willReturn(400000);
    $config->expects($this->never())->method('set');
    $config->expects($this->never())->method('save');
    $this->containerWith($config);

    $this->assertSame(
      'Beacon model context window left at its site-specific value (400000 tokens).',
      (string) ys_beacon_deploy_10003(),
    );
  }

  /**
   * A site without Beacon settings is skipped rather than seeded.
   */
  public function testSkipsWhenSettingsAreAbsent(): void {
    $config = $this->createMock(Config::class);
    $config->method('get')->with('model_context_window')->willReturn(NULL);
    $config->expects($this->never())->method('set');
    $config->expects($this->never())->method('save');
    $this->containerWith($config);

    $this->assertSame(
      'Beacon settings are absent on this site; the model context window was not changed.',
      (string) ys_beacon_deploy_10003(),
    );
  }

  /**
   * Builds the minimal container the hook needs.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The editable settings the hook will read and possibly write.
   */
  private function containerWith(Config $config): void {
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('getEditable')
      ->with('ys_beacon.settings')
      ->willReturn($config);

    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

}
