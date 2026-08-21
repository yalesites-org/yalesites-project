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
 * merely being viewed (issue #929). The group is now removed on every route,
 * including Layout Builder's: keeping it there surfaced nothing, because it is
 * attached inside the submission form rather than to the block, so it never
 * reached the block's contextual pencil. The submissions link editors need is
 * added to that pencil instead, and is covered by
 * \Drupal\Tests\ys_layouts\Unit\WebformResultsLinkBuilderTest.
 *
 * @coversDefaultClass \Drupal\ys_core\WebformContextualLinksSuppressor
 *
 * @group ys_core
 */
class WebformContextualLinksSuppressorTest extends UnitTestCase {

  /**
   * The webform group goes, whatever route the form was rendered on.
   *
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
