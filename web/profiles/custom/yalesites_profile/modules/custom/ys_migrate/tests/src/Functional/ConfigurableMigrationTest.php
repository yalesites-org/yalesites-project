<?php

namespace Drupal\Tests\ys_migrate\Functional;

use Drupal\layout_builder\Section;

/**
 * Tests the configurable migration functionality end-to-end.
 *
 * @group ys_migrate
 */
class ConfigurableMigrationTest extends MigrationTestBase {

  /**
   * Test the complete migration workflow.
   */
  public function testCompleteMigrationWorkflow() {
    // Import the test block migration
    $this->runMigration('ys_test_blocks');

    // Verify blocks were created
    $this->assertBlocksCreated();

    // Import the test page migration
    $this->runMigration('ys_test_pages');

    // Verify pages were created with Layout Builder sections
    $this->assertPagesCreated();

    // Verify paragraph entities were created and linked
    $this->assertParagraphsCreated();

    // Verify Layout Builder sections contain correct blocks
    $this->assertLayoutBuilderSections();
  }

  /**
   * Test block field processing for different field types.
   */
  public function testBlockFieldProcessing() {
    $this->runMigration('ys_test_blocks');

    // Test text block
    $text_block = $this->loadBlockByInfo('Test Text Block');
    $this->assertNotNull($text_block);
    $this->assertEquals('text', $text_block->bundle());
    $this->assertStringContainsString('test text block', $text_block->get('field_text')->value);

    // Test accordion block with nested paragraphs
    $accordion_block = $this->loadBlockByInfo('Test Accordion Block');
    $this->assertNotNull($accordion_block);
    $this->assertEquals('accordion', $accordion_block->bundle());
    $this->assertEquals('Test FAQ Section', $accordion_block->get('field_heading')->value);
    
    // Verify accordion items were created
    $accordion_items = $accordion_block->get('field_accordion_items')->referencedEntities();
    $this->assertCount(3, $accordion_items);
    
    foreach ($accordion_items as $item) {
      $this->assertEquals('accordion_item', $item->bundle());
      $this->assertNotEmpty($item->get('field_heading')->value);
      
      // Verify nested text paragraphs
      $content_items = $item->get('field_content')->referencedEntities();
      $this->assertCount(1, $content_items);
      $this->assertEquals('text', $content_items[0]->bundle());
    }

    // Test custom cards block
    $cards_block = $this->loadBlockByInfo('Test Custom Cards Block');
    $this->assertNotNull($cards_block);
    $this->assertEquals('custom_cards', $cards_block->bundle());
    
    $card_items = $cards_block->get('field_cards')->referencedEntities();
    $this->assertCount(2, $card_items);
    
    foreach ($card_items as $card) {
      $this->assertEquals('custom_card', $card->bundle());
      $this->assertNotEmpty($card->get('field_heading')->value);
      $this->assertNotEmpty($card->get('field_text')->value);
      $this->assertNotEmpty($card->get('field_link')->uri);
    }
  }

  /**
   * Test paragraph creation and field processing.
   */
  public function testParagraphCreation() {
    $this->runMigration('ys_test_blocks');

    // Test facts block paragraphs
    $facts_block = $this->loadBlockByInfo('Test Facts Block');
    $facts_items = $facts_block->get('field_facts_items')->referencedEntities();
    
    $this->assertCount(3, $facts_items);
    
    $expected_facts = [
      ['100%', 'Test coverage goal'],
      ['5+', 'Block types tested'],
      ['10+', 'Paragraph entities created'],
    ];
    
    foreach ($facts_items as $index => $fact) {
      $this->assertEquals('facts_item', $fact->bundle());
      $this->assertEquals($expected_facts[$index][0], $fact->get('field_heading')->value);
      $this->assertEquals($expected_facts[$index][1], $fact->get('field_text')->value);
    }

    // Test tiles block with styling
    $tiles_block = $this->loadBlockByInfo('Test Tiles Block');
    $tile_items = $tiles_block->get('field_tiles')->referencedEntities();
    
    $this->assertCount(2, $tile_items);
    
    foreach ($tile_items as $tile) {
      $this->assertEquals('tile', $tile->bundle());
      $this->assertNotEmpty($tile->get('field_heading')->value);
      $this->assertNotEmpty($tile->get('field_text')->value);
      $this->assertNotEmpty($tile->get('field_style_color')->value);
      $this->assertNotEmpty($tile->get('field_link')->uri);
    }
  }

