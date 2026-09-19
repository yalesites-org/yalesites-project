<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\KeyValueStore\DatabaseStorage;

/**
 * Tests the shared purge of a removed module's orphaned schema entry.
 *
 * The update hooks in ys_core.install delete a module's leftover system.schema
 * key_value entry so that a config import can succeed after the module's files
 * have already been removed from the codebase. That logic used to be
 * copy-pasted into each of them and had no test at all, which is an
 * uncomfortable combination for code whose only job is to DELETE rows: the
 * delete is scoped by two conditions, and dropping either one would widen it
 * to every module on the site while every caller still reported success.
 *
 * testLeavesOtherEntriesAlone() is therefore the case that matters most.
 *
 * The helper talks to the key_value table directly rather than going through
 * \Drupal::keyValue(), because on a real site running updatedb the module it
 * is cleaning up after is already gone. These tests work at the same level:
 * KernelTestBase swaps the keyvalue service for an in-memory factory, so
 * writing through the service would never touch the table the helper reads.
 *
 * @group ys_core
 * @group yalesites
 *
 * @see yalesites-org/YaleSites-Internal#1692
 */
class PurgeMissingModuleTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // No module declares a schema for key_value -- Drupal's DatabaseStorage
    // creates it on demand -- so build it here from core's own definition
    // rather than restating the columns.
    \Drupal::database()->schema()
      ->createTable('key_value', DatabaseStorage::schemaDefinition());

    // The helper lives in ys_core.install; load it without enabling ys_core,
    // which would pull in cas/role_delegation and a much heavier container.
    require_once __DIR__ . '/../../../ys_core.install';
  }

  /**
   * An orphaned entry is deleted, and the removal is reported to the caller.
   */
  public function testRemovesAnOrphanedSchemaEntryAndReportsIt(): void {
    $this->writeEntry('system.schema', 'ys_gone_module', 8000);

    $this->assertTrue(_ys_core_purge_missing_module('ys_gone_module'));
    $this->assertFalse($this->entryExists('system.schema', 'ys_gone_module'));
  }

  /**
   * A module with no entry is a no-op, reported as nothing removed.
   *
   * This is what lets ys_core_update_10015 return "nothing to remove", and the
   * two defensive callers stay silent instead of claiming a cleanup they did
   * not perform.
   */
  public function testReportsNothingRemovedWhenThereIsNoEntry(): void {
    $this->assertFalse(_ys_core_purge_missing_module('ys_never_installed'));
  }

  /**
   * Only the named module's entry in the system.schema collection is touched.
   *
   * The regression guard for the delete's scope: it is conditioned on both the
   * collection and the module name, and losing either would take out unrelated
   * rows -- every other module's schema version, or a same-named key in a
   * different collection.
   */
  public function testLeavesOtherEntriesAlone(): void {
    $this->writeEntry('system.schema', 'ys_gone_module', 8000);
    $this->writeEntry('system.schema', 'ys_still_here', 9001);
    $this->writeEntry('some.other.collection', 'ys_gone_module', 'untouched');

    $this->assertTrue(_ys_core_purge_missing_module('ys_gone_module'));

    $this->assertTrue($this->entryExists('system.schema', 'ys_still_here'));
    $this->assertTrue($this->entryExists('some.other.collection', 'ys_gone_module'));
  }

  /**
   * Writes one key_value row the way a real installed module would have.
   */
  private function writeEntry(string $collection, string $name, $value): void {
    \Drupal::database()->insert('key_value')
      ->fields([
        'collection' => $collection,
        'name' => $name,
        'value' => serialize($value),
      ])
      ->execute();
  }

  /**
   * Checks whether a key_value row is still present.
   */
  private function entryExists(string $collection, string $name): bool {
    return (bool) \Drupal::database()->select('key_value', 'kv')
      ->fields('kv', ['name'])
      ->condition('collection', $collection)
      ->condition('name', $name)
      ->execute()
      ->fetchField();
  }

}
