<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\ys_core\Plugin\Field\FieldWidget\HeadingLinksWidget;

/**
 * Tests HeadingLinksWidget's create/update/delete cta_link paragraph flow.
 *
 * The widget under test (#1318 item 1) replaces the stock paragraphs
 * widget's add/collapse/drag ceremony on field_heading_links with up to N
 * flat link rows, but the
 * underlying data model is unchanged: each filled row is still a cta_link
 * paragraph referenced via entity_reference_revisions. These tests exercise
 * that create/update/delete logic directly against real entity storage
 * (a Kernel test, not a Unit test, since it has to be) rather than through
 * a full HTML form submission.
 *
 * @group ys_core
 */
class HeadingLinksWidgetTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'link',
    'text',
    'filter',
    'entity_reference_revisions',
    'paragraphs',
    'block_content',
    'linkit',
    'ys_core',
  ];

  /**
   * The field_heading_links-equivalent field definition under test.
   *
   * @var \Drupal\field\Entity\FieldConfig
   */
  protected FieldConfig $fieldConfig;

  /**
   * The widget instance under test.
   *
   * @var \Drupal\ys_core\Plugin\Field\FieldWidget\HeadingLinksWidget
   */
  protected HeadingLinksWidget $widget;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('block_content');
    $this->installSchema('system', ['sequences']);

    ParagraphsType::create(['id' => 'cta_link', 'label' => 'CTA Link'])->save();

    $link_storage = FieldStorageConfig::create([
      'field_name' => 'field_link',
      'entity_type' => 'paragraph',
      'type' => 'link',
      'cardinality' => 1,
    ]);
    $link_storage->save();
    FieldConfig::create([
      'field_storage' => $link_storage,
      'bundle' => 'cta_link',
      'label' => 'Link',
      'required' => TRUE,
      'settings' => [
        'title' => 2,
        'link_type' => 17,
      ],
    ])->save();

    BlockContentType::create(['id' => 'test_bundle', 'label' => 'Test'])->save();

    $heading_links_storage = FieldStorageConfig::create([
      'field_name' => 'field_heading_links',
      'entity_type' => 'block_content',
      'type' => 'entity_reference_revisions',
      'cardinality' => 3,
      'settings' => ['target_type' => 'paragraph'],
    ]);
    $heading_links_storage->save();
    $this->fieldConfig = FieldConfig::create([
      'field_storage' => $heading_links_storage,
      'bundle' => 'test_bundle',
      'label' => 'Links',
      'settings' => [
        'handler' => 'default:paragraph',
        'handler_settings' => [
          'target_bundles' => ['cta_link' => 'cta_link'],
        ],
      ],
    ]);
    $this->fieldConfig->save();

    $this->widget = $this->container->get('plugin.manager.field.widget')->getInstance([
      'field_definition' => $this->fieldConfig,
      'configuration' => [
        'type' => 'heading_links_inline',
        'settings' => [],
        'third_party_settings' => [],
      ],
    ]);
  }

  /**
   * True only for a field shaped exactly like field_heading_links.
   *
   * @covers ::isApplicable
   */
  public function testIsApplicableForMatchingField() {
    $this->assertTrue(HeadingLinksWidget::isApplicable($this->fieldConfig));
  }

  /**
   * False for an unlimited-cardinality paragraph reference field.
   *
   * The widget hardcodes "render exactly $cardinality rows," which only
   * makes sense for a small, fixed cardinality — an unlimited field would
   * need the stock widget's add/remove behavior instead.
   *
   * @covers ::isApplicable
   */
  public function testIsApplicableFalseForUnlimitedCardinality() {
    $storage = FieldStorageConfig::create([
      'field_name' => 'field_other_links',
      'entity_type' => 'block_content',
      'type' => 'entity_reference_revisions',
      'cardinality' => -1,
      'settings' => ['target_type' => 'paragraph'],
    ]);
    $storage->save();
    $field = FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => 'test_bundle',
      'label' => 'Other links',
      'settings' => [
        'handler' => 'default:paragraph',
        'handler_settings' => ['target_bundles' => ['cta_link' => 'cta_link']],
      ],
    ]);
    $field->save();

    $this->assertFalse(HeadingLinksWidget::isApplicable($field));
  }

  /**
   * False for a paragraph reference field allowing more than one bundle.
   *
   * The widget hardcodes the single allowed bundle's own field (field_link)
   * directly, so it can't handle a field where the editor could pick
   * between several paragraph types.
   *
   * @covers ::isApplicable
   */
  public function testIsApplicableFalseForMultipleBundles() {
    ParagraphsType::create(['id' => 'other_type', 'label' => 'Other'])->save();

    $storage = FieldStorageConfig::create([
      'field_name' => 'field_multi_bundle',
      'entity_type' => 'block_content',
      'type' => 'entity_reference_revisions',
      'cardinality' => 3,
      'settings' => ['target_type' => 'paragraph'],
    ]);
    $storage->save();
    $field = FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => 'test_bundle',
      'label' => 'Multi bundle',
      'settings' => [
        'handler' => 'default:paragraph',
        'handler_settings' => [
          'target_bundles' => ['cta_link' => 'cta_link', 'other_type' => 'other_type'],
        ],
      ],
    ]);
    $field->save();

    $this->assertFalse(HeadingLinksWidget::isApplicable($field));
  }

  /**
   * A filled blank row creates a new cta_link paragraph.
   *
   * @covers ::formElement
   * @covers ::massageFormValues
   */
  public function testMassageFormValuesCreatesNewParagraph() {
    $block = BlockContent::create(['type' => 'test_bundle', 'info' => 'Test Block']);
    $items = $block->get('field_heading_links');
    // A brand-new block has no field_heading_links values yet; real form
    // building (WidgetBase::formMultipleElements()) pads the item list up
    // to $cardinality empty items before calling formElement() for each
    // delta, so this does the same for delta 0 here.
    $items->appendItem();

    $form = ['#parents' => []];
    $form_state = new FormState();
    $rendered = $this->widget->formElement($items, 0, ['#field_parents' => []], $form, $form_state);
    $this->assertNull($rendered['existing_paragraph_id']['#value'], 'A brand-new row has no existing paragraph.');

    $values = [
      0 => [
        'uri' => 'https://example.com',
        'title' => 'Example',
        'attributes' => [],
        'existing_paragraph_id' => $rendered['existing_paragraph_id']['#value'],
      ],
    ];
    $massaged = $this->widget->massageFormValues($values, $form, $form_state);

    $this->assertCount(1, $massaged);
    $paragraph = $massaged[0]['entity'];
    $this->assertInstanceOf(ParagraphInterface::class, $paragraph);
    $this->assertSame('cta_link', $paragraph->bundle());
    $this->assertSame('https://example.com', $paragraph->get('field_link')->uri);
    $this->assertSame('Example', $paragraph->get('field_link')->title);
    $this->assertTrue($paragraph->isNew(), 'massageFormValues() hands back an unsaved entity; the host save cascade saves it.');
  }

  /**
   * Editing a filled row updates the same paragraph rather than a new one.
   *
   * @covers ::formElement
   * @covers ::massageFormValues
   */
  public function testMassageFormValuesUpdatesExistingParagraphInPlace() {
    $paragraph = Paragraph::create(['type' => 'cta_link']);
    $paragraph->set('field_link', ['uri' => 'https://old.example.com', 'title' => 'Old']);
    $paragraph->save();
    $original_id = $paragraph->id();

    $block = BlockContent::create([
      'type' => 'test_bundle',
      'info' => 'Test Block',
      'field_heading_links' => [
        ['target_id' => $paragraph->id(), 'target_revision_id' => $paragraph->getRevisionId()],
      ],
    ]);
    $block->save();

    $items = $block->get('field_heading_links');
    $form = ['#parents' => []];
    $form_state = new FormState();
    $rendered = $this->widget->formElement($items, 0, ['#field_parents' => []], $form, $form_state);
    $this->assertSame($original_id, $rendered['existing_paragraph_id']['#value'], 'The existing row carries its paragraph id forward.');

    $values = [
      0 => [
        'uri' => 'https://new.example.com',
        'title' => 'New',
        'attributes' => [],
        'existing_paragraph_id' => $rendered['existing_paragraph_id']['#value'],
      ],
    ];
    $massaged = $this->widget->massageFormValues($values, $form, $form_state);

    $updated = $massaged[0]['entity'];
    $this->assertSame($original_id, $updated->id(), 'The same paragraph is reused, not duplicated.');
    $this->assertSame('https://new.example.com', $updated->get('field_link')->uri);
    $this->assertSame('New', $updated->get('field_link')->title);
    $this->assertTrue($updated->needsSave());
  }

  /**
   * Clearing a previously-filled row deletes its orphaned paragraph.
   *
   * Round-trip case: a row disabled/cleared shouldn't leave a dangling
   * cta_link paragraph nobody references any more.
   *
   * @covers ::massageFormValues
   */
  public function testMassageFormValuesDeletesOrphanedParagraphWhenRowCleared() {
    $paragraph = Paragraph::create(['type' => 'cta_link']);
    $paragraph->set('field_link', ['uri' => 'https://example.com', 'title' => 'Example']);
    $paragraph->save();
    $paragraph_id = $paragraph->id();

    $block = BlockContent::create([
      'type' => 'test_bundle',
      'info' => 'Test Block',
      'field_heading_links' => [
        ['target_id' => $paragraph->id(), 'target_revision_id' => $paragraph->getRevisionId()],
      ],
    ]);
    $block->save();

    $items = $block->get('field_heading_links');
    $form = ['#parents' => []];
    $form_state = new FormState();
    $rendered = $this->widget->formElement($items, 0, ['#field_parents' => []], $form, $form_state);

    $values = [
      0 => [
        'uri' => '',
        'title' => '',
        'attributes' => [],
        'existing_paragraph_id' => $rendered['existing_paragraph_id']['#value'],
      ],
    ];
    $massaged = $this->widget->massageFormValues($values, $form, $form_state);

    $this->assertSame([], $massaged, 'A blank row produces no field value to store.');

    $paragraph_storage = $this->container->get('entity_type.manager')->getStorage('paragraph');
    $paragraph_storage->resetCache([$paragraph_id]);
    $this->assertNull($paragraph_storage->load($paragraph_id), 'The orphaned paragraph was deleted, not left dangling.');
  }

  /**
   * A leftover blank row among filled ones is dropped entirely.
   *
   * Filling link 1 and link 3 but leaving link 2 blank should not error or
   * leave a gap — the block content field just ends up with two items.
   *
   * @covers ::massageFormValues
   */
  public function testMassageFormValuesSkipsBlankRowAmongFilledRows() {
    $form = ['#parents' => []];
    $form_state = new FormState();

    $values = [
      0 => [
        'uri' => 'https://one.example.com',
        'title' => 'One',
        'attributes' => [],
        'existing_paragraph_id' => NULL,
      ],
      1 => [
        'uri' => '',
        'title' => '',
        'attributes' => [],
        'existing_paragraph_id' => NULL,
      ],
      2 => [
        'uri' => 'https://three.example.com',
        'title' => 'Three',
        'attributes' => [],
        'existing_paragraph_id' => NULL,
      ],
    ];
    $massaged = $this->widget->massageFormValues($values, $form, $form_state);

    $this->assertCount(2, $massaged, 'Only the two filled rows produce a value.');
    $this->assertSame('https://one.example.com', $massaged[0]['entity']->get('field_link')->uri);
    $this->assertSame('https://three.example.com', $massaged[1]['entity']->get('field_link')->uri);
  }

}
