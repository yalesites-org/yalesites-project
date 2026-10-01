<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Tests\UnitTestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * Tests the parts the authoring-form mockup preview renders per content type.
 *
 * @group ys_views_basic
 */
class MockupPreviewTemplateTest extends UnitTestCase {

  /**
   * Renders the preview template with a pass-through `t` filter.
   */
  protected function render(string $content_type, string $view_mode): string {
    $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
    $twig->addFilter(new TwigFilter('t', fn ($s) => $s));
    return $twig->render('views-basic-mockup-preview.html.twig', [
      'content_type' => $content_type,
      'view_mode' => $view_mode,
    ]);
  }

  /**
   * Resource listings render one part per "Resource options" checkbox.
   */
  public function testResourcePartsRender(): void {
    $html = $this->render('resource', 'portrait_grid');
    foreach (['teaser', 'discipline', 'journal-name', 'journal-issue', 'authors', 'publish-date'] as $part) {
      $this->assertSame(1, substr_count($html, "vb-preview__$part\""), "Resource preview renders vb-preview__$part once.");
    }
  }

  /**
   * Resource parts stay out of other content types' previews.
   */
  public function testResourcePartsOnlyForResources(): void {
    $html = $this->render('post', 'card');
    $this->assertStringNotContainsString('vb-preview__discipline', $html);
    $this->assertSame(1, substr_count($html, 'vb-preview__teaser"'));

    // Condensed resources offer no resource options and render no teaser.
    $html = $this->render('resource', 'condensed');
    $this->assertStringNotContainsString('vb-preview__discipline', $html);
    $this->assertStringNotContainsString('vb-preview__teaser', $html);
  }

}
