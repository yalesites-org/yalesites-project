<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the In-Line Message icon field wiring (issue #697).
 *
 * The In-Line Message block gains an editor-selectable icon by reusing the
 * shared icon mechanism built for Facts and Figures: a list_string field whose
 * options come from ys_core_facts_icon_allowed_values(), which delegates to the
 * FactsAndFiguresIconManager. This test guards that the callback serves a
 * block_content field (it was previously only wired to the facts_item
 * paragraph) and that a chosen icon round-trips.
 *
 * @group ys_core
 */
class InlineMessageIconFieldTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'block_content',
    'ys_core',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('block_content');

    BlockContentType::create([
      'id' => 'inline_message',
      'label' => 'In-Line Message',
    ])->save();

    // Mirrors config/sync/field.storage.block_content.field_icon.yml.
    FieldStorageConfig::create([
      'field_name' => 'field_icon',
      'entity_type' => 'block_content',
      'type' => 'list_string',
      'cardinality' => 1,
      'settings' => [
        'allowed_values' => [],
        'allowed_values_function' => 'ys_core_facts_icon_allowed_values',
      ],
    ])->save();

    // Mirrors field.field.block_content.inline_message.field_icon.yml.
    FieldConfig::create([
      'field_name' => 'field_icon',
      'entity_type' => 'block_content',
      'bundle' => 'inline_message',
      'label' => 'Icon',
      'required' => FALSE,
      'default_value' => [['value' => '_none']],
    ])->save();
  }

  /**
   * The bundle exposes an icon field backed by the shared icon option set.
   */
  public function testInlineMessageHasIconFieldWithSharedIconOptions(): void {
    $definitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('block_content', 'inline_message');
    $this->assertArrayHasKey('field_icon', $definitions);

    $field = $definitions['field_icon'];
    $this->assertSame('list_string', $field->getType());
    $this->assertSame(
      'ys_core_facts_icon_allowed_values',
      $field->getFieldStorageDefinition()->getSetting('allowed_values_function'),
      'The block icon field reuses the shared allowed-values callback.'
    );

    // The options resolve to exactly what the shared icon manager provides,
    // including the "- None -" option, regardless of the underlying manifest.
    $manager = \Drupal::service('ys_core.facts_and_figures_icon_manager');
    $actual = ys_core_facts_icon_allowed_values($field->getFieldStorageDefinition());
    $this->assertSame($manager->getFlatIconOptions(), $actual);
    $this->assertArrayHasKey('_none', $actual);
    $this->assertGreaterThan(
      1,
      count($actual),
      'The icon field offers more than just the "none" option.'
    );
  }

  /**
   * A chosen icon value persists on an In-Line Message block.
   */
  public function testChosenIconRoundTrips(): void {
    $options = ys_core_facts_icon_allowed_values(
      FieldStorageConfig::loadByName('block_content', 'field_icon')
    );
    $real_icon = NULL;
    foreach (array_keys($options) as $key) {
      if ($key !== '_none') {
        $real_icon = $key;
        break;
      }
    }
    $this->assertNotNull($real_icon, 'A non-none icon option is available.');

    $block = BlockContent::create([
      'type' => 'inline_message',
      'info' => 'Icon round-trip',
      'field_icon' => $real_icon,
    ]);
    $block->save();

    $reloaded = BlockContent::load($block->id());
    $this->assertSame($real_icon, $reloaded->get('field_icon')->value);
  }

}
