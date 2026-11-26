<?php

namespace Drupal\Tests\ys_themes\Kernel;

use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Kernel tests for the inline CSS that ys_themes adds to every page head.
 *
 * @group ys_themes
 * @group yalesites
 */
class ThemesCssVariablesTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'ys_themes',
  ];

  /**
   * {@inheritdoc}
   *
   * Ys_themes.theme_settings ships without a schema file.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['ys_themes']);
  }

  /**
   * No custom property is built from a theme setting that does not exist.
   *
   * @covers ::ys_themes_build_css_variables
   */
  public function testNoMalformedCustomProperties(): void {
    $css = ys_themes_build_css_variables();

    $this->assertStringNotContainsString('var(--color-)', $css);
    $this->assertStringNotContainsString('var(--thickness-divider-)', $css);
    $this->assertStringContainsString('#environment-indicator { font-weight: normal !important; }', $css);
  }

}
