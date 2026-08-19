<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the item-count rule on the limited block types.
 *
 * Regression test for yalesites-org/YaleSites-Internal#1564. The rule used to
 * live in js/block-form.js, which called setCustomValidity() on the first
 * matching input from a hand-maintained selector list. For Quick Links that
 * list led with the Component Title, so "Number of links must be between 3 and
 * 9" was reported on field_heading while field_links carried nothing at all —
 * a native browser bubble with no visible error text, no aria-describedby, and
 * nothing announced to a screen reader (WCAG 2.1 AA 3.3.1).
 *
 * Validating on the entity instead ties the violation to the field the message
 * is about, which is what lets Drupal flag the right widget.
 *
 * @group ys_core
 */
class BlockItemCountRangeConstraintTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'link',
    'block_content',
    'ys_core',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('block_content');
    $this->createBlockType('quick_links', 'field_links', 'link');
  }

  /**
   * Creates a block content type carrying a single multi-value field.
   */
  protected function createBlockType(string $bundle, string $field_name, string $type, int $cardinality = FieldStorageConfig::CARDINALITY_UNLIMITED): void {
    BlockContentType::create([
      'id' => $bundle,
      'label' => $bundle,
    ])->save();

    if (!FieldStorageConfig::loadByName('block_content', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'block_content',
        'type' => $type,
        'cardinality' => $cardinality,
      ])->save();
    }

    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'block_content',
      'bundle' => $bundle,
      'label' => $field_name,
    ])->save();
  }

  /**
   * Builds an unsaved Quick Links block holding the given number of links.
   */
  protected function quickLinksBlock(int $count): BlockContent {
    $links = [];
    for ($i = 0; $i < $count; $i++) {
      $links[] = ['uri' => 'https://example.com/' . $i, 'title' => 'Link ' . $i];
    }
    return BlockContent::create([
      'type' => 'quick_links',
      'info' => 'Test quick links',
      'field_links' => $links,
    ]);
  }

  /**
   * Too few links is reported against the links field, not the title.
   */
  public function testTooFewLinksIsFlaggedOnTheLinksField(): void {
    $violations = $this->quickLinksBlock(1)->validate();

    $this->assertCount(1, $violations, 'One link must be rejected.');
    $this->assertSame(
      'field_links',
      $violations[0]->getPropertyPath(),
      'The violation must name the links field, not the component title.'
    );
    $this->assertSame(
      'Number of links must be between 3 and 9. Number of links added: 1.',
      (string) $violations[0]->getMessage()
    );
  }

  /**
   * Too many links is reported against the links field too.
   */
  public function testTooManyLinksIsFlaggedOnTheLinksField(): void {
    $violations = $this->quickLinksBlock(10)->validate();

    $this->assertCount(1, $violations);
    $this->assertSame('field_links', $violations[0]->getPropertyPath());
    $this->assertSame(
      'Number of links must be between 3 and 9. Number of links added: 10.',
      (string) $violations[0]->getMessage()
    );
  }

  /**
   * A block inside the allowed range validates cleanly.
   */
  public function testCountWithinRangeValidates(): void {
    $this->assertCount(0, $this->quickLinksBlock(3)->validate());
    $this->assertCount(0, $this->quickLinksBlock(9)->validate());
  }

  /**
   * A field with no upper bound gets the "or more" wording.
   *
   * Media Grid and Gallery have no maximum, so they take the other message
   * branch entirely; without this, a typo there would ship green.
   */
  public function testFieldWithNoMaximumUsesTheMinimumOnlyMessage(): void {
    $this->createBlockType('media_grid', 'field_media_grid_items', 'string');

    $block = BlockContent::create([
      'type' => 'media_grid',
      'info' => 'Test media grid',
      'field_media_grid_items' => ['one'],
    ]);
    $violations = $block->validate();

    $this->assertCount(1, $violations);
    $this->assertSame('field_media_grid_items', $violations[0]->getPropertyPath());
    $this->assertSame(
      'Number of media grid items must be 2 or more. Number of media grid items added: 1.',
      (string) $violations[0]->getMessage()
    );
  }

  /**
   * The spare empty row the widget leaves behind is not counted as a link.
   *
   * This is the behaviour the deleted JS hand-rolled as onlyCountFilledLinkRows
   * and the reason core's Count constraint was not reused, so it needs holding
   * down rather than being left to a comment. Only the count rule is asserted
   * here: an unfilled row draws core's own per-item "The path '' is invalid."
   * on the delta, which a real submit never reaches because the widget drops
   * empty rows before validating.
   */
  public function testEmptyDeltasDoNotCountTowardTheTotal(): void {
    $block = $this->quickLinksBlock(3);
    $block->get('field_links')->appendItem(['uri' => '', 'title' => '']);

    $field_level = [];
    foreach ($block->validate() as $violation) {
      if ($violation->getPropertyPath() === 'field_links') {
        $field_level[] = (string) $violation->getMessage();
      }
    }

    $this->assertSame(
      [],
      $field_level,
      'Three real links plus an unfilled row is still three links.'
    );
  }

  /**
   * Every limited block type constrains the field its message is about.
   *
   * The sibling blocks grouped with quick_links shared the misplacement, so
   * they are covered by the same rule rather than left to the selector list.
   */
  public function testEveryLimitedBlockTypeConstrainsItsCountedField(): void {
    // Tabs caps its items in field storage rather than in the rules table, so
    // its expected max is the cardinality the hook reads back off the field.
    $unlimited = FieldStorageConfig::CARDINALITY_UNLIMITED;
    $expected = [
      'quick_links' => ['field_links', $unlimited, ['min' => 3, 'max' => 9, 'itemLabel' => 'links']],
      'tabs' => ['field_tabs', 5, ['min' => 2, 'max' => 5, 'itemLabel' => 'tabs']],
      'media_grid' => [
        'field_media_grid_items',
        $unlimited,
        ['min' => 2, 'max' => NULL, 'itemLabel' => 'media grid items'],
      ],
      'gallery' => [
        'field_gallery_items',
        $unlimited,
        ['min' => 2, 'max' => NULL, 'itemLabel' => 'gallery items'],
      ],
    ];

    foreach ($expected as $bundle => [$field_name, $cardinality, $options]) {
      if ($bundle !== 'quick_links') {
        $this->createBlockType($bundle, $field_name, 'string', $cardinality);
      }

      $definitions = \Drupal::service('entity_field.manager')
        ->getFieldDefinitions('block_content', $bundle);
      $constraints = $definitions[$field_name]->getConstraints();

      $this->assertArrayHasKey(
        'YsItemCountRange',
        $constraints,
        "$bundle.$field_name must carry the item-count rule."
      );
      $this->assertSame($options, $constraints['YsItemCountRange']);
    }
  }

  /**
   * The rule is scoped to the bundle, not to the field name alone.
   */
  public function testUnrelatedBundleIsNotConstrained(): void {
    $this->createBlockType('banner', 'field_links', 'link');

    $definitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('block_content', 'banner');

    $this->assertArrayNotHasKey(
      'YsItemCountRange',
      $definitions['field_links']->getConstraints()
    );
  }

}
