<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ys_core\Traits\LegacyConfigFixtureTrait;

/**
 * Tests that saving config a real site already holds still works.
 *
 * Adding a config schema (issue #1563) changes what Config::save() does: it
 * only casts values against a schema when one exists, so shapes that used to
 * pass straight through are now handed to
 * \Drupal\Core\Config\StorableConfigBase::castValue(). Two of the shapes
 * production databases hold reach that code, and they behave differently:
 *
 * - A legacy scalar meeting a `sequence` element is left alone, because
 *   castValue() only coerces for a PrimitiveInterface element and Sequence is
 *   not one. This is the backward-compatibility claim the schema rests on, and
 *   it is reachable: HeaderSettingsForm writes `site_name_image` only inside a
 *   ys_core_allow_secret_items() check, so a site_admin saving the header form
 *   saves an object still holding the legacy `''`.
 * - An array meeting the scalar `focus_header_image` element is NOT left
 *   alone: castValue() recurses and looks up `focus_header_image.0`, which
 *   does not exist under a string element, and throws an uncaught
 *   \InvalidArgumentException. That array shape is real - ys_core_module's
 *   render-path guard was added for it by a production hotfix - so anything
 *   that saves ys_core.header_settings on such a site aborts, including
 *   ys_core_deploy_10008().
 *
 * Strict schema checking is off here on purpose. ConfigSchemaChecker validates
 * the data POST-cast, so with it on it would correctly reject the legacy
 * scalar and the assertion could not be made at all - the point is what
 * production, which has no such checker, does with these values.
 *
 * @group ys_core
 * @group yalesites
 */
class LegacyConfigShapeSaveTest extends KernelTestBase {

  use LegacyConfigFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'ys_core'];

  /**
   * {@inheritdoc}
   *
   * The legacy shapes tested here are exactly the ones a schema checker
   * refuses, so leaving it on would fail the save for the wrong reason.
   *
   * DrupalPractice forbids this on the grounds that a module should declare
   * its schema properly instead - which is precisely what the rest of this
   * change does. The values under test predate that schema, so no declaration
   * can make them valid; ConfigSchemaChecker validates the data POST-cast, and
   * asserting what production does with an uncast legacy value is impossible
   * with a checker in the way. Suppressed for this class only.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->requireDeployHooks();
  }

  /**
   * A legacy scalar under a sequence element survives an unrelated save.
   *
   * This is the scenario a site_admin hits saving /admin/yalesites/header:
   * the form never writes site_name_image for them, so the legacy '' is still
   * in the object's data when Config::save() casts it.
   */
  public function testLegacyScalarUnderSequenceSurvivesSave(): void {
    $this->storeLegacy('ys_core.header_settings', ['site_name_image' => '']);

    $this->config('ys_core.header_settings')->set('nav_position', 'center')->save();

    $this->assertSame('center', $this->config('ys_core.header_settings')->get('nav_position'));
    $this->assertSame('', $this->config('ys_core.header_settings')->get('site_name_image'));
  }

  /**
   * An array focus_header_image is repaired instead of aborting the hook.
   *
   * The fixture is the combination a real site holds: the array the render
   * path guards against, alongside the legacy managed_file scalar the deploy
   * hook is there to normalise. Writing site_name_image first would serialise
   * the whole object - array included - and throw, so this also pins the order
   * the repairs run in.
   */
  public function testArrayFocusHeaderImageIsClearedByNormalization(): void {
    $this->storeLegacy('ys_core.header_settings', [
      'focus_header_image' => ['12'],
      'site_name_image' => '',
    ]);

    $report = ys_core_normalize_settings_shapes();

    $this->assertNull($this->config('ys_core.header_settings')->get('focus_header_image'));
    $this->assertSame([], $this->config('ys_core.header_settings')->get('site_name_image'));
    $this->assertCount(2, $report['changes']);
  }

  /**
   * Once repaired, saving the object again works and stays repaired.
   */
  public function testRepairedConfigCanBeSavedAgain(): void {
    $this->storeLegacy('ys_core.header_settings', ['focus_header_image' => ['12']]);

    ys_core_normalize_settings_shapes();
    $this->config('ys_core.header_settings')->set('nav_position', 'center')->save();

    $this->assertSame('center', $this->config('ys_core.header_settings')->get('nav_position'));
    $this->assertNull($this->config('ys_core.header_settings')->get('focus_header_image'));
    $this->assertSame([], ys_core_normalize_settings_shapes()['changes']);
  }

  /**
   * A dry run reports the array without clearing it.
   */
  public function testDryRunReportsArrayWithoutClearing(): void {
    $this->storeLegacy('ys_core.header_settings', ['focus_header_image' => ['12']]);

    $report = ys_core_normalize_settings_shapes(TRUE);

    $this->assertCount(1, $report['changes']);
    $this->assertSame(['12'], $this->config('ys_core.header_settings')->get('focus_header_image'));
  }

  /**
   * A scalar focus_header_image is a usable media ID and is left alone.
   */
  public function testScalarFocusHeaderImageIsUntouched(): void {
    $this->config('ys_core.header_settings')->set('focus_header_image', '42')->save();

    $this->assertSame([], ys_core_normalize_settings_shapes()['changes']);
    $this->assertSame('42', $this->config('ys_core.header_settings')->get('focus_header_image'));
  }

}
