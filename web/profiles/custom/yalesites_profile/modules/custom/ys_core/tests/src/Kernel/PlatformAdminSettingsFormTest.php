<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ys_core\Form\PlatformAdminSettingsForm;

/**
 * Tests the Platform Admin Settings page's section discovery and ordering.
 *
 * The page had no test of its own, so nothing verified that a contributed
 * PlatformAdminSetting plugin actually reaches it, that sections are namespaced
 * under the plugin id, or that the declared weights - not an accidental
 * alphabetical-by-label order - decide the layout
 * (yalesites-org/YaleSites-Internal#1560).
 *
 * Only ys_core is installed, so this asserts on ys_core's own plugins; other
 * modules (ys_beacon) contribute their own sections when enabled.
 *
 * @group ys_core
 * @group yalesites
 * @coversDefaultClass \Drupal\ys_core\Form\PlatformAdminSettingsForm
 */
class PlatformAdminSettingsFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The site branding section requires ys_media.media_manager, so ys_media has
   * to be listed explicitly - KernelTestBase only registers services for the
   * modules a test names, even ones ys_core declares as a dependency. The
   * service lived in ys_core until the module was extracted in
   * yalesites-org/YaleSites-Internal#579.
   */
  protected static $modules = ['system', 'user', 'views', 'twig_tweak', 'ys_media', 'ys_core'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Builds the page and returns its form array.
   */
  private function buildPage(): array {
    return PlatformAdminSettingsForm::create($this->container)->buildForm([], new FormState());
  }

  /**
   * The settings moved off Header settings render here instead.
   *
   * @covers ::buildForm
   */
  public function testMovedSiteBrandingFieldsRenderOnThePage(): void {
    $form = $this->buildPage();

    $this->assertArrayHasKey('site_branding', $form);
    $this->assertTrue($form['site_branding']['#tree']);
    $this->assertArrayHasKey('site_name_image', $form['site_branding']);
    $this->assertArrayHasKey('site_wide_branding_name', $form['site_branding']);
    $this->assertArrayHasKey('site_wide_branding_link', $form['site_branding']);
  }

  /**
   * The setting moved off Site settings renders here instead.
   *
   * @covers ::buildForm
   */
  public function testMovedEnvironmentIndicatorRendersOnThePage(): void {
    $form = $this->buildPage();

    $this->assertArrayHasKey('environment_indicator', $form);
    $this->assertArrayHasKey('environment_indicator_show', $form['environment_indicator']);
  }

  /**
   * The settings moved off Dashboard settings render here instead.
   *
   * @covers ::buildForm
   */
  public function testMovedAnnouncementsSourceFieldsRenderOnThePage(): void {
    $form = $this->buildPage();

    $this->assertArrayHasKey('announcements_source', $form);
    $this->assertArrayHasKey('announcements_source_enabled', $form['announcements_source']);
    $this->assertArrayHasKey('announcements_source_term', $form['announcements_source']);
  }

  /**
   * Sections are ordered by the declared plugin weight.
   *
   * @covers ::buildForm
   * @covers ::sortedDefinitions
   */
  public function testSectionsAreOrderedByDeclaredWeight(): void {
    $sections = array_diff(array_keys($this->buildPage()), ['actions']);

    $this->assertSame(
      ['site_branding', 'environment_indicator', 'announcements_feed', 'announcements_source'],
      array_values($sections),
    );
  }

}
