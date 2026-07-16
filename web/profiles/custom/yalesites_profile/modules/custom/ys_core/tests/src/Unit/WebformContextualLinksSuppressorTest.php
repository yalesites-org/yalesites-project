<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\WebformContextualLinksSuppressor;

/**
 * Tests suppression of the Webform module's contextual "edit" links.
 *
 * Regression coverage for the bug where the Webform module's contextual links
 * group (Test / Results / Build / Settings) — including access to submission
 * data — appeared as an edit icon on Pre-Built Form blocks while a page was
 * merely being viewed, outside of Layout Builder's "Edit Layout and Content".
 * The links must stay only on Layout Builder editing routes and be removed on
 * every view route.
 *
 * @coversDefaultClass \Drupal\ys_core\WebformContextualLinksSuppressor
 *
 * @group ys_core
 */
class WebformContextualLinksSuppressorTest extends UnitTestCase {

  /**
   * @covers ::shouldSuppress
   *
   * @dataProvider routeProvider
   */
  public function testShouldSuppress(?string $route_name, bool $expected): void {
    $this->assertSame(
      $expected,
      WebformContextualLinksSuppressor::shouldSuppress($route_name)
    );
  }

  /**
   * Provides route names with the expected suppression decision.
   *
   * @return array
   *   Each case: [route name, expected shouldSuppress()].
   */
  public static function routeProvider(): array {
    return [
      // View routes suppress the leaking edit icon.
      'node canonical suppressed' => ['entity.node.canonical', TRUE],
      'standalone webform page suppressed' => ['entity.webform.canonical', TRUE],
      'front page suppressed' => ['view.frontpage.page_1', TRUE],
      'node preview suppressed' => ['entity.node.preview', TRUE],

      // Webform's own admin/test routes are suppressed too, by design: the same
      // links exist there as local-task tabs, so removing the contextual pencil
      // loses nothing (the target pages stay permission-gated regardless).
      'webform test form suppressed' => ['entity.webform.test_form', TRUE],
      'webform submission edit suppressed' => ['entity.webform_submission.edit_form', TRUE],

      // Layout Builder editing routes keep the links (Edit Layout and Content).
      'lb overrides view kept' => ['layout_builder.overrides.node.view', FALSE],
      'lb defaults view kept' => ['layout_builder.defaults.node.view', FALSE],
      'lb add block kept' => ['layout_builder.add_block', FALSE],
      'lb configure block kept' => ['layout_builder.configure_block', FALSE],

      // Defensive: no matched route removes the icon (fail closed on the leak).
      'null route suppressed' => [NULL, TRUE],
      'empty route suppressed' => ['', TRUE],
    ];
  }

  /**
   * @covers ::preRender
   */
  public function testPreRenderRemovesWebformGroup(): void {
    $element = [
      '#contextual_links' => [
        'webform' => ['route_parameters' => ['webform' => 'contact']],
        'layout_builder_block' => ['route_parameters' => ['foo' => 'bar']],
      ],
      '#markup' => 'form',
    ];

    $result = WebformContextualLinksSuppressor::preRender($element);

    $this->assertArrayNotHasKey('webform', $result['#contextual_links']);
    // Unrelated contextual-links groups are left untouched.
    $this->assertArrayHasKey('layout_builder_block', $result['#contextual_links']);
    $this->assertSame('form', $result['#markup']);
  }

  /**
   * @covers ::preRender
   */
  public function testPreRenderIsNoOpWhenGroupAbsent(): void {
    $element = ['#markup' => 'form'];

    $result = WebformContextualLinksSuppressor::preRender($element);

    $this->assertSame($element, $result);
  }

  /**
   * The pre-render callback must be trusted or the renderer throws.
   *
   * @covers ::trustedCallbacks
   */
  public function testPreRenderIsTrusted(): void {
    $this->assertContains(
      'preRender',
      WebformContextualLinksSuppressor::trustedCallbacks()
    );
  }

}
