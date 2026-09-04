<?php

namespace Drupal\Tests\ys_mathjax\Kernel;

use Drupal\Core\Serialization\Yaml;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Tests that MathJax is served from this site rather than a third-party CDN.
 *
 * MathJax pulls its config, output jax, font data and extensions at runtime
 * through its own loader, and those follow-up requests carry no integrity
 * hashes. Serving the entry point from a third-party CDN therefore hands that
 * origin arbitrary JavaScript execution on every public page containing math,
 * and a subresource integrity hash on the entry point would not cover it. The
 * platform ships `use_cdn: 0` so every MathJax asset comes from the site
 * domain; this asserts the config a site actually runs with still does that.
 *
 * @group ys_mathjax
 * @group yalesites
 */
class MathjaxSelfHostedTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['mathjax'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The shipped config is what a site actually runs with, so it — not the
    // contrib module's install defaults — is what this test asserts against.
    $path = \Drupal::root() . '/profiles/custom/yalesites_profile/config/sync/mathjax.settings.yml';
    $settings = Yaml::decode(file_get_contents($path));
    unset($settings['_core']);
    $this->config('mathjax.settings')->setData($settings)->save();
  }

  /**
   * The shipped config disables the CDN and resolves the library locally.
   */
  public function testShippedConfigServesMathjaxLocally(): void {
    $this->assertSame(0, $this->config('mathjax.settings')->get('use_cdn'), 'mathjax.settings must ship with use_cdn disabled so no third-party origin serves MathJax.');

    $library = \Drupal::service('library.discovery')->getLibraryByName('mathjax', 'source');
    $this->assertNotFalse($library, 'The mathjax/source library should be discoverable.');
    $this->assertStringStartsWith('/libraries/MathJax/MathJax.js', $library['js'][0]['data'], 'The MathJax entry point should be a site-relative path, not a URL to another origin.');
  }

}
