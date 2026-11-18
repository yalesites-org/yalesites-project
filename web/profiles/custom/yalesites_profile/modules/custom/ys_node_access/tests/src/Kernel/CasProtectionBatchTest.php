<?php

namespace Drupal\Tests\ys_node_access\Kernel;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\ys_node_access\CasProtectionBatch;
use Drupal\ys_node_access\CasProtectionUpdater;

/**
 * Tests the batch that runs a bulk CAS protection change.
 *
 * @coversDefaultClass \Drupal\ys_node_access\CasProtectionBatch
 * @group yalesites
 * @group ys_node_access
 */
class CasProtectionBatchTest extends CasProtectionKernelTestBase {

  /**
   * A selection bigger than one pass is spread across several passes.
   *
   * Each pass is one request when the batch runs in a browser, so this is
   * what keeps a large selection from running into a request's time limit.
   *
   * @covers ::build
   * @covers ::process
   */
  public function testLargeSelectionIsProcessedAcrossPasses(): void {
    $this->setCurrentUser($this->editor);
    $nids = [];
    for ($i = 0; $i < CasProtectionBatch::NODES_PER_PASS * 2 + 3; $i++) {
      $nids[] = (int) $this->createCasNode('published', FALSE, "Page $i")->id();
    }

    $batch = CasProtectionBatch::build($nids, TRUE);
    $this->assertSame([[[CasProtectionBatch::class, 'process'], [$nids, TRUE]]], $batch['operations']);
    $this->assertSame([CasProtectionBatch::class, 'finish'], $batch['finished']);

    $context = ['sandbox' => [], 'results' => [], 'finished' => 1, 'message' => ''];
    $passes = 0;
    do {
      CasProtectionBatch::process($nids, TRUE, $context);
      $passes++;
      if ($passes === 1) {
        $this->assertLessThan(1, $context['finished'], 'The first pass leaves work for later passes.');
        $this->assertEquals('Updated 10 of 23 items.', (string) $context['message']);
      }
    } while ($context['finished'] < 1 && $passes < 10);

    $this->assertSame(3, $passes);
    $this->assertSame(count($nids), $context['results'][CasProtectionUpdater::CHANGED]);
    foreach ($nids as $nid) {
      $this->assertTrue((bool) $this->reloadNode($nid)->get('field_login_required')->value, "Node $nid is protected.");
    }
  }

  /**
   * Every outcome is counted, and one failure does not stop the rest.
   *
   * @covers ::process
   */
  public function testOutcomesAreCountedAndFailuresDoNotStopTheBatch(): void {
    $this->setCurrentUser($this->contributor);
    $changed = $this->createCasNode('draft', FALSE, 'Draft');
    $unchanged = $this->createCasNode('draft', TRUE, 'Already protected');
    $denied = $this->createCasNode('published', FALSE, 'Live page');
    $failing = $this->createCasNode('draft', FALSE, 'Fails to save');
    $after_failure = $this->createCasNode('draft', FALSE, 'After the failure');

    // A real updater, except that one node's save throws.
    $real = $this->container->get('ys_node_access.cas_protection_updater');
    $updater = $this->createMock(CasProtectionUpdater::class);
    $updater->method('apply')->willReturnCallback(function ($nid, $protect, $account) use ($real, $failing) {
      if ($nid == $failing->id()) {
        throw new \RuntimeException('Simulated save failure.');
      }
      return $real->apply($nid, $protect, $account);
    });
    $this->container->set('ys_node_access.cas_protection_updater', $updater);

    $context = ['sandbox' => [], 'results' => [], 'finished' => 1, 'message' => ''];
    $nids = [$changed->id(), $unchanged->id(), $denied->id(), $failing->id(), $after_failure->id(), 999];
    CasProtectionBatch::process(array_map('intval', $nids), TRUE, $context);

    $this->assertEquals(1, $context['finished']);
    $this->assertSame([
      'protect' => TRUE,
      CasProtectionUpdater::CHANGED => 2,
      CasProtectionUpdater::UNCHANGED => 1,
      CasProtectionUpdater::DENIED => 1,
      CasProtectionUpdater::MISSING => 1,
      'failed' => 1,
    ], $context['results']);
    $this->assertTrue((bool) $this->reloadNode($after_failure->id())->get('field_login_required')->value, 'The node after the failure was still updated.');
  }

  /**
   * The finish message reports how many items changed, and why others did not.
   *
   * @covers ::finish
   */
  public function testFinishReportsEachOutcome(): void {
    CasProtectionBatch::finish(TRUE, [
      'protect' => TRUE,
      CasProtectionUpdater::CHANGED => 12,
      CasProtectionUpdater::UNCHANGED => 3,
      CasProtectionUpdater::DENIED => 2,
      CasProtectionUpdater::MISSING => 1,
      'failed' => 1,
    ], []);

    $messages = $this->messages();
    $this->assertSame([
      'Enabled CAS protection on 12 items.',
      '3 items were already CAS protected, so they were skipped.',
    ], $messages[MessengerInterface::TYPE_STATUS]);
    $this->assertSame([
      '2 items were not changed because you do not have permission to change their CAS protection.',
      '1 item no longer exists, so it was skipped.',
    ], $messages[MessengerInterface::TYPE_WARNING]);
    $this->assertSame([
      '1 item could not be updated because of an error. The error has been logged.',
    ], $messages[MessengerInterface::TYPE_ERROR]);
  }

  /**
   * Removal reports in its own terms, and quiet outcomes stay quiet.
   *
   * @covers ::finish
   */
  public function testFinishReportsRemoval(): void {
    CasProtectionBatch::finish(TRUE, [
      'protect' => FALSE,
      CasProtectionUpdater::CHANGED => 1,
      CasProtectionUpdater::UNCHANGED => 1,
      CasProtectionUpdater::DENIED => 0,
      CasProtectionUpdater::MISSING => 0,
      'failed' => 0,
    ], []);

    $this->assertSame([
      MessengerInterface::TYPE_STATUS => [
        'Removed CAS protection from 1 item.',
        '1 item was not CAS protected, so it was skipped.',
      ],
    ], $this->messages());
  }

  /**
   * When every item was skipped, there is no "changed 0 items" message.
   *
   * @covers ::finish
   */
  public function testFinishOmitsZeroChangedWhenEverythingWasSkipped(): void {
    CasProtectionBatch::finish(TRUE, [
      'protect' => TRUE,
      CasProtectionUpdater::CHANGED => 0,
      CasProtectionUpdater::UNCHANGED => 4,
      CasProtectionUpdater::DENIED => 0,
      CasProtectionUpdater::MISSING => 0,
      'failed' => 0,
    ], []);

    $this->assertSame([
      MessengerInterface::TYPE_STATUS => ['4 items were already CAS protected, so they were skipped.'],
    ], $this->messages());
  }

  /**
   * Returns the messages set so far, as plain strings keyed by type.
   *
   * @return array
   *   The messages.
   */
  protected function messages(): array {
    return array_map(
      fn (array $messages) => array_map('strval', $messages),
      $this->container->get('messenger')->all(),
    );
  }

}
