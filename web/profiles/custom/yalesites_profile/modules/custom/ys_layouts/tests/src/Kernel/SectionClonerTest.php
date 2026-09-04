<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\ys_layouts\Service\BlockCloner;
use Drupal\ys_layouts\Service\SectionCloner;

/**
 * Tests the SectionCloner service behind the "Clone section" action.
 *
 * Covers the issue #1638 acceptance criteria that are testable without a
 * browser: the copy keeps the original's layout, layout settings and section
 * colour / component-theme selection; every block is copied into the same
 * region in the same order; inline blocks are deep-copied while reusable blocks
 * are re-referenced; and no component UUID is reused, which is what would
 * corrupt the layout once it is saved.
 *
 * @group ys_layouts
 * @group yalesites
 *
 * @coversDefaultClass \Drupal\ys_layouts\Service\SectionCloner
 */
class SectionClonerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'text',
    'entity_reference_revisions',
    'paragraphs',
    'block_content',
    'layout_builder',
    'layout_discovery',
    'quick_node_clone',
  ];

  /**
   * The section cloner service under test.
   *
   * @var \Drupal\ys_layouts\Service\SectionCloner
   */
  protected SectionCloner $sectionCloner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('block_content');
    $this->installSchema('system', ['sequences']);

    ParagraphsType::create(['id' => 'gallery_item', 'label' => 'Gallery Item'])->save();
    BlockContentType::create(['id' => 'gallery', 'label' => 'Gallery'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_gallery_items',
      'entity_type' => 'block_content',
      'type' => 'entity_reference_revisions',
      'cardinality' => -1,
      'settings' => ['target_type' => 'paragraph'],
    ])->save();

    FieldConfig::create([
      'field_storage' => FieldStorageConfig::loadByName('block_content', 'field_gallery_items'),
      'bundle' => 'gallery',
      'label' => 'Gallery items',
      'settings' => [
        'handler' => 'default:paragraph',
        'handler_settings' => ['target_bundles' => ['gallery_item' => 'gallery_item']],
      ],
    ])->save();

    // Both services are instantiated directly rather than via the container so
    // the test does not have to enable ys_layouts (which pulls in
    // calendar_link / ys_localist). Both classes are autoloadable via PSR-4.
    $block_cloner = new BlockCloner(
      $this->container->get('quick_node_clone.entity.form_builder'),
      $this->container->get('uuid'),
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.factory')->get('ys_layouts'),
    );

    $this->sectionCloner = new SectionCloner(
      $block_cloner,
      $this->container->get('uuid'),
    );
  }

  /**
   * Builds a saved gallery inline-block component in the given region.
   *
   * @param string $region
   *   The region to place the component in.
   * @param int $weight
   *   The component weight, which fixes its order within the region.
   * @param string $label
   *   The component label.
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   An inline_block:gallery component keyed by block_revision_id.
   */
  protected function createGalleryComponent(string $region, int $weight, string $label): SectionComponent {
    $paragraph = Paragraph::create(['type' => 'gallery_item']);
    $paragraph->save();

    $block_content = BlockContent::create([
      'type' => 'gallery',
      'info' => $label,
      'field_gallery_items' => [
        [
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ],
      ],
    ]);
    $block_content->save();

    $component = new SectionComponent(
      $this->container->get('uuid')->generate(),
      $region,
      [
        'id' => 'inline_block:gallery',
        'block_revision_id' => $block_content->getRevisionId(),
        'block_serialized' => NULL,
        'label' => $label,
        'label_display' => 'visible',
        'view_mode' => 'full',
        'provider' => 'layout_builder',
        'context_mapping' => [],
      ]
    );

    return $component->setWeight($weight);
  }

  /**
   * Builds a non-inline (reusable-equivalent) component in the given region.
   *
   * A core block stands in for a placed reusable block: both resolve to a
   * plugin that is not an InlineBlock, which is the branch under test, and the
   * core block needs no extra content fixtures.
   *
   * @param string $region
   *   The region to place the component in.
   * @param int $weight
   *   The component weight, which fixes its order within the region.
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   A non-inline section component.
   */
  protected function createReusableComponent(string $region, int $weight): SectionComponent {
    $component = new SectionComponent(
      $this->container->get('uuid')->generate(),
      $region,
      ['id' => 'system_powered_by_block', 'label' => 'Powered by']
    );

    return $component->setWeight($weight);
  }

  /**
   * Reads a component's block plugin configuration.
   *
   * SectionComponent::getConfiguration() is protected, so the configuration is
   * read through the public array representation instead.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component to read.
   *
   * @return array
   *   The component's block plugin configuration.
   */
  protected function configurationOf(SectionComponent $component): array {
    return $component->toArray()['configuration'];
  }

  /**
   * Builds the section fixture used by most of the tests.
   *
   * Two regions, mixed inline and non-inline blocks, plus layout settings and a
   * third-party setting standing in for the section colour dial.
   *
   * @return \Drupal\layout_builder\Section
   *   The section to clone.
   */
  protected function createSection(): Section {
    $section = new Section(
      'layout_twocol_section',
      ['label' => 'Featured section', 'column_widths' => '50-50'],
      [
        $this->createGalleryComponent('first', 0, 'First gallery'),
        $this->createReusableComponent('first', 1),
        $this->createGalleryComponent('second', 0, 'Second gallery'),
      ]
    );
    $section->setThirdPartySetting('ys_themes', 'component_theme', 'three');

    return $section;
  }

  /**
   * The clone keeps the layout, its settings and third-party settings.
   *
   * The section colour / component-theme dial is stored as a third-party
   * setting, so losing those would silently reset the copy's appearance.
   *
   * @covers ::duplicateSection
   */
  public function testCloneKeepsLayoutSettingsAndThirdPartySettings(): void {
    $section = $this->createSection();

    $clone = $this->sectionCloner->duplicateSection($section);

    $this->assertSame('layout_twocol_section', $clone->getLayoutId(), 'The clone keeps the layout plugin.');
    // Compared against the original rather than a literal: getLayoutSettings()
    // instantiates the layout plugin and merges its default configuration in,
    // so the stored settings are not only what the fixture set.
    $this->assertEquals(
      $section->getLayoutSettings(),
      $clone->getLayoutSettings(),
      'The clone keeps the layout settings.'
    );
    $this->assertSame(
      'three',
      $clone->getThirdPartySetting('ys_themes', 'component_theme'),
      'The clone keeps the section component-theme selection.'
    );
  }

  /**
   * Every block is copied into the same region, in the same order.
   *
   * @covers ::duplicateSection
   */
  public function testAllComponentsAreCopiedIntoTheSameRegionsInOrder(): void {
    $section = $this->createSection();

    $clone = $this->sectionCloner->duplicateSection($section);

    $this->assertCount(3, $clone->getComponents(), 'Every component is copied.');

    $original_first = array_values($section->getComponentsByRegion('first'));
    $clone_first = array_values($clone->getComponentsByRegion('first'));
    $this->assertCount(2, $clone_first, 'Both blocks in the first region are copied.');
    $this->assertSame(
      array_map(fn (SectionComponent $c) => $c->getPluginId(), $original_first),
      array_map(fn (SectionComponent $c) => $c->getPluginId(), $clone_first),
      'The first region keeps its block order.'
    );

    $clone_second = array_values($clone->getComponentsByRegion('second'));
    $this->assertCount(1, $clone_second, 'The block in the second region is copied.');
    $this->assertSame('inline_block:gallery', $clone_second[0]->getPluginId(), 'The second region keeps its block.');
  }

  /**
   * No component UUID is shared between the original and the copy.
   *
   * Both sections live in the same layout, so a reused component UUID would
   * collide once the layout is saved. This is the "no duplicate block UUIDs"
   * acceptance criterion, and it is the case that quick_node_clone's
   * cloneLayoutSection() gets wrong for an in-place clone: it regenerates the
   * UUID for inline blocks only and leaves non-inline components on the
   * original's UUID.
   *
   * @covers ::duplicateSection
   */
  public function testNoComponentUuidIsSharedWithTheOriginal(): void {
    $section = $this->createSection();

    $clone = $this->sectionCloner->duplicateSection($section);

    $original_uuids = array_keys($section->getComponents());
    $clone_uuids = array_keys($clone->getComponents());

    $this->assertCount(3, $clone_uuids, 'The clone has a component per original component.');
    $this->assertSame(
      [],
      array_intersect($original_uuids, $clone_uuids),
      'No component UUID is shared between the original section and its copy.'
    );
  }

  /**
   * Inline blocks are deep-copied, not shared with the original.
   *
   * @covers ::duplicateSection
   */
  public function testInlineBlocksAreDeepCopied(): void {
    $section = $this->createSection();

    $clone = $this->sectionCloner->duplicateSection($section);

    $copies = array_values(array_filter(
      $clone->getComponents(),
      fn (SectionComponent $c) => $c->getPluginId() === 'inline_block:gallery'
    ));
    $this->assertCount(2, $copies, 'Both inline blocks are present in the copy.');

    foreach ($copies as $copy) {
      $configuration = $this->configurationOf($copy);
      $this->assertNotEmpty($configuration['block_serialized'], 'The copied inline block carries its own serialized block.');
      $this->assertNull($configuration['block_revision_id'], 'The copied inline block drops the original revision id.');

      // @codingStandardsIgnoreStart
      $cloned_block = unserialize($configuration['block_serialized']);
      // @codingStandardsIgnoreEnd
      $cloned_paragraph = $cloned_block->get('field_gallery_items')->first()->entity;
      $this->assertNotNull($cloned_paragraph, 'The copied block still references a paragraph.');
      $this->assertNull(
        $cloned_paragraph->id(),
        'The paragraph in the copy is a new unsaved entity, not the shared original.'
      );
    }
  }

  /**
   * Reusable / non-inline blocks are re-referenced, not duplicated.
   *
   * Issue #190 scope rule: a reusable block is shared across placements, so the
   * copy must point at the same block rather than become a disconnected
   * duplicate. Only the component UUID changes.
   *
   * @covers ::duplicateSection
   */
  public function testReusableBlocksAreRereferencedNotDuplicated(): void {
    $section = $this->createSection();
    $original = array_values(array_filter(
      $section->getComponents(),
      fn (SectionComponent $c) => $c->getPluginId() === 'system_powered_by_block'
    ))[0];

    $clone = $this->sectionCloner->duplicateSection($section);

    $copies = array_values(array_filter(
      $clone->getComponents(),
      fn (SectionComponent $c) => $c->getPluginId() === 'system_powered_by_block'
    ));
    $this->assertCount(1, $copies, 'The reusable block is present in the copy.');

    $copy = $copies[0];
    $this->assertNotSame($original->getUuid(), $copy->getUuid(), 'The copy gets its own component UUID.');
    $this->assertSame(
      $this->configurationOf($original),
      $this->configurationOf($copy),
      'The reusable block is re-referenced with identical configuration.'
    );
    $this->assertSame($original->getRegion(), $copy->getRegion(), 'The reusable block stays in its region.');
  }

  /**
   * Cloning leaves the original section untouched.
   *
   * @covers ::duplicateSection
   */
  public function testOriginalSectionIsNotModified(): void {
    $section = $this->createSection();
    $original_uuids = array_keys($section->getComponents());
    $original_configurations = array_map(
      fn (SectionComponent $c) => $this->configurationOf($c),
      $section->getComponents()
    );

    $this->sectionCloner->duplicateSection($section);

    $this->assertSame($original_uuids, array_keys($section->getComponents()), 'The original keeps its components.');
    $this->assertSame(
      $original_configurations,
      array_map(fn (SectionComponent $c) => $this->configurationOf($c), $section->getComponents()),
      'The original keeps its block configuration.'
    );
  }

  /**
   * An empty section clones to an empty section rather than failing.
   *
   * @covers ::duplicateSection
   */
  public function testEmptySectionIsCloned(): void {
    $section = new Section('layout_onecol', ['label' => 'Empty']);

    $clone = $this->sectionCloner->duplicateSection($section);

    $this->assertSame('layout_onecol', $clone->getLayoutId(), 'The empty clone keeps the layout.');
    $this->assertSame([], $clone->getComponents(), 'The empty clone has no components.');
  }

  /**
   * The copy is inserted directly below the section it was cloned from.
   *
   * Off by one in either direction and the copy lands above the original or at
   * the wrong point in a multi-section page. A single non-zero delta pins it:
   * the placement is one arithmetic expression with no per-delta branch, and a
   * non-zero delta distinguishes `$delta + 1` from both `$delta` and an append.
   *
   * @covers ::cloneSection
   */
  public function testCloneIsInsertedDirectlyBelowTheOriginal(): void {
    $section = $this->createSection();
    $inserted = NULL;

    $section_storage = $this->createMock(SectionStorageInterface::class);
    $section_storage->method('getSection')->with(2)->willReturn($section);
    $section_storage->expects($this->once())
      ->method('insertSection')
      ->with(3, $this->callback(function ($section) use (&$inserted) {
        $inserted = $section;
        return $section instanceof Section;
      }));

    $clone = $this->sectionCloner->cloneSection($section_storage, 2);

    $this->assertSame($clone, $inserted, 'The section inserted below the original is the returned copy.');
    $this->assertNotSame($section, $clone, 'The inserted section is a copy, not the original.');
    $this->assertCount(3, $clone->getComponents(), "The inserted copy carries the original's blocks.");
  }

}
