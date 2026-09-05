<?php

namespace Drupal\Tests\ys_views_basic\Unit;

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
    foreach (['post_card', 'event_list_item', 'page_condensed', 'profile_directory'] as $bundle) {
      $suggestions = $this->suggestionsFor('inline_block', $bundle);
      $this->assertSame('block__inline_block__view', end($suggestions), "$bundle reuses the view template (highest priority).");
    }
  }

  /**
   * Non-listing inline blocks are left untouched.
   */
  public function testNonListingBlocksUnchanged() {
    // The calendar keeps its own template; plain blocks are unaffected.
    $this->assertNotContains('block__inline_block__view', $this->suggestionsFor('inline_block', 'event_calendar'));
    $this->assertNotContains('block__inline_block__view', $this->suggestionsFor('inline_block', 'text'));
  }

  /**
   * Non-inline-block providers are ignored.
   */
  public function testNonInlineBlockIgnored() {
    $this->assertNotContains('block__inline_block__view', $this->suggestionsFor('block_content', 'page_card'));
    $this->assertNotContains('block__inline_block__view', $this->suggestionsFor(NULL, NULL));
  }

}
