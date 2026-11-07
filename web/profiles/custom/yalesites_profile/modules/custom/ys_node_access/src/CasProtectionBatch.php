<?php

namespace Drupal\ys_node_access;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Utility\Error;

/**
 * Runs a bulk CAS protection change as a batch.
 *
 * Core's node_bulk_form executes an action on every selected row inside the
 * one request that submitted the form. Each node here is a full save -- a new
 * revision, its moderation state, its access grants, and whatever else reacts
 * to a node save -- so a long enough selection would run into PHP's time limit
 * part way through and leave the editor not knowing which items changed. The
 * Batch API spreads the saves across as many requests as they need instead.
 *
 * The selection reaches here through the private tempstore: the bulk actions
 * store it (see \Drupal\ys_node_access\Plugin\Action\CasProtectionActionBase)
 * and the confirmation form or the removal route reads it back and starts the
 * batch. Every node is loaded and access-checked again when its turn comes,
 * since it may have been edited or deleted since it was selected.
 */
final class CasProtectionBatch {

  /**
   * The private tempstore collection holding each user's pending selection.
   */
  public const TEMPSTORE = 'ys_node_access_cas_protection';

  /**
   * The tempstore key for a selection waiting to be protected.
   */
  public const PROTECT = 'protect';

  /**
   * The tempstore key for a selection waiting to have protection removed.
   */
  public const UNPROTECT = 'unprotect';

  /**
   * How many nodes each pass of the batch saves.
   *
   * A node can mean two saves when it has a draft in progress, so this is kept
   * small enough to leave plenty of headroom under a request's time limit.
   */
  public const NODES_PER_PASS = 10;

  /**
   * Builds the batch.
   *
   * @param int[] $nids
   *   The IDs of the nodes to update.
   * @param bool $protect
   *   TRUE to enable CAS protection, FALSE to remove it.
   *
   * @return array
   *   A batch definition, for batch_set().
   */
  public static function build(array $nids, bool $protect): array {
    return (new BatchBuilder())
      ->setTitle($protect
        ? new TranslatableMarkup('Enabling CAS protection')
        : new TranslatableMarkup('Removing CAS protection'))
      ->setInitMessage(new TranslatableMarkup('Starting…'))
      // The batch is one operation that reports its own item counts in
      // process(); core's default message would count operations, not items.
      ->setProgressMessage('')
      ->setErrorMessage(new TranslatableMarkup('CAS protection could not be updated for every item.'))
      ->addOperation([self::class, 'process'], [array_values(array_map('intval', $nids)), $protect])
      ->setFinishCallback([self::class, 'finish'])
      ->toArray();
  }

  /**
   * Batch operation: updates the next few nodes in the selection.
   *
   * @param int[] $nids
   *   The IDs of every node in the selection.
   * @param bool $protect
   *   TRUE to enable CAS protection, FALSE to remove it.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public static function process(array $nids, bool $protect, &$context): void {
    if (!isset($context['sandbox']['remaining'])) {
      $context['sandbox']['remaining'] = $nids;
      $context['sandbox']['total'] = count($nids);
      $context['results'] = [
        'protect' => $protect,
        CasProtectionUpdater::CHANGED => 0,
        CasProtectionUpdater::UNCHANGED => 0,
        CasProtectionUpdater::DENIED => 0,
        CasProtectionUpdater::MISSING => 0,
        'failed' => 0,
      ];
    }

    /** @var \Drupal\ys_node_access\CasProtectionUpdater $updater */
    $updater = \Drupal::service('ys_node_access.cas_protection_updater');
    $account = \Drupal::currentUser();

    foreach (array_splice($context['sandbox']['remaining'], 0, self::NODES_PER_PASS) as $nid) {
      // One node failing to save must not stop the rest of the selection, or
      // the editor is back to not knowing which items changed.
      try {
        $context['results'][$updater->apply($nid, $protect, $account)]++;
      }
      catch (\Throwable $e) {
        Error::logException(\Drupal::logger('ys_node_access'), $e, 'Could not update CAS protection on node @nid: %type: @message in %function (line %line of %file).', ['@nid' => $nid]);
        $context['results']['failed']++;
      }
    }

    $total = $context['sandbox']['total'];
    $done = $total - count($context['sandbox']['remaining']);
    $context['message'] = new TranslatableMarkup('Updated @done of @total items.', [
      '@done' => $done,
      '@total' => $total,
    ]);
    $context['finished'] = $total ? $done / $total : 1;
  }

  /**
   * Batch finished callback: reports what changed.
   *
   * Every outcome other than "changed" gets its own message, so the counts an
   * editor sees always add up to what they selected.
   *
   * @param bool $success
   *   Whether the batch completed without a fatal error.
   * @param array $results
   *   The counts process() collected.
   * @param array $operations
   *   Any operations that did not run.
   */
  public static function finish(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    $translation = \Drupal::translation();
    $protect = $results['protect'] ?? TRUE;
    $changed = $results[CasProtectionUpdater::CHANGED] ?? 0;
    $unchanged = $results[CasProtectionUpdater::UNCHANGED] ?? 0;
    $denied = $results[CasProtectionUpdater::DENIED] ?? 0;
    $missing = $results[CasProtectionUpdater::MISSING] ?? 0;
    $failed = $results['failed'] ?? 0;

    if ($changed || !($unchanged || $denied || $missing || $failed)) {
      $messenger->addStatus($protect
        ? $translation->formatPlural($changed, 'Enabled CAS protection on 1 item.', 'Enabled CAS protection on @count items.')
        : $translation->formatPlural($changed, 'Removed CAS protection from 1 item.', 'Removed CAS protection from @count items.'));
    }
    if ($unchanged) {
      $messenger->addStatus($protect
        ? $translation->formatPlural($unchanged, '1 item was already CAS protected, so it was skipped.', '@count items were already CAS protected, so they were skipped.')
        : $translation->formatPlural($unchanged, '1 item was not CAS protected, so it was skipped.', '@count items were not CAS protected, so they were skipped.'));
    }
    if ($denied) {
      $messenger->addWarning($translation->formatPlural($denied, '1 item was not changed because you do not have permission to change its CAS protection.', '@count items were not changed because you do not have permission to change their CAS protection.'));
    }
    if ($missing) {
      $messenger->addWarning($translation->formatPlural($missing, '1 item no longer exists, so it was skipped.', '@count items no longer exist, so they were skipped.'));
    }
    if ($failed) {
      $messenger->addError($translation->formatPlural($failed, '1 item could not be updated because of an error. The error has been logged.', '@count items could not be updated because of an error. The errors have been logged.'));
    }
    if (!$success) {
      $messenger->addError(new TranslatableMarkup('CAS protection could not be updated for every item. Check the CAS Protected column before trying again.'));
    }
  }

}
