<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests that gin_lb's stylesheet pulls in ys_layouts' color-scheme fix.
 *
 * The gin_lb module declares no color-scheme, so in Gin dark mode the browser
 * draws the native parts of every Layout Builder form control, such as
 * number-input stepper arrows, for a light background
 * (YaleSites-Internal#1672). The fix
 * rides on gin_lb/gin_lb through ys_layouts_library_info_alter() rather than
 * being attached per form, so this pins that wiring through the real library
 * discovery. If a gin_lb release renames the library, this fails instead of
 * the fix silently dropping off every block form.
 *
 * @group yalesites
 * @group ys_layouts
 */
class GinLbColorSchemeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'quick_node_clone',
    'formdazzle',
    'ys_themes',
    'ys_layouts',
    'gin_lb',
  ];

  /**
   * The gin_lb stylesheet library depends on the color-scheme library.
   */
  public function testGinLbLibraryDependsOnColorScheme(): void {
    /** @var \Drupal\Core\Asset\LibraryDiscoveryInterface $discovery */
    $discovery = $this->container->get('library.discovery');

    $gin_lb = $discovery->getLibraryByName('gin_lb', 'gin_lb');
    $this->assertNotEmpty($gin_lb['css'], 'gin_lb/gin_lb is still the library carrying gin_lb.css.');
    $this->assertContains('ys_layouts/gin_lb_color_scheme', $gin_lb['dependencies']);

    $color_scheme = $discovery->getLibraryByName('ys_layouts', 'gin_lb_color_scheme');
    $this->assertStringEndsWith('css/gin-lb-color-scheme.css', $color_scheme['css'][0]['data']);
  }

}
