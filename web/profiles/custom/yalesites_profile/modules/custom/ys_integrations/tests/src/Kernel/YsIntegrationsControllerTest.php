<?php

namespace Drupal\Tests\ys_integrations\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ys_integrations\Controller\YsIntegrationsController;

/**
 * Tests the integrations admin overview controller.
 *
 * SystemAdminMenuBlockPage() reads the integration settings config and, for
 * every integration flagged on, adds that plugin's build() output to the
 * themed render array.
 *
 * The module ships no config schema for ys_integrations.integration_settings,
 * so strict schema checking is disabled here.
 *
 * @coversDefaultClass \Drupal\ys_integrations\Controller\YsIntegrationsController
 *
 * @group ys_integrations
 * @group yalesites
 */
class YsIntegrationsControllerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'ys_integrations',
    'ys_integrations_test',
  ];

  /**
   * {@inheritdoc}
   *
   * The ys_integrations.integration_settings config has no schema in the
   * module, so strict schema checking is disabled here; logged as a GAP.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * The name of the integration settings config object.
   */
  const SETTINGS = 'ys_integrations.integration_settings';

  /**
   * The controller under test.
   *
   * @var \Drupal\ys_integrations\Controller\YsIntegrationsController
   */
  protected $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->controller = YsIntegrationsController::create($this->container);
  }

  /**
   * The page uses the integrations block theme hook.
   *
   * @covers ::systemAdminMenuBlockPage
   */
  public function testPageUsesIntegrationsBlockTheme(): void {
    $output = $this->controller->systemAdminMenuBlockPage();
    $this->assertSame('ys_integrations_block', $output['#theme']);
    $this->assertArrayHasKey('#content', $output);
  }

  /**
   * An enabled integration contributes its build() output to the page.
   *
   * @covers ::systemAdminMenuBlockPage
   */
  public function testEnabledIntegrationIsBuilt(): void {
    $this->config(self::SETTINGS)->set('ys_integrations_test_built', 1)->save();

    $output = $this->controller->systemAdminMenuBlockPage();

    $this->assertArrayHasKey('ys_integrations_test_built', $output['#content']);
    $this->assertSame(
      'Built Test Integration',
      (string) $output['#content']['ys_integrations_test_built']['title']
    );
  }

  /**
   * An enabled integration with nothing to show contributes no card.
   *
   * The theme iterates every entry in #content and wraps each one in an
   * admin-item, so an empty build left in place renders as a blank card.
   * Plugins signal "show nothing" by returning an empty array, which is how
   * an integration that is turned on but not configured, or one that has been
   * superseded, opts out of the overview.
   *
   * @covers ::systemAdminMenuBlockPage
   */
  public function testEnabledIntegrationWithEmptyBuildIsSkipped(): void {
    $this->config(self::SETTINGS)->set('ys_integrations_test', 1)->save();

    $output = $this->controller->systemAdminMenuBlockPage();

    $this->assertArrayNotHasKey('ys_integrations_test', $output['#content']);
  }

  /**
   * A disabled integration is skipped.
   *
   * @covers ::systemAdminMenuBlockPage
   */
  public function testDisabledIntegrationIsSkipped(): void {
    $this->config(self::SETTINGS)->set('ys_integrations_test', 0)->save();

    $output = $this->controller->systemAdminMenuBlockPage();

    $this->assertArrayNotHasKey('ys_integrations_test', $output['#content']);
  }

}
