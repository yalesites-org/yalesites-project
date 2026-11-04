<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests that gin_lb's stylesheet pulls in the visible resize handle.
 *
 * Pins the ys_layouts_library_info_alter() wiring for
 * YaleSites-Internal#1812 through the real library discovery.
 *
 * @group yalesites
 * @group ys_layouts
 */
class OffCanvasResizeHandleTest extends KernelTestBase {

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
   * The gin_lb stylesheet library depends on the resize handle library.
   */
  public function testGinLbLibraryDependsOnResizeHandle(): void {
    /** @var \Drupal\Core\Asset\LibraryDiscoveryInterface $discovery */
    $discovery = $this->container->get('library.discovery');

    $gin_lb = $discovery->getLibraryByName('gin_lb', 'gin_lb');
    $this->assertContains('ys_layouts/off_canvas_resize_handle', $gin_lb['dependencies']);

    $handle = $discovery->getLibraryByName('ys_layouts', 'off_canvas_resize_handle');
    $this->assertNotFalse($handle, 'ys_layouts/off_canvas_resize_handle is defined.');
    $this->assertStringEndsWith('css/off-canvas-resize-handle.css', $handle['css'][0]['data']);
  }

}
