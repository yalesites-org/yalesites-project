<?php

namespace Drupal\Tests\ys_integrations\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_integrations\IntegrationPluginBase;
use Drupal\ys_integrations\IntegrationPluginInterface;

/**
 * Tests the default behavior provided by IntegrationPluginBase.
 *
 * The base class is concrete: integrations extend it and override the methods
 * they need. These tests characterize the defaults it hands to a subclass that
 * overrides nothing.
 *
 * @coversDefaultClass \Drupal\ys_integrations\IntegrationPluginBase
 *
 * @group ys_integrations
 * @group yalesites
 */
class IntegrationPluginBaseTest extends UnitTestCase {

  /**
   * The plugin under test.
   *
   * @var \Drupal\ys_integrations\IntegrationPluginBase
   */
  protected $plugin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $current_user = $this->createMock(AccountInterface::class);
    $this->plugin = new IntegrationPluginBase(
      $config_factory,
      ['id' => 'ys_integration_test'],
      $current_user,
    );
  }

  /**
   * The base class implements the integration plugin interface.
   */
  public function testImplementsInterface(): void {
    $this->assertInstanceOf(IntegrationPluginInterface::class, $this->plugin);
  }

  /**
   * Integrations are turned off unless a subclass says otherwise.
   *
   * @covers ::isTurnedOn
   */
  public function testIsTurnedOnReturnsFalseByDefault(): void {
    $this->assertFalse($this->plugin->isTurnedOn());
  }

  /**
   * The default build array is empty.
   *
   * @covers ::build
   */
  public function testBuildReturnsEmptyArray(): void {
    $this->assertSame([], $this->plugin->build());
  }

  /**
   * The config URL defaults to the shared integration settings route.
   *
   * @covers ::configUrl
   */
  public function testConfigUrlPointsToIntegrationSettings(): void {
    $url = $this->plugin->configUrl();
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame('ys_integrations.integrations_settings', $url->getRouteName());
  }

  /**
   * The sync URL defaults to the shared integration settings route.
   *
   * @covers ::syncUrl
   */
  public function testSyncUrlPointsToIntegrationSettings(): void {
    $url = $this->plugin->syncUrl();
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame('ys_integrations.integrations_settings', $url->getRouteName());
  }

  /**
   * The default save() is a no-op and returns nothing.
   *
   * @covers ::save
   */
  public function testSaveIsNoOp(): void {
    $form_state = $this->createMock(FormStateInterface::class);
    $form = [];
    $this->assertNull($this->plugin->save($form, $form_state));
  }

}
