<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\Yaml\Yaml;

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
   * The icon every In-Line Message rendered before the field existed.
   *
   * The component hardcoded 'circle-info' for the 'general' type, and atomic
   * never passes a type, so this is what every Drupal-rendered block showed.
   */
  const LEGACY_ICON = 'circle-info';

  /**
   * The icon the bare component uses for a non-general message.
   *
   * Only reachable from Storybook, but it must stay selectable so an editor
   * can choose it deliberately.
   */
  const MARKETING_ICON = 'circle-exclamation';

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
      'default_value' => [['value' => self::LEGACY_ICON]],
    ])->save();
  }

  /**
   * Absolute path to the profile's exported config/sync directory.
   */
  protected function configSyncDir(): string {
    return \Drupal::root() . '/' . \Drupal::service('extension.list.profile')
      ->getPath('yalesites_profile') . '/config/sync';
  }

  /**
   * The icon options the picker offers for the In-Line Message icon field.
   */
  protected function iconOptions(): array {
    return ys_core_facts_icon_allowed_values(
      FieldStorageConfig::loadByName('block_content', 'field_icon')
    );
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
    $options = $this->iconOptions();
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

  /**
   * The icons the component used to hardcode are selectable in the picker.
   *
   * They live in images/icons/ but were never registered in the shared
   * facts-and-figures manifest the picker reads, so before issue #697's review
   * they could not be chosen at all — including the one this bundle defaults
   * to. Named explicitly rather than read from the manifest: the point is that
   * these two specific icons must not disappear from it.
   *
   * Scope, stated plainly: locally the manifest path is a symlink to the
   * in-tree component library, so this guards the CLT source. It cannot catch
   * version skew — atomic pins component-library-twig by release, so a
   * yalesites-project deploy that lands before a CLT release carrying these
   * entries would still offer a picker without them.
   */
  public function testHistoricalIconsAreSelectable(): void {
    $options = $this->iconOptions();

    $this->assertArrayHasKey(self::LEGACY_ICON, $options);
    $this->assertArrayHasKey(self::MARKETING_ICON, $options);
  }

  /**
   * The exported field config defaults new blocks to the legacy icon.
   *
   * Asserted against the exported YAML rather than the fixture above, because
   * config/sync is what a deploy actually imports — a re-export that flipped
   * this back to '_none' would ship blocks with no icon.
   */
  public function testExportedFieldConfigDefaultsToLegacyIcon(): void {
    $file = $this->configSyncDir()
      . '/field.field.block_content.inline_message.field_icon.yml';
    $this->assertFileExists($file);

    $config = Yaml::parseFile($file);
    $this->assertSame(
      self::LEGACY_ICON,
      $config['default_value'][0]['value'] ?? NULL,
      'A new In-Line Message block starts with the icon it always had.'
    );
  }

  /**
   * The deploy hook gives pre-existing blocks back the icon they rendered.
   *
   * Drupal applies a field default only at entity creation, so blocks that
   * predate field_icon keep an empty value and would render with no icon.
   */
  public function testDeployBackfillsIconOnBlocksThatPredateTheField(): void {
    $block = BlockContent::create([
      'type' => 'inline_message',
      'info' => 'Predates the icon field',
    ]);
    // Simulate a block saved before field_icon existed.
    $block->set('field_icon', NULL);
    $block->save();
    $this->assertTrue(
      $block->get('field_icon')->isEmpty(),
      'Guard: the fixture really starts with no icon.'
    );

    $this->runIconBackfill();

    $reloaded = BlockContent::load($block->id());
    $this->assertSame(self::LEGACY_ICON, $reloaded->get('field_icon')->value);
  }

  /**
   * The deploy hook leaves an editor's deliberate icon choice alone.
   */
  public function testDeployLeavesChosenIconUntouched(): void {
    $block = BlockContent::create([
      'type' => 'inline_message',
      'info' => 'Editor picked an icon',
      'field_icon' => self::MARKETING_ICON,
    ]);
    $block->save();

    $this->runIconBackfill();

    $reloaded = BlockContent::load($block->id());
    $this->assertSame(self::MARKETING_ICON, $reloaded->get('field_icon')->value);
  }

  /**
   * The backfill spans more blocks than one batch pass handles.
   *
   * The hook is batched because an unbatched pass timed out on sites with
   * thousands of blocks (commit a6fad6354). Batching pages by offset while
   * the same pass writes to those rows, so this covers the chunk boundary
   * where an off-by-one would silently skip blocks.
   */
  public function testDeployBackfillsAcrossBatchPasses(): void {
    $created = [];
    for ($i = 0; $i < 55; $i++) {
      $block = BlockContent::create([
        'type' => 'inline_message',
        'info' => 'Bulk block ' . $i,
      ]);
      $block->set('field_icon', NULL);
      $block->save();
      $this->assertTrue(
        $block->get('field_icon')->isEmpty(),
        'Guard: bulk fixtures really start with no icon.'
      );
      $created[] = $block->id();
    }

    $passes = $this->runIconBackfill();
    $this->assertGreaterThan(
      1,
      $passes,
      'Guard: the fixtures must exceed one chunk or this proves nothing.'
    );

    foreach ($created as $id) {
      $reloaded = BlockContent::load($id);
      $this->assertSame(
        self::LEGACY_ICON,
        $reloaded->get('field_icon')->value,
        "Block $id was skipped by the batch."
      );
    }
  }

  /**
   * An older, still-pinned revision is backfilled, not just the newest one.
   *
   * Layout Builder renders whichever block revision a node revision pins, and
   * layout_builder__layout is revisionable — so a page with a draft pins a
   * newer block revision on the draft while the published revision keeps an
   * older one. Backfilling only the latest would leave the live page iconless.
   */
  public function testDeployBackfillsOlderRevisions(): void {
    $block = BlockContent::create([
      'type' => 'inline_message',
      'info' => 'Has an older pinned revision',
    ]);
    $block->set('field_icon', NULL);
    $block->save();
    $old_revision_id = $block->getRevisionId();

    // A later edit — as a Layout Builder draft would make — leaves the older
    // revision behind, still pinned by the published node revision.
    $block->setNewRevision(TRUE);
    $block->set('info', 'Newer draft revision');
    $block->save();
    $new_revision_id = $block->getRevisionId();
    $this->assertNotSame(
      $old_revision_id,
      $new_revision_id,
      'Guard: the fixture really has two revisions.'
    );

    $this->runIconBackfill();

    $storage = \Drupal::entityTypeManager()->getStorage('block_content');
    $this->assertSame(
      self::LEGACY_ICON,
      $storage->loadRevision($old_revision_id)->get('field_icon')->value,
      'The older, still-pinned revision must render an icon too.'
    );
    $this->assertSame(
      self::LEGACY_ICON,
      $storage->loadRevision($new_revision_id)->get('field_icon')->value
    );
  }

  /**
   * Runs the In-Line Message icon backfill deploy hook to completion.
   *
   * The hook is batched, so it is driven the way Drush drives it: called
   * repeatedly with a shared sandbox until it reports '#finished'.
   *
   * @return int
   *   How many passes the hook needed, so a caller can assert it really did
   *   batch rather than finish everything in one go.
   */
  protected function runIconBackfill(): int {
    $path = \Drupal::service('extension.list.module')->getPath('ys_core');
    require_once \Drupal::root() . '/' . $path . '/ys_core.deploy.php';

    $sandbox = [];
    // Bounded so a hook that never finishes fails the test instead of hanging.
    for ($pass = 1; $pass <= 100; $pass++) {
      ys_core_deploy_10007($sandbox);
      if (($sandbox['#finished'] ?? 0) >= 1) {
        return $pass;
      }
    }

    $this->fail('The icon backfill never reported #finished.');
  }

}
