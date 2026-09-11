<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Tests restoring a "+" that the #683 forward fix decoded away as a space.
 *
 * Issue #1494 follow-up: patches/contrib/linkit/3436733-5.patch used to call
 * urldecode(), which reads "+" as an encoded space, so a link to "a+b.pdf" was
 * saved as "a b.pdf" and 404s. The patch now uses rawurldecode(), but values
 * already stored through the old one need rewriting.
 *
 * The whole point of the repair is that it decides from the filesystem rather
 * than from the URI, so these fixtures create real files: a space in a file
 * name is ordinary and must survive untouched.
 *
 * @group ys_core
 * @group yalesites
 */
class RestorePlusLinkUriTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'link',
    'text',
    'block_content',
  ];

  /**
   * The field's dedicated data table.
   */
  protected string $dataTable;

  /**
   * The field's dedicated revision table.
   */
  protected string $revisionTable;

  /**
   * The public files directory, as the repair resolves it.
   */
  protected string $filesBasePath;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('block_content');

    BlockContentType::create(['id' => 'button_link', 'label' => 'Button link'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_button_link',
      'entity_type' => 'block_content',
      'type' => 'link',
      'cardinality' => 2,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_button_link',
      'entity_type' => 'block_content',
      'bundle' => 'button_link',
      'label' => 'Button link',
    ])->save();

    $storage = \Drupal::entityTypeManager()->getStorage('block_content');
    $definition = \Drupal::service('entity_field.manager')
      ->getFieldStorageDefinitions('block_content')['field_button_link'];
    $this->dataTable = $storage->getTableMapping()->getDedicatedDataTableName($definition);
    $this->revisionTable = $storage->getTableMapping()->getDedicatedRevisionTableName($definition);

    // Resolved at runtime rather than hardcoded, because the test site's public
    // files directory is not the platform's.
    $this->filesBasePath = PublicStream::basePath();
    $directory = 'public://2026-08';
    \Drupal::service('file_system')
      ->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

    // The file the editor meant. Its "+" was eaten on save.
    file_put_contents('public://2026-08/a+b.pdf', 'plus');
    // A file whose name genuinely contains a space, stored correctly.
    file_put_contents('public://2026-08/My Report.pdf', 'space');

    // The helper lives in ys_core.install; load it without enabling ys_core,
    // which would pull in cas/role_delegation and a much heavier container.
    require_once __DIR__ . '/../../../ys_core.install';
  }

  /**
   * Builds an internal link URI pointing into the public files directory.
   */
  protected function fileUri(string $relative): string {
    return 'internal:/' . $this->filesBasePath . '/' . $relative;
  }

  /**
   * Returns the stored URI for one row of a field table.
   */
  protected function storedUri(string $table, int $revision_id, int $delta): ?string {
    return \Drupal::database()->select($table, 'f')
      ->fields('f', ['field_button_link_uri'])
      ->condition('revision_id', $revision_id)
      ->condition('delta', $delta)
      ->execute()
      ->fetchField() ?: NULL;
  }

  /**
   * Creates a non-reusable block holding the given link URIs.
   */
  protected function createBlock(string $info, array $uris): BlockContent {
    $values = [];
    foreach ($uris as $uri) {
      $values[] = ['uri' => $uri, 'title' => 'Document'];
    }
    $block = BlockContent::create([
      'type' => 'button_link',
      'info' => $info,
      'reusable' => FALSE,
      'field_button_link' => $values,
    ]);
    $block->save();
    return $block;
  }

  /**
   * Only the value whose "+" file exists is rewritten, in both tables.
   */
  public function testRestoresOnlyWhereTheFileProvesIt(): void {
    $corrupted = $this->createBlock('Corrupted', [
      $this->fileUri('2026-08/a b.pdf'),
      // A file that really is named with a space: it must survive untouched.
      $this->fileUri('2026-08/My Report.pdf'),
    ]);
    // An old revision, which is what Layout Builder renders an inline block
    // from, so it has to be corrected too.
    $old_revision_id = (int) $corrupted->getRevisionId();
    $corrupted->setNewRevision(TRUE);
    $corrupted->save();
    $current_revision_id = (int) $corrupted->getRevisionId();
    $this->assertNotSame($old_revision_id, $current_revision_id, 'The fixture really has two revisions.');

    // Neither name exists, so there is no evidence and nothing to do.
    $missing = $this->createBlock('Missing file', [$this->fileUri('2026-08/gone away.pdf')]);

    $changed_before = [
      $corrupted->id() => $corrupted->getChangedTime(),
      $missing->id() => $missing->getChangedTime(),
    ];
    $revisions_before = (int) \Drupal::database()
      ->select('block_content_revision', 'r')->countQuery()->execute()->fetchField();

    $report = ys_core_restore_plus_link_uris();

    // Both the data table and every revision row are corrected.
    $this->assertSame($this->fileUri('2026-08/a+b.pdf'), $this->storedUri($this->dataTable, $current_revision_id, 0), 'The default field table is corrected.');
    $this->assertSame($this->fileUri('2026-08/a+b.pdf'), $this->storedUri($this->revisionTable, $current_revision_id, 0), 'The matching revision row is corrected.');
    $this->assertSame($this->fileUri('2026-08/a+b.pdf'), $this->storedUri($this->revisionTable, $old_revision_id, 0), 'The old revision Layout Builder renders from is corrected.');
    $this->assertSame(3, $report['repaired'], 'Exactly the three rows holding the eaten "+" are rewritten.');

    // A file name that genuinely holds a space is never touched.
    $this->assertSame($this->fileUri('2026-08/My Report.pdf'), $this->storedUri($this->dataTable, $current_revision_id, 1), 'A real space in a file name survives.');
    $this->assertSame($this->fileUri('2026-08/My Report.pdf'), $this->storedUri($this->revisionTable, $old_revision_id, 1), 'A real space survives in the revision table too.');

    // With neither file present the value is left alone rather than guessed at,
    // and reported so a run without the site's files is visible as such.
    $this->assertSame($this->fileUri('2026-08/gone away.pdf'), $this->storedUri($this->dataTable, (int) $missing->getRevisionId(), 0), 'A link whose file is simply gone is left alone.');
    $this->assertSame([$this->fileUri('2026-08/gone away.pdf')], $report['undecided_uris'], 'Only the value with no file either way is reported as undecided.');

    // Nothing an entity save would have disturbed has moved.
    $revisions_after = (int) \Drupal::database()
      ->select('block_content_revision', 'r')->countQuery()->execute()->fetchField();
    $this->assertSame($revisions_before, $revisions_after, 'The repair creates no new revisions.');

    \Drupal::entityTypeManager()->getStorage('block_content')->resetCache();
    foreach ($changed_before as $id => $changed) {
      $this->assertSame($changed, BlockContent::load($id)->getChangedTime(), 'The changed timestamp does not move.');
    }

    // The corrected value is what the entity now returns.
    $this->assertSame($this->fileUri('2026-08/a+b.pdf'), BlockContent::load($corrupted->id())->get('field_button_link')->first()->uri, 'The corrected URI is visible through the entity API.');
  }

  /**
   * Running the repair a second time changes nothing.
   */
  public function testRepairIsIdempotent(): void {
    $block = $this->createBlock('Corrupted', [$this->fileUri('2026-08/a b.pdf')]);

    $first = ys_core_restore_plus_link_uris();
    $this->assertSame(2, $first['repaired'], 'The first run corrects the data and revision rows.');

    $second = ys_core_restore_plus_link_uris();
    $this->assertSame(0, $second['repaired'], 'A second run has nothing left to correct.');
    $this->assertSame($this->fileUri('2026-08/a+b.pdf'), $this->storedUri($this->dataTable, (int) $block->getRevisionId(), 0), 'The value is not rewritten twice.');
  }

  /**
   * A dry run reports what it would change without writing anything.
   */
  public function testDryRunWritesNothing(): void {
    $block = $this->createBlock('Corrupted', [$this->fileUri('2026-08/a b.pdf')]);

    $report = ys_core_restore_plus_link_uris(TRUE);

    $this->assertSame(2, $report['repaired'], 'The dry run counts the rows it would correct.');
    $this->assertNotEmpty($report['changes'], 'The dry run lists the changes it would make.');
    $this->assertSame($this->fileUri('2026-08/a b.pdf'), $this->storedUri($this->dataTable, (int) $block->getRevisionId(), 0), 'The dry run leaves the data untouched.');
  }

  /**
   * The repair does not disturb a URI the #683 repair still owns.
   */
  public function testLeavesStillEncodedUrisToTheOtherRepair(): void {
    $encoded = 'internal:/' . $this->filesBasePath . '/2026-08/a%20b.pdf';
    $block = $this->createBlock('Still encoded', [$encoded]);

    $report = ys_core_restore_plus_link_uris();

    $this->assertSame(0, $report['repaired'], 'A percent-encoded path is not this repair to make.');
    $this->assertSame($encoded, $this->storedUri($this->dataTable, (int) $block->getRevisionId(), 0), 'The still-encoded value is untouched.');
  }

}
