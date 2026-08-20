<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\SchemaCheckTestTrait;
use Drupal\Tests\ys_core\Traits\LegacyConfigFixtureTrait;

/**
 * Tests normalising settings values that predate the ys_core config schema.
 *
 * Adding a schema (issue #1563) only validates values written from then on.
 * Existing sites still store the shapes written before it: an empty string
 * where a managed_file only ever writes a list of file IDs, and an integer
 * where the environment indicator's install default ships a boolean. Nothing
 * rewrites them on its own, so ys_core_deploy_10008() is what brings them into
 * line - a deploy hook rather than a hook_update_N because it writes to active
 * config, and drush deploy runs updatedb before config:import.
 *
 * The legacy fixtures are written straight to the config storage rather than
 * saved through the config factory. Strict schema checking would refuse the
 * save - correctly, since the whole point is that these shapes are invalid -
 * and a direct write is also the more honest fixture: it reproduces what is
 * already sitting in an existing site's database rather than an impossible
 * save.
 *
 * @group ys_core
 * @group yalesites
 */
class SettingsShapeNormalizationTest extends KernelTestBase {

  use LegacyConfigFixtureTrait;
  use SchemaCheckTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'ys_core'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->requireDeployHooks();
  }

  /**
   * A pre-schema empty string becomes the empty list a managed_file writes.
   */
  public function testLegacyEmptyStringBecomesEmptyList(): void {
    $this->storeLegacy('ys_core.site', ['custom_favicon' => '']);
    $this->storeLegacy('ys_core.header_settings', ['site_name_image' => '']);

    $report = ys_core_normalize_settings_shapes();

    $this->assertSame([], $this->config('ys_core.site')->get('custom_favicon'));
    $this->assertSame([], $this->config('ys_core.header_settings')->get('site_name_image'));
    $this->assertCount(2, $report['changes']);
    $this->assertConfigSchemaByName('ys_core.site');
    $this->assertConfigSchemaByName('ys_core.header_settings');
  }

  /**
   * A scalar file ID is kept rather than discarded as a real upload.
   */
  public function testScalarFileIdBecomesSingleItemList(): void {
    $this->storeLegacy('ys_core.site', ['custom_favicon' => '9']);

    ys_core_normalize_settings_shapes();

    $this->assertSame([9], $this->config('ys_core.site')->get('custom_favicon'));
    $this->assertConfigSchemaByName('ys_core.site');
  }

  /**
   * The environment indicator's stored integer becomes the boolean it declares.
   */
  public function testLegacyIntegerBecomesBoolean(): void {
    $this->storeLegacy('ys_core.site', ['environment_indicator' => ['show' => 1]]);

    ys_core_normalize_settings_shapes();

    $this->assertTrue($this->config('ys_core.site')->get('environment_indicator.show'));
    $this->assertConfigSchemaByName('ys_core.site');
  }

  /**
   * A falsy integer survives as FALSE rather than being flipped to TRUE.
   *
   * This is the value that hides the banner, so turning it into TRUE would
   * reveal an environment indicator on every site that had switched it off.
   */
  public function testLegacyZeroBecomesFalseNotTrue(): void {
    $this->storeLegacy('ys_core.site', ['environment_indicator' => ['show' => 0]]);

    ys_core_normalize_settings_shapes();

    $this->assertFalse($this->config('ys_core.site')->get('environment_indicator.show'));
    $this->assertConfigSchemaByName('ys_core.site');
  }

  /**
   * Values already in the right shape are left alone, and a re-run is a no-op.
   */
  public function testAlreadyCorrectValuesAreUntouched(): void {
    $this->config('ys_core.site')
      ->set('custom_favicon', [9])
      ->set('environment_indicator.show', TRUE)
      ->save();
    $this->config('ys_core.header_settings')->set('site_name_image', [])->save();

    $this->assertSame([], ys_core_normalize_settings_shapes()['changes']);
    $this->assertSame([9], $this->config('ys_core.site')->get('custom_favicon'));
    $this->assertTrue($this->config('ys_core.site')->get('environment_indicator.show'));
  }

  /**
   * A key this site never stored is not invented by the normalisation.
   *
   * The ys_core.header_settings object ships as a config/install default that
   * is not in the profile's config/sync, so a site only gains it when its
   * settings form is first saved. Writing into an object that does not exist
   * yet would create it holding a single key and nothing else.
   */
  public function testAbsentKeysAreNotCreated(): void {
    $this->assertSame([], ys_core_normalize_settings_shapes()['changes']);
    $this->assertNull($this->config('ys_core.site')->get('custom_favicon'));
    $this->assertNull($this->config('ys_core.header_settings')->get('site_name_image'));
  }

  /**
   * A dry run reports what it would change without writing anything.
   */
  public function testDryRunReportsWithoutWriting(): void {
    $this->storeLegacy('ys_core.site', ['custom_favicon' => '']);

    $report = ys_core_normalize_settings_shapes(TRUE);

    $this->assertCount(1, $report['changes']);
    $this->assertSame('', $this->config('ys_core.site')->get('custom_favicon'));
  }

}
