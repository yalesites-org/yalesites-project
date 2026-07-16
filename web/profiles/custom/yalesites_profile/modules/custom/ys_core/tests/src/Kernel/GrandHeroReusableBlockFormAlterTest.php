<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests ys_core_form_alter() on reusable vs inline block placements.
 *
 * Regression test for the bug where adding a reusable (Custom Block Library)
 * Grand Hero block to a page via Layout Builder failed on save with false
 * "… field is required" errors and logged an "Undefined array key block_form"
 * warning in ys_core_form_alter().
 *
 * For a reusable block placement (plugin id block_content:UUID) the entity
 * subform lives at $form['block_form'], not $form['settings']['block_form']
 * (that structure is only built for inline_block:* placements). The grand_hero
 * customization and its ys_core_grand_hero_validate handler assume the inline
 * settings.block_form structure, so they must not run for a reusable placement
 * — otherwise the validator reads empty values from the wrong path and flags
 * every required field as missing.
 *
 * @group ys_core
 */
class GrandHeroReusableBlockFormAlterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block_content',
    'ys_core',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('block_content');
    // ys_core_get_block_type() resolves a reusable placement's block type by
    // loading the block_content entity and reading its bundle, so only the
    // bundle needs to exist — no fields are required for this test.
    BlockContentType::create([
      'id' => 'grand_hero',
      'label' => 'Grand Hero',
    ])->save();
  }

  /**
   * Builds a layout builder block form state for a given block plugin id.
   *
   * The block plugin id is read by ys_core_get_block_type() from build-info
   * arg 3.
   */
  protected function blockFormState(string $plugin_id): FormState {
    $form_state = new FormState();
    $form_state->setBuildInfo(['args' => [NULL, NULL, NULL, $plugin_id]]);
    return $form_state;
  }

  /**
   * A reusable placement must not attach the inline-only grand_hero validator.
   *
   * Exercises both form IDs the guard governs (add + update block).
   */
  public function testReusablePlacementDoesNotAttachGrandHeroValidator(): void {
    $block = BlockContent::create([
      'type' => 'grand_hero',
      'info' => 'Reusable Grand Hero',
      'reusable' => 1,
    ]);
    $block->save();

    foreach (['layout_builder_add_block', 'layout_builder_update_block'] as $form_id) {
      // Reusable placement: no settings.block_form; the entity subform (when
      // shown) lives at the top-level block_form key.
      $form = [
        'settings' => [
          'label' => ['#default_value' => 'Grand Hero'],
        ],
        'block_form' => ['#block' => $block],
      ];
      $form_state = $this->blockFormState('block_content:' . $block->uuid());

      // The grand_hero branch must actually be reached: the block type resolves
      // to grand_hero, so it is the isset() guard — not a misclassification —
      // that keeps the inline-only validator off a reusable placement.
      $this->assertSame('grand_hero', ys_core_get_block_type($form, $form_state));

      ys_core_form_alter($form, $form_state, $form_id);

      $this->assertNotContains(
        'ys_core_grand_hero_validate',
        $form['#validate'] ?? [],
        "The grand_hero validate handler must not be attached for a reusable placement ($form_id)."
      );
    }
  }

  /**
   * An inline placement must still attach the grand_hero validator.
   */
  public function testInlinePlacementAttachesGrandHeroValidator(): void {
    $block = BlockContent::create([
      'type' => 'grand_hero',
      'info' => 'Inline Grand Hero',
      'reusable' => 0,
    ]);
    $block->save();

    // Inline placement: the entity subform lives under settings.block_form.
    $form = [
      'settings' => [
        'label' => ['#default_value' => 'Grand Hero'],
        'block_form' => ['#block' => $block],
      ],
    ];
    $form_state = $this->blockFormState('inline_block:grand_hero');

    $this->assertSame('grand_hero', ys_core_get_block_type($form, $form_state));

    ys_core_form_alter($form, $form_state, 'layout_builder_add_block');

    $this->assertContains(
      'ys_core_grand_hero_validate',
      $form['#validate'] ?? [],
      'The grand_hero validate handler must be attached for an inline block placement.'
    );
  }

}