  /**
   * Test Layout Builder integration.
   */
  public function testLayoutBuilderIntegration() {
    $this->runMigration('ys_test_blocks');
    $this->runMigration('ys_test_pages');

    $homepage = $this->loadNodeByTitle('Migration Test Homepage');
    $this->assertNotNull($homepage);

    // Get Layout Builder sections
    $sections = $homepage->get('layout_builder__layout')->getSections();
    $this->assertCount(4, $sections);

    // Test hero section (layout_onecol)
    $hero_section = $sections[0];
    $this->assertEquals('layout_onecol', $hero_section->getLayoutId());
    $components = $hero_section->getComponents();
    $this->assertCount(2, $components); // text + button

    // Test content section (layout_twocol)
    $content_section = $sections[1];
    $this->assertEquals('layout_twocol', $content_section->getLayoutId());
    $components = $content_section->getComponents();
    $this->assertCount(2, $components); // accordion + callout

    // Test feature section (layout_threecol_25_50_25)
    $feature_section = $sections[2];
    $this->assertEquals('layout_threecol_25_50_25', $feature_section->getLayoutId());
    $components = $feature_section->getComponents();
    $this->assertCount(3, $components); // facts + cards + gallery

    // Test tiles section (layout_onecol)
    $tiles_section = $sections[3];
    $this->assertEquals('layout_onecol', $tiles_section->getLayoutId());
    $components = $tiles_section->getComponents();
    $this->assertCount(1, $components); // tiles
  }

  /**
   * Test error handling and validation.
   */
  public function testErrorHandling() {
    // This would test various error conditions like:
    // - Invalid paragraph types
    // - Missing required fields
    // - Invalid field values
    // - Missing media references
    // We'll simulate these by creating a test migration with invalid data
    
    $this->markTestIncomplete('Error handling tests to be implemented');
  }


  /**
   * Assert that all test blocks were created.
   */
  protected function assertBlocksCreated() {
    $expected_blocks = [
      'Test Text Block',
      'Test Accordion Block',
      'Test Custom Cards Block',
      'Test Gallery Block',
      'Test Facts Block',
      'Test Tiles Block',
      'Test Callout Block',
      'Test Button Block',
    ];

    foreach ($expected_blocks as $block_info) {
      $block = $this->loadBlockByInfo($block_info);
      $this->assertNotNull($block, "Block '{$block_info}' was not created");
    }
  }

  /**
   * Assert that test pages were created.
   */
  protected function assertPagesCreated() {
    $expected_pages = [
      'Migration Test Homepage',
      'Migration Features Test Page',
      'Content Migration Test Page',
    ];

    foreach ($expected_pages as $page_title) {
      $page = $this->loadNodeByTitle($page_title);
      $this->assertNotNull($page, "Page '{$page_title}' was not created");
      $this->assertEquals('page', $page->bundle());
    }
  }

  /**
   * Assert that paragraph entities were created.
   */
  protected function assertParagraphsCreated() {
    // Count total paragraphs created
    $paragraph_count = \Drupal::entityQuery('paragraph')->accessCheck(FALSE)->count()->execute();
    
    // We expect:
    // - 3 accordion_item + 3 text (accordion)
    // - 2 custom_card (cards)
    // - 2 gallery_item (gallery)
    // - 3 facts_item (facts)
    // - 2 tile (tiles)
    // - 1 callout_item (callout)
    // Total: 16 paragraphs
    $this->assertGreaterThanOrEqual(16, $paragraph_count, 'Expected number of paragraph entities were not created');
  }

  /**
   * Assert Layout Builder sections are correct.
   */
  protected function assertLayoutBuilderSections() {
    $homepage = $this->loadNodeByTitle('Migration Test Homepage');
    $sections = $homepage->get('layout_builder__layout')->getSections();

    // Verify each section has the expected components
    foreach ($sections as $index => $section) {
      $this->assertInstanceOf(Section::class, $section);
      $components = $section->getComponents();
      $this->assertNotEmpty($components, "Section {$index} has no components");
      
      // Verify each component references a block
      foreach ($components as $component) {
        $configuration = $component->get('configuration');
        $this->assertStringStartsWith('inline_block:', $configuration['id']);
        $this->assertArrayHasKey('block_revision_id', $configuration);
      }
    }
  }

}