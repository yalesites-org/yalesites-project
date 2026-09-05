<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\ys_core\TextFormatRepair;

/**
 * Tests the storage-level text format repair.
 *
 * The unit test covers the decision logic in isolation; this exercises the
 * actual column rewrite against real field tables, on fields configured the
 * way node.resource's field_abstract is.
 *
 * The revision-safety cases matter most: the repair deliberately writes the
 * format column instead of re-saving nodes, because content_moderation's
 * presave handler rewrites publication status outside its isSyncing() guard.
 * These tests pin down that nothing but the format column moves.
 *
 * @coversDefaultClass \Drupal\ys_core\TextFormatRepair
 *
 * @group ys_core
 * @group yalesites
 *
 * @see yalesites-org/YaleSites-Internal#1646
 */
class TextFormatRepairTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
  ];

  /**
   * Markup that restricted_html would strip but basic_html would keep.
   *
   * Used to prove the repair rewrites the format name only and never edits the
   * stored value, which is what makes it reversible.
   */
  const RICH_VALUE = '<h3>Findings</h3><ul><li>First</li></ul>';

  /**
   * The service under test.
   *
   * @var \Drupal\ys_core\TextFormatRepair
   */
  protected $repair;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'filter', 'node']);

    FilterFormat::create([
      'format' => 'restricted_html',
      'name' => 'Restricted HTML',
      'weight' => 1,
    ])->save();
    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
      'weight' => 0,
    ])->save();

    NodeType::create(['type' => 'resource', 'name' => 'Resource'])->save();
    // A second bundle, to prove the repair is scoped by bundle.
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    // field_abstract mirrors the real instance: restricted to one format.
    $this->createTextField('field_abstract', ['restricted_html']);
    // field_teaser stands in for a field that places no restriction.
    $this->createTextField('field_teaser', []);
    // A multi-value field, to prove every delta is visited.
    $this->createTextField(
      'field_notes',
      ['restricted_html'],
      FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED
    );

    // Constructed directly rather than via the ys_core service, so this test
    // does not have to enable ys_core and its dependency chain (cas, ys_media
    // and friends) just to exercise four core services.
    $this->repair = new TextFormatRepair(
      $this->container->get('entity_type.manager'),
      $this->container->get('entity_field.manager'),
      $this->container->get('database'),
      $this->container->get('cache_tags.invalidator')
    );
  }

  /**
   * Creates a text_long field on the resource and page bundles.
   */
  protected function createTextField(string $field_name, array $allowed_formats, int $cardinality = 1): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'type' => 'text_long',
      'cardinality' => $cardinality,
    ])->save();

    foreach (['resource', 'page'] as $bundle) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => $bundle,
        'settings' => ['allowed_formats' => $allowed_formats],
      ])->save();
    }
  }

  /**
   * Creates a saved node.
   */
  protected function createNode(array $values, string $bundle = 'resource'): Node {
    $node = Node::create(['type' => $bundle, 'title' => 'Test node'] + $values);
    $node->save();
    return $node;
  }

  /**
   * Returns the node storage handler.
   */
  protected function nodeStorage() {
    return $this->container->get('entity_type.manager')->getStorage('node');
  }

  /**
   * Returns the stored format of a field on the freshly loaded node.
   */
  protected function storedFormat(int $nid, string $field_name, int $delta = 0): ?string {
    $storage = $this->nodeStorage();
    $storage->resetCache([$nid]);
    return $storage->load($nid)->get($field_name)[$delta]->format;
  }

  /**
   * The service reads the restriction off the real field config.
   *
   * @covers ::getAllowedFormats
   */
  public function testReadsAllowedFormatsFromFieldConfig(): void {
    $this->assertSame(
      ['restricted_html'],
      $this->repair->getAllowedFormats('node', 'resource', 'field_abstract')
    );
    $this->assertSame(
      [],
      $this->repair->getAllowedFormats('node', 'resource', 'field_teaser')
    );
  }

  /**
   * An out-of-contract format is rewritten in storage.
   *
   * @covers ::repairFieldStorage
   */
  public function testRepairsOutOfContractFormat(): void {
    $node = $this->createNode([
      'field_abstract' => ['value' => self::RICH_VALUE, 'format' => 'basic_html'],
    ]);

    $this->assertGreaterThan(
      0,
      $this->repair->repairFieldStorage('node', 'resource', 'field_abstract')
    );
    $this->assertSame('restricted_html', $this->storedFormat($node->id(), 'field_abstract'));
  }

  /**
   * The repair rewrites the format name only, never the stored markup.
   *
   * This is what makes it reversible: restoring the old format name restores
   * the original rendering, because no characters were dropped.
   *
   * @covers ::repairFieldStorage
   */
  public function testStoredValueIsNotModified(): void {
    $node = $this->createNode([
      'field_abstract' => ['value' => self::RICH_VALUE, 'format' => 'basic_html'],
    ]);

    $this->repair->repairFieldStorage('node', 'resource', 'field_abstract');

    $storage = $this->nodeStorage();
    $storage->resetCache([$node->id()]);
    $this->assertSame(
      self::RICH_VALUE,
      $storage->load($node->id())->get('field_abstract')->value
    );
  }

  /**
   * A value already within the contract is left untouched and not counted.
   *
   * @covers ::repairFieldStorage
   */
  public function testPermittedFormatIsLeftAlone(): void {
    $node = $this->createNode([
      'field_abstract' => ['value' => '<p>Fine</p>', 'format' => 'restricted_html'],
    ]);

    $this->assertSame(0, $this->repair->repairFieldStorage('node', 'resource', 'field_abstract'));
    $this->assertSame('restricted_html', $this->storedFormat($node->id(), 'field_abstract'));
  }

  /**
   * A field with no allowed_formats restriction is never touched.
   *
   * @covers ::repairFieldStorage
   */
  public function testUnrestrictedFieldIsLeftAlone(): void {
    $node = $this->createNode([
      'field_teaser' => ['value' => '<p>Anything</p>', 'format' => 'basic_html'],
    ]);

    $this->assertSame(0, $this->repair->repairFieldStorage('node', 'resource', 'field_teaser'));
    $this->assertSame('basic_html', $this->storedFormat($node->id(), 'field_teaser'));
  }

  /**
   * Every delta of a multi-value field is repaired, not just the first.
   *
   * A delta-0-only implementation would pass every other case here.
   *
   * @covers ::repairFieldStorage
   */
  public function testAllDeltasAreRepaired(): void {
    $node = $this->createNode([
      'field_notes' => [
        ['value' => '<p>Zero</p>', 'format' => 'restricted_html'],
        ['value' => '<p>One</p>', 'format' => 'basic_html'],
        ['value' => '<p>Two</p>', 'format' => 'basic_html'],
      ],
    ]);

    $this->repair->repairFieldStorage('node', 'resource', 'field_notes');

    $this->assertSame('restricted_html', $this->storedFormat($node->id(), 'field_notes', 0));
    $this->assertSame('restricted_html', $this->storedFormat($node->id(), 'field_notes', 1));
    $this->assertSame('restricted_html', $this->storedFormat($node->id(), 'field_notes', 2));
  }

  /**
   * Only the requested bundle is repaired.
   *
   * @covers ::repairFieldStorage
   */
  public function testOtherBundlesAreNotTouched(): void {
    $page = $this->createNode([
      'field_abstract' => ['value' => '<p>Page</p>', 'format' => 'basic_html'],
    ], 'page');

    $this->repair->repairFieldStorage('node', 'resource', 'field_abstract');

    $this->assertSame('basic_html', $this->storedFormat($page->id(), 'field_abstract'));
  }

  /**
   * Older revisions are repaired too, and no revision is added or removed.
   *
   * The node edit form loads the latest revision while the front end renders
   * the default one, so both have to be corrected — but the repair must not
   * create a revision while doing it.
   *
   * @covers ::repairFieldStorage
   */
  public function testAllRevisionsRepairedWithoutAddingRevisions(): void {
    $node = $this->createNode([
      'field_abstract' => ['value' => '<p>First</p>', 'format' => 'basic_html'],
    ]);

    $node->setNewRevision(TRUE);
    $node->set('field_abstract', ['value' => '<p>Second</p>', 'format' => 'basic_html']);
    $node->save();

    $storage = $this->nodeStorage();
    $revision_ids_before = $storage->revisionIds($node);
    $this->assertCount(2, $revision_ids_before);

    $this->repair->repairFieldStorage('node', 'resource', 'field_abstract');

    $storage->resetCache([$node->id()]);
    $reloaded = $storage->load($node->id());

    // No revision created or destroyed.
    $this->assertSame($revision_ids_before, $storage->revisionIds($reloaded));

    // Every revision corrected, not just the current one.
    foreach ($revision_ids_before as $revision_id) {
      $revision = $storage->loadRevision($revision_id);
      $this->assertSame(
        'restricted_html',
        $revision->get('field_abstract')->format,
        sprintf('Revision %d was repaired.', $revision_id)
      );
    }
  }

  /**
   * Publication status and the changed timestamp survive the repair.
   *
   * This is the reason the repair writes the column instead of re-saving the
   * node: content_moderation's presave handler rewrites publication status
   * outside its isSyncing() guard, so a save could unpublish a live page.
   *
   * @covers ::repairFieldStorage
   */
  public function testPublicationStatusAndChangedTimeAreUntouched(): void {
    $node = $this->createNode([
      'field_abstract' => ['value' => '<p>Live</p>', 'format' => 'basic_html'],
      'status' => 1,
    ]);
    $changed_before = $node->getChangedTime();

    $this->repair->repairFieldStorage('node', 'resource', 'field_abstract');

    $storage = $this->nodeStorage();
    $storage->resetCache([$node->id()]);
    $reloaded = $storage->load($node->id());

    $this->assertTrue($reloaded->isPublished());
    $this->assertSame($changed_before, $reloaded->getChangedTime());
    $this->assertSame('restricted_html', $reloaded->get('field_abstract')->format);
  }

  /**
   * A field absent from the bundle is a no-op rather than an error.
   *
   * @covers ::repairFieldStorage
   */
  public function testMissingFieldIsSkipped(): void {
    $this->assertSame(
      0,
      $this->repair->repairFieldStorage('node', 'resource', 'field_does_not_exist')
    );
  }

}
