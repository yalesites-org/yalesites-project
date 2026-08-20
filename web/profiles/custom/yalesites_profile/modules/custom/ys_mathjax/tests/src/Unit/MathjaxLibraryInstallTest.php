<?php

namespace Drupal\Tests\ys_mathjax\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests the composer-installed MathJax library the platform self-hosts.
 *
 * Serving MathJax from a third-party CDN hands that origin arbitrary
 * JavaScript execution on every public page containing math: MathJax pulls its
 * config, output jax, font data and extensions at runtime through its own
 * loader, and none of those follow-up requests carry an integrity hash. The
 * platform therefore installs the library itself, and these assertions cover
 * the ways that install can be subtly wrong while still looking fine: the path
 * it lands at, the build it resolves its a11y extensions from, and the prune
 * that keeps the committed artifact from carrying the parts nothing loads.
 *
 * These assert build state rather than code: they read the composer-installed
 * tree under `web/libraries/`, which is gitignored. A failure here means the
 * checkout has not been built (run `composer install`) or the pin moved to a
 * version that no longer satisfies the constraint — not that module code broke.
 *
 * @group ys_mathjax
 * @group yalesites
 */
class MathjaxLibraryInstallTest extends UnitTestCase {

  /**
   * The library directory the contrib module hardcodes.
   *
   * `mathjax_library_info_build()` points at `/libraries/MathJax/MathJax.js`,
   * so a package installed under any other casing silently fails to load.
   */
  protected const LIBRARY_DIR = 'libraries/MathJax';

  /**
   * Upstream directories `ScriptHandler::pruneMathJaxLibrary()` removes.
   *
   * `docs/` only exists in some upstream builds, not in the pinned 2.7.9 dist.
   */
  protected const PRUNED_DIRS = [
    'unpacked',
    'test',
    'docs',
  ];

  /**
   * Directories MathJax loads at runtime, which the prune must leave alone.
   */
  protected const RUNTIME_DIRS = [
    'config',
    'extensions',
    'fonts',
    'jax',
    'localization',
  ];

  /**
   * The library is installed on disk at the casing the module expects.
   *
   * Composer must place the library at `web/libraries/MathJax`; a package that
   * lands at `web/libraries/mathjax` resolves to a 404 and math stops
   * rendering, with nothing in the page to say why.
   */
  public function testLibraryIsInstalledAtTheExpectedPath(): void {
    $this->assertFileExists($this->libraryPath('MathJax.js'), 'MathJax must be composer-installed to web/libraries/MathJax (capital M, capital J).');
  }

  /**
   * The installed build resolves its accessibility extensions locally.
   *
   * MathJax loads the combined config at runtime, and up to 2.7.0 that config
   * pulled the accessibility menu from `[Contrib]` — a path hardcoded to
   * cdn.mathjax.org — because the a11y extensions were not part of the
   * distribution. Self-hosting the entry point alone would therefore leave one
   * third-party script fetch behind. Builds from 2.7.1 on ship those
   * extensions and reference them under `[MathJax]`, so the version pin is
   * what actually removes the last external origin; this asserts the pinned
   * build is one of them.
   */
  public function testAccessibilityExtensionsAreBundled(): void {
    $this->assertFileExists($this->libraryPath('extensions/a11y/accessibility-menu.js'), 'The pinned MathJax build must bundle the a11y extensions rather than fetching them from cdn.mathjax.org.');

    $config = $this->libraryPath('config/TeX-AMS-MML_HTMLorMML.js');
    $this->assertFileExists($config);
    $this->assertStringContainsString('[MathJax]/extensions/a11y/accessibility-menu.js', file_get_contents($config), 'The combined MathJax config must load the accessibility menu from the local [MathJax] path, not from [Contrib] (cdn.mathjax.org).');
  }

  /**
   * The unused upstream directories are pruned from the installed tree.
   *
   * CI force-adds `web/libraries` into the Pantheon artifact, so anything
   * composer leaves in the package ships whether or not a page requests it.
   * `ScriptHandler::pruneMathJaxLibrary()` removes these after install, and
   * that comment carries the per-directory reasoning.
   */
  public function testUnusedUpstreamDirectoriesArePruned(): void {
    foreach (self::PRUNED_DIRS as $dir) {
      $this->assertDirectoryDoesNotExist($this->libraryPath($dir), sprintf('The composer prune must remove %s/ from the installed MathJax tree, because web/libraries is committed into the Pantheon artifact.', $dir));
    }
  }

  /**
   * The prune leaves every directory MathJax loads at runtime in place.
   *
   * Over-reach is the failure worth guarding: losing `jax/`, `fonts/` or
   * `config/` stops math rendering everywhere, and the only symptom is LaTeX
   * sitting on the page unrendered. Kept as its own test so that a failure
   * here cannot be masked by the removal assertions above.
   */
  public function testThePruneKeepsTheRuntimeTree(): void {
    foreach (self::RUNTIME_DIRS as $dir) {
      $this->assertDirectoryExists($this->libraryPath($dir), sprintf('The composer prune must not remove %s/ - MathJax loads it at runtime.', $dir));
    }
  }

  /**
   * Builds an absolute path to a file inside the installed MathJax library.
   */
  protected function libraryPath(string $relative): string {
    return DRUPAL_ROOT . '/' . self::LIBRARY_DIR . '/' . $relative;
  }

}
