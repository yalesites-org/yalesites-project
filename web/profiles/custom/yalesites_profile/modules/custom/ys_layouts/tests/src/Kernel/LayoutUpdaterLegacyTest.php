<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ys_layouts\Service\LayoutUpdaterLegacy;

/**
 * Tests the LayoutUpdaterLegacy service.
 *
 * The bulk of this service (updateExistingPageMeta(), updateExistingPageLock(),
 * updateExistingEventsLock(), updateExistingPostMeta(), and
 * updateExistingEventMeta()) calls \Drupal::entityQuery(), Node::load(), and
 * entity_view_display section lookups directly with no injected dependencies,
 * which requires a full page/post/event content type plus real Layout
 * Builder-enabled displays and overridden nodes to exercise meaningfully.
 * That fixture is left as a GAP -- see the module test log. This class covers
 * updateTempStore(), the one method with an isolated, mockable contract: a
 * real round trip through the key_value_expire table.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Service\LayoutUpdaterLegacy
 *
 * @group yalesites
 * @group ys_layouts
 */
class LayoutUpdaterLegacyTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * The service under test.
   *
   * @var \Drupal\ys_layouts\Service\LayoutUpdaterLegacy
   */
  protected $legacyUpdater;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->legacyUpdater = new LayoutUpdaterLegacy();
  }

  /**
   * With no key_value_expire table, updateTempStore() is a clean no-op.
   *
   * @covers ::updateTempStore
   */
  public function testUpdateTempStoreIsNoOpWithoutTable(): void {
    $this->assertFalse($this->container->get('database')->schema()->tableExists('key_value_expire'));

    $called = FALSE;
    $this->legacyUpdater->updateTempStore(function () use (&$called) {
      $called = TRUE;
    });

    $this->assertFalse($called, 'The process callback must not run when there is nothing stored.');
  }

  /**
   * An in-place mutation of the stored section_storage object persists.
   *
   * Populates the table the same way Drupal's key/value expirable store
   * does, then confirms updateTempStore() finds the row, hands it to the
   * process callback by reference, and persists the mutation back. This
   * mirrors how the real callers in this class use it: they call mutating
   * methods on the existing $stored_data->data['section_storage'] object
   * (e.g. removeSection()/insertSection()) rather than replacing it.
   *
   * @covers ::updateTempStore
   */
  public function testUpdateTempStoreRoundTripsInPlaceMutation(): void {
    $keyValueExpirable = $this->container->get('keyvalue.expirable')->get(
      'tempstore.shared.layout_builder.section_storage.overrides'
    );
    $sectionStorage = (object) ['label' => 'original'];
    $keyValueExpirable->setWithExpire('node.42.default.en', (object) [
      'owner' => 'test',
      'data' => ['section_storage' => $sectionStorage],
      'updated' => 100,
    ], 3600);

    $this->legacyUpdater->updateTempStore(function (&$stored_data) {
      // Mutate the existing object's property in place, the way the real
      // callers mutate a Section/SectionStorage object via its methods.
      $stored_data->data['section_storage']->label = 'mutated';
    });

    $reloaded = $keyValueExpirable->get('node.42.default.en');
    $this->assertSame('mutated', $reloaded->data['section_storage']->label);
  }

  /**
   * Replacing section_storage outright is silently discarded, not saved.
   *
   * The method captures $stored_data->data['section_storage'] BEFORE
   * invoking the process callback, then unconditionally writes that
   * captured reference back into $stored_data->data['section_storage']
   * AFTER the callback runs -- clobbering any wholesale replacement the
   * callback made. It happens to be harmless for every current caller in
   * this class, because they all mutate the existing object's own state in
   * place (see testUpdateTempStoreRoundTripsInPlaceMutation()) rather than
   * assigning a new value to that array key. A future caller that replaced
   * the value outright would have the replacement silently dropped.
   *
   * @covers ::updateTempStore
   */
  public function testUpdateTempStoreDiscardsWholesaleReplacement(): void {
    $keyValueExpirable = $this->container->get('keyvalue.expirable')->get(
      'tempstore.shared.layout_builder.section_storage.overrides'
    );
    $keyValueExpirable->setWithExpire('node.42.default.en', (object) [
      'owner' => 'test',
      'data' => ['section_storage' => (object) ['label' => 'original']],
      'updated' => 100,
    ], 3600);

    $this->legacyUpdater->updateTempStore(function (&$stored_data) {
      $stored_data->data['section_storage'] = (object) ['label' => 'replaced'];
    });

    $reloaded = $keyValueExpirable->get('node.42.default.en');
    $this->assertSame('original', $reloaded->data['section_storage']->label);
  }

}
