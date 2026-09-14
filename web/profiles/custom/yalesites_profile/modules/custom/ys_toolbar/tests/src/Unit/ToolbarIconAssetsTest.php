<?php

namespace Drupal\Tests\ys_toolbar\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests that the toolbar icon overrides point at icons that exist.
 *
 * These overrides are plain CSS, so nothing in Drupal fails loudly when one
 * points at a missing file: the toolbar item just renders a blank square, which
 * is easy to miss in review and easier to miss in a screenshot nobody took.
 *
 * @group ys_toolbar
 * @group yalesites
 */
class ToolbarIconAssetsTest extends UnitTestCase {

  /**
   * The Platform Admin section does not reuse Gin's environment glyph.
   *
   * Gin maps .toolbar-icon-environment to its own `server` sprite, and
   * environment_indicator is enabled on Pantheon environments, so a server or
   * rack glyph here would read as the environment indicator sitting two items
   * away. A shield is unused by every icon in Gin's toolbar set, so it collides
   * with nothing.
   */
  public function testPlatformAdminIconIsNotServerGlyph(): void {
    $icon = $this->iconFor('toolbar-icon-ys-core-admin-platform-admin');

    $this->assertSame('shield.svg', basename($icon));
  }

  /**
   * Settings keeps its own distinct glyph.
   *
   * The two sections sit next to each other in the toolbar, so the point of
   * either override is lost if they ever converge on one icon.
   */
  public function testSettingsAndPlatformAdminIconsDiffer(): void {
    $this->assertNotSame(
      $this->iconFor('toolbar-icon-ys-core-admin-yalesites'),
      $this->iconFor('toolbar-icon-ys-core-admin-platform-admin')
    );
  }

  /**
   * Every icon the stylesheet references resolves to a real file.
   *
   * Both URL styles in this stylesheet are checked the way a browser would
   * resolve them, because they resolve differently: a relative url() resolves
   * against this stylesheet, while one inside a custom property resolves
   * against Gin's consuming stylesheet and so has to be root-relative. Writing
   * the wrong style, or renaming an icon without updating the rule, produces a
   * 404 rather than an error.
   */
  public function testEveryReferencedIconExists(): void {
    $urls = $this->iconUrls();
    $this->assertNotEmpty($urls, 'No icon url() found; the extraction regex is stale.');

    foreach ($urls as $url) {
      $this->assertFileExists($this->resolve($url), sprintf('Icon url("%s") does not resolve to a file.', $url));
    }
  }

  /**
   * No icon file sits in the module unreferenced by the stylesheet.
   *
   * An icon left behind after a swap looks like a live asset to the next person
   * reading the directory. Every icon here is referenced from this one
   * stylesheet; if one ever needs referencing from a template instead, this is
   * the test that should be taught about it rather than deleted.
   */
  public function testNoOrphanedIconFiles(): void {
    $referenced = array_map(
      fn (string $url): string => basename($url),
      $this->iconUrls()
    );
    $onDisk = array_map('basename', glob($this->moduleDir() . '/images/*.svg'));
    $orphans = array_values(array_diff($onDisk, $referenced));

    $this->assertSame([], $orphans, sprintf(
      'Unreferenced icon file(s) in images/: %s. Delete them, or reference them from ys_toolbar.theme.css.',
      implode(', ', $orphans)
    ));
  }

  /**
   * Returns the icon URL declared for a toolbar icon class.
   */
  private function iconFor(string $class): string {
    $css = file_get_contents($this->moduleDir() . '/css/ys_toolbar.theme.css');
    $pattern = sprintf('#\.%s\s*\{[^}]*?url\(\s*["\']?([^"\')]+)#s', preg_quote($class, '#'));

    $this->assertSame(1, preg_match($pattern, $css, $matches), sprintf('No icon rule found for .%s.', $class));

    return trim($matches[1]);
  }

  /**
   * Returns every icon URL referenced by the stylesheet.
   */
  private function iconUrls(): array {
    $css = file_get_contents($this->moduleDir() . '/css/ys_toolbar.theme.css');
    preg_match_all('#url\(\s*["\']?([^"\')]+\.svg)#', $css, $matches);

    return array_unique(array_map('trim', $matches[1]));
  }

  /**
   * Resolves a stylesheet URL to the path on disk a browser would fetch.
   */
  private function resolve(string $url): string {
    if (str_starts_with($url, '/')) {
      // Served from the web root, so that is what the path is checked against
      // rather than assuming the module's own directory.
      return $this->webRoot() . $url;
    }

    return $this->moduleDir() . '/css/' . $url;
  }

  /**
   * Returns the ys_toolbar module directory.
   */
  private function moduleDir(): string {
    // .../ys_toolbar/tests/src/Unit -> .../ys_toolbar.
    return dirname(__DIR__, 3);
  }

  /**
   * Returns the Drupal web root.
   */
  private function webRoot(): string {
    // .../web/profiles/custom/yalesites_profile/modules/custom/ys_toolbar.
    return dirname($this->moduleDir(), 6);
  }

}
