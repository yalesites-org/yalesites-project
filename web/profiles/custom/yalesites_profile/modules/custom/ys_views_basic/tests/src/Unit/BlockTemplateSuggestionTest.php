<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\block_content\BlockContentInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the listing-block template-suggestion reuse hook.
 *
 * The new listing bundles reuse atomic's block--inline-block--view.html.twig so
 * they render like the legacy "view" block instead of falling back to the
 * default field render (printed padding field, unstyled heading).
 *
 * @group yalesites
 */
class BlockTemplateSuggestionTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/../../../ys_views_basic.module';
  }

  /**
   * Invokes the suggestion hook for a given inline block.
   */
  private function suggestionsFor(?string $basePluginId, ?string $bundle): array {
    $suggestions = ['block__inline_block', 'block__inline_block__' . $bundle];
    $variables = [
      'elements' => [
        '#base_plugin_id' => $basePluginId,
        '#derivative_plugin_id' => $bundle,
      ],
    ];
    ys_views_basic_theme_suggestions_block_alter($suggestions, $variables);
    return $suggestions;
  }

  /**
   * Every listing bundle gets the shared view template suggestion, last.
   */
  public function testListingBundlesReuseViewTemplate() {
    foreach (['post_card', 'event_list_item', 'page_condensed', 'profile_card'] as $bundle) {
      $suggestions = $this->suggestionsFor('inline_block', $bundle);
      $this->assertSame('block__inline_block__view', end($suggestions), "$bundle reuses the view template (highest priority).");
    }
  }

  /**
   * Resource listings reuse the resource_view template instead (#1723).
   *
   * That template carries the ys-resource-view wrapper class the component
   * library styles differently from ys-view, so rendering the new bundles
   * through it is what keeps a migrated resource listing looking the same.
   */
  public function testResourceBundlesReuseResourceViewTemplate() {
    foreach (['resource_card', 'resource_portrait_grid', 'resource_list_item', 'resource_condensed'] as $bundle) {
      $suggestions = $this->suggestionsFor('inline_block', $bundle);
      $this->assertSame('block__inline_block__resource_view', end($suggestions), "$bundle reuses the resource_view template (highest priority).");
      $this->assertNotContains('block__inline_block__view', $suggestions, "$bundle does not also get the generic view template.");
    }
  }

  /**
   * The resource template reads its params under the old field's name.
   *
   * Atomic's block--inline-block--resource-view.html.twig prints
   * content.field_view_resource_params; the new bundles store their params in
   * field_view_params, so the preprocess hands the rendered field over under
   * the name the template expects.
   */
  public function testResourceBundlePreprocessRenamesParamsField() {
    $variables = [
      'base_plugin_id' => 'inline_block',
      'derivative_plugin_id' => 'resource_card',
      'content' => [
        'field_heading' => ['#markup' => 'Heading'],
        'field_view_params' => ['#markup' => 'Listing'],
      ],
    ];
    ys_views_basic_preprocess_block($variables);
    $this->assertSame(['#markup' => 'Listing'], $variables['content']['field_view_resource_params']);
    $this->assertArrayNotHasKey('field_view_params', $variables['content']);
    $this->assertSame(['#markup' => 'Heading'], $variables['content']['field_heading']);

    // Every other listing keeps field_view_params where its template reads it.
    $variables['derivative_plugin_id'] = 'post_card';
    $variables['content'] = ['field_view_params' => ['#markup' => 'Listing']];
    ys_views_basic_preprocess_block($variables);
    $this->assertArrayHasKey('field_view_params', $variables['content']);
    $this->assertArrayNotHasKey('field_view_resource_params', $variables['content']);
  }

  /**
   * A stale plugin id is overridden by the rendered block's real bundle.
   *
   * A node cached before deploy_10003 rewrote its layout still places the
   * migrated block as inline_block:resource_view. The block entity in the
   * build already carries its new bundle, so both shims decide from that.
   */
  public function testEntityBundleWinsOverStaleDerivative() {
    $block = $this->createMock(BlockContentInterface::class);
    $block->method('bundle')->willReturn('resource_card');
    $elements = [
      '#base_plugin_id' => 'inline_block',
      '#derivative_plugin_id' => 'resource_view',
      'content' => ['#block_content' => $block],
    ];

    $suggestions = ['block__inline_block', 'block__inline_block__resource_view'];
    ys_views_basic_theme_suggestions_block_alter($suggestions, ['elements' => $elements]);
    $this->assertSame('block__inline_block__resource_view', end($suggestions));
    $this->assertCount(3, $suggestions, 'The listing suggestion is appended.');

    $variables = [
      'elements' => $elements,
      'base_plugin_id' => 'inline_block',
      'derivative_plugin_id' => 'resource_view',
      'content' => ['field_view_params' => ['#markup' => 'Listing']],
    ];
    ys_views_basic_preprocess_block($variables);
    $this->assertSame(['#markup' => 'Listing'], $variables['content']['field_view_resource_params']);
    $this->assertArrayNotHasKey('field_view_params', $variables['content']);
  }

  /**
   * Non-listing inline blocks are left untouched.
   */
  public function testNonListingBlocksUnchanged() {
    // The calendar keeps its own template; plain blocks are unaffected.
    $this->assertNotContains('block__inline_block__view', $this->suggestionsFor('inline_block', 'event_calendar'));
    $this->assertNotContains('block__inline_block__view', $this->suggestionsFor('inline_block', 'text'));
    // The old resource_view bundle already has its own template by name.
    $this->assertSame(
      ['block__inline_block', 'block__inline_block__resource_view'],
      $this->suggestionsFor('inline_block', 'resource_view')
    );
  }

  /**
   * Non-inline-block providers are ignored.
   */
  public function testNonInlineBlockIgnored() {
    $this->assertNotContains('block__inline_block__view', $this->suggestionsFor('block_content', 'page_card'));
    $this->assertNotContains('block__inline_block__view', $this->suggestionsFor(NULL, NULL));
  }

}
