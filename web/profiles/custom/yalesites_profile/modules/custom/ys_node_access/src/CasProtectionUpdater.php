<?php

namespace Drupal\ys_node_access;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\content_moderation\StateTransitionValidationInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Turns CAS protection on or off for one node, as the bulk actions do.
 *
 * The node form changes field_login_required on whichever revision the editor
 * saves, and content moderation decides whether that goes live. A bulk action
 * has no form, so this class makes the same decisions explicitly: who may make
 * the change, which revisions it is written to, and what counts as nothing to
 * do. Saving the node is what rewrites its node access grants -- see
 * \Drupal\node\Entity\Node::postSave() -- so a protected page is hidden from
 * anonymous visitors as soon as apply() returns.
 *
 * @see \Drupal\ys_node_access\CasProtectionBatch
 */
class CasProtectionUpdater {

  /**
   * The field that gates a node behind CAS.
   */
  public const FIELD = 'field_login_required';

  /**
   * The node now has the requested value, and a new revision records it.
   */
  public const CHANGED = 'changed';

  /**
   * The node already had the requested value, so nothing was saved.
   */
  public const UNCHANGED = 'unchanged';

  /**
   * The account may not change this node's CAS protection.
   */
  public const DENIED = 'denied';

  /**
   * The node no longer exists, or cannot be CAS protected at all.
   */
  public const MISSING = 'missing';

  /**
   * Constructs a CasProtectionUpdater.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\content_moderation\ModerationInformationInterface|null $moderationInformation
   *   The moderation information service. content_moderation is a declared
   *   dependency, so on a site this is always present. It is optional so the
   *   container still builds where that module is not enabled -- several of
   *   this module's kernel tests enable it alone -- and in that case no
   *   content is moderated, so edit access alone decides.
   * @param \Drupal\content_moderation\StateTransitionValidationInterface|null $transitionValidation
   *   The state transition validation service, likewise optional.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected TimeInterface $time,
    protected ?ModerationInformationInterface $moderationInformation = NULL,
    protected ?StateTransitionValidationInterface $transitionValidation = NULL,
  ) {}

  /**
   * Checks whether an account may change a node's CAS protection.
   *
   * Edit access to the node and to the field is the baseline, the same as the
   * node form. On top of that, a change to a published node goes live the
   * moment it is saved, so the account must also be one that could publish it.
   * Without that, a Contributor -- who can edit published content, but only
   * into a draft that an Editor then publishes -- could change who is able to
   * see a live page. Unpublished content has no live revision to change, so
   * edit access is enough there, as it is on the form.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node, as its default revision.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account making the change.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(NodeInterface $node, AccountInterface $account): AccessResultInterface {
    if (!$node->hasField(self::FIELD)) {
      return AccessResult::forbidden('This content type cannot be CAS protected.')->addCacheableDependency($node);
    }

    return $node->access('update', $account, TRUE)
      ->andIf($node->get(self::FIELD)->access('edit', $account, TRUE))
      ->andIf($this->liveChangeAccess($node, $account));
  }

  /**
   * Sets CAS protection on a node, unless it already has that value.
   *
   * The node's default revision is what visitors see and what its access
   * grants are computed from, so that is the revision that has to change. If
   * an editor has a draft in progress on top of it, the draft changes too, for
   * two reasons. Saving the default revision makes it the latest one, which
   * would leave the draft behind: the edit form would stop opening it. And a
   * draft left on the old value would quietly undo this change the moment it
   * was published.
   *
   * Each revision is saved in its current moderation state, so published
   * content stays published and a draft stays a draft. Both saves share one
   * transaction, so a failure part way through cannot leave the draft behind.
   *
   * @param int $nid
   *   The ID of the node to update.
   * @param bool $protect
   *   TRUE to require CAS login to view the node, FALSE to stop requiring it.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account making the change, which is checked for access and recorded
   *   as the author of any new revisions.
   *
   * @return string
   *   One of this class's CHANGED, UNCHANGED, DENIED or MISSING constants.
   */
  public function apply(int $nid, bool $protect, AccountInterface $account): string {
    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    $node = $storage->load($nid);
    if (!$node instanceof NodeInterface || !$node->hasField(self::FIELD)) {
      return self::MISSING;
    }
    if (!$this->access($node, $account)->isAllowed()) {
      return self::DENIED;
    }

    $draft = NULL;
    $latest_revision_id = $storage->getLatestRevisionId($nid);
    if ($latest_revision_id && $latest_revision_id != $node->getRevisionId()) {
      $draft = $storage->loadRevision($latest_revision_id);
    }

    $live_changes = $this->isProtected($node) !== $protect;
    if (!$live_changes && (!$draft || $this->isProtected($draft) === $protect)) {
      return self::UNCHANGED;
    }

    $transaction = $this->database->startTransaction();
    try {
      if ($live_changes) {
        $this->saveRevision($node, $protect, $account);
      }
      // Saved whenever anything was, even if the draft already had the value:
      // it is the only way to put the draft back on top of the new default
      // revision.
      if ($draft) {
        $this->saveRevision($draft, $protect, $account);
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }

    return self::CHANGED;
  }

  /**
   * Checks the account could publish a change to a published, moderated node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node, as its default revision.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account making the change.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed when the change would not go live or the account could publish
   *   it, forbidden otherwise.
   */
  protected function liveChangeAccess(NodeInterface $node, AccountInterface $account): AccessResultInterface {
    if (!$node->isPublished() || !$this->moderationInformation?->isModeratedEntity($node)) {
      return AccessResult::allowed()->addCacheableDependency($node);
    }

    $workflow = $this->moderationInformation->getWorkflowForEntity($node);
    $state = $workflow->getTypePlugin()->getState($node->get('moderation_state')->value);
    $result = $this->transitionValidation->isTransitionValid($workflow, $state, $state, $account, $node)
      ? AccessResult::allowed()
      : AccessResult::forbidden('Changing CAS protection on published content requires permission to publish it.');

    return $result
      ->cachePerPermissions()
      ->addCacheableDependency($node)
      ->addCacheableDependency($workflow);
  }

  /**
   * Saves a revision with a new CAS protection value, as a new revision.
   *
   * @param \Drupal\node\NodeInterface $revision
   *   The revision to save.
   * @param bool $protect
   *   The value to save.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account recorded as the new revision's author.
   */
  protected function saveRevision(NodeInterface $revision, bool $protect, AccountInterface $account): void {
    $revision->set(self::FIELD, $protect);
    $revision->setNewRevision(TRUE);
    $revision->setRevisionUserId($account->id());
    $revision->setRevisionCreationTime($this->time->getRequestTime());
    $revision->setRevisionLogMessage($protect
      ? 'CAS protection enabled with a bulk action.'
      : 'CAS protection removed with a bulk action.');
    $revision->save();
  }

  /**
   * Reads a revision's CAS protection value.
   *
   * @param \Drupal\node\NodeInterface $revision
   *   The revision.
   *
   * @return bool
   *   TRUE if the revision requires CAS login to view.
   */
  protected function isProtected(NodeInterface $revision): bool {
    return (bool) $revision->get(self::FIELD)->value;
  }

}
