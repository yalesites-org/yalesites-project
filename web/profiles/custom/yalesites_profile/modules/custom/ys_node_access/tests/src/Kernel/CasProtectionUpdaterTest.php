<?php

namespace Drupal\Tests\ys_node_access\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\ys_node_access\CasProtectionUpdater;

/**
 * Tests how the bulk actions change CAS protection on a single node.
 *
 * @coversDefaultClass \Drupal\ys_node_access\CasProtectionUpdater
 * @group yalesites
 * @group ys_node_access
 */
class CasProtectionUpdaterTest extends CasProtectionKernelTestBase {

  /**
   * The updater under test.
   *
   * @var \Drupal\ys_node_access\CasProtectionUpdater
   */
  protected CasProtectionUpdater $updater;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->updater = $this->container->get('ys_node_access.cas_protection_updater');
  }

  /**
   * Protecting a published page hides it from anonymous visitors right away.
   *
   * @covers ::apply
   */
  public function testProtectingPublishedNodeRewritesItsGrants(): void {
    $node = $this->createCasNode('published', FALSE);
    $this->assertTrue($node->access('view', new AnonymousUserSession()));
    $revision_before = $node->getRevisionId();

    $this->assertSame(CasProtectionUpdater::CHANGED, $this->updater->apply($node->id(), TRUE, $this->editor));

    $node = $this->reloadNode($node->id());
    $this->assertTrue((bool) $node->get('field_login_required')->value);
    $this->assertTrue($node->isPublished(), 'The page is still published.');
    $this->assertSame('published', $node->get('moderation_state')->value);
    $this->assertGreaterThan($revision_before, $node->getRevisionId(), 'The change was saved as a new revision.');
    $this->assertSame('CAS protection enabled with a bulk action.', $node->getRevisionLogMessage());
    $this->assertEquals($this->editor->id(), $node->getRevisionUserId());

    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $this->assertFalse($node->access('view', new AnonymousUserSession()), 'Anonymous visitors can no longer see the page.');
    $this->assertTrue($node->access('view', $this->contributor), 'Logged-in users still can.');
  }

  /**
   * Removing protection makes a published page public again.
   *
   * @covers ::apply
   */
  public function testRemovingProtectionMakesPageVisibleToAnonymous(): void {
    $node = $this->createCasNode('published', TRUE);
    $this->assertFalse($node->access('view', new AnonymousUserSession()));

    $this->assertSame(CasProtectionUpdater::CHANGED, $this->updater->apply($node->id(), FALSE, $this->editor));

    $node = $this->reloadNode($node->id());
    $this->assertFalse((bool) $node->get('field_login_required')->value);
    $this->assertSame('CAS protection removed with a bulk action.', $node->getRevisionLogMessage());
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $this->assertTrue($node->access('view', new AnonymousUserSession()));
  }

  /**
   * A node already in the requested state is skipped, with nothing saved.
   *
   * @covers ::apply
   */
  public function testNodeAlreadyInTargetStateIsUnchanged(): void {
    $protected = $this->createCasNode('published', TRUE);
    $unprotected = $this->createCasNode('published', FALSE);

    $this->assertSame(CasProtectionUpdater::UNCHANGED, $this->updater->apply($protected->id(), TRUE, $this->editor));
    $this->assertSame(CasProtectionUpdater::UNCHANGED, $this->updater->apply($unprotected->id(), FALSE, $this->editor));

    $this->assertEquals($protected->getRevisionId(), $this->reloadNode($protected->id())->getRevisionId());
    $this->assertEquals($unprotected->getRevisionId(), $this->reloadNode($unprotected->id())->getRevisionId());
  }

  /**
   * A draft in progress is kept, and carries the change forward.
   *
   * It keeps its edits and stays the latest revision, and gets the new value
   * too, so publishing it later cannot undo the change.
   *
   * @covers ::apply
   */
  public function testDraftInProgressIsCarriedForward(): void {
    $node = $this->createCasNode('published', FALSE, 'Live title');
    $this->addDraft($node, 'Draft title');

    $this->assertSame(CasProtectionUpdater::CHANGED, $this->updater->apply($node->id(), TRUE, $this->editor));

    $live = $this->reloadNode($node->id());
    $this->assertSame('Live title', $live->getTitle(), 'The live revision does not pick up the draft\'s edits.');
    $this->assertTrue((bool) $live->get('field_login_required')->value);
    $this->assertTrue($live->isPublished());

    $draft = $this->latestRevision($node->id());
    $this->assertNotEquals($live->getRevisionId(), $draft->getRevisionId(), 'The draft is still pending on top of the live revision.');
    $this->assertSame('Draft title', $draft->getTitle(), 'The draft keeps its edits.');
    $this->assertSame('draft', $draft->get('moderation_state')->value);
    $this->assertTrue((bool) $draft->get('field_login_required')->value, 'The draft carries the change.');
  }

  /**
   * A draft that disagrees with an already-correct live revision is fixed.
   *
   * The live page is left alone, since it already has the value; only the
   * draft is saved, so it no longer reverts the protection when published.
   *
   * @covers ::apply
   */
  public function testDraftIsUpdatedWhenOnlyItDiffers(): void {
    $node = $this->createCasNode('published', TRUE, 'Live title');
    $this->addDraft($node, 'Draft title', FALSE);
    $live_revision = $this->reloadNode($node->id())->getRevisionId();

    $this->assertSame(CasProtectionUpdater::CHANGED, $this->updater->apply($node->id(), TRUE, $this->editor));

    $this->assertEquals($live_revision, $this->reloadNode($node->id())->getRevisionId(), 'The live revision was not re-saved.');
    $draft = $this->latestRevision($node->id());
    $this->assertSame('Draft title', $draft->getTitle());
    $this->assertTrue((bool) $draft->get('field_login_required')->value);
  }

  /**
   * A never-published draft is changed in place as a draft, unpublished.
   *
   * @covers ::apply
   */
  public function testUnpublishedDraftStaysDraft(): void {
    $node = $this->createCasNode('draft', FALSE);

    $this->assertSame(CasProtectionUpdater::CHANGED, $this->updater->apply($node->id(), TRUE, $this->contributor));

    $node = $this->reloadNode($node->id());
    $this->assertTrue((bool) $node->get('field_login_required')->value);
    $this->assertFalse($node->isPublished());
    $this->assertSame('draft', $node->get('moderation_state')->value);
  }

  /**
   * An unpublished (archived) page stays unpublished.
   *
   * @covers ::apply
   */
  public function testArchivedNodeStaysArchived(): void {
    $node = $this->createCasNode('published', FALSE);
    $node->set('moderation_state', 'archived');
    $node->save();

    $this->assertSame(CasProtectionUpdater::CHANGED, $this->updater->apply($node->id(), TRUE, $this->editor));

    $node = $this->reloadNode($node->id());
    $this->assertTrue((bool) $node->get('field_login_required')->value);
    $this->assertFalse($node->isPublished());
    $this->assertSame('archived', $node->get('moderation_state')->value);
  }

  /**
   * A Contributor cannot change protection on a live page.
   *
   * They can edit published content on the node form, but only into a draft
   * that someone who can publish then publishes, so the bulk action must not
   * let them change who can see the live page directly.
   *
   * @covers ::access
   * @covers ::apply
   */
  public function testContributorCannotChangePublishedNode(): void {
    $node = $this->createCasNode('published', FALSE);

    $this->assertTrue($node->access('update', $this->contributor), 'Precondition: the contributor can edit the node.');
    $this->assertFalse($this->updater->access($node, $this->contributor)->isAllowed());
    $this->assertSame(CasProtectionUpdater::DENIED, $this->updater->apply($node->id(), TRUE, $this->contributor));
    $this->assertFalse((bool) $this->reloadNode($node->id())->get('field_login_required')->value);

    $this->assertTrue($this->updater->access($node, $this->editor)->isAllowed());
  }

  /**
   * Someone who cannot edit the node cannot change its protection.
   *
   * @covers ::access
   * @covers ::apply
   */
  public function testAccountWithoutEditAccessIsDenied(): void {
    $node = $this->createCasNode('draft', FALSE);
    $viewer = $this->createUser(['access content', 'view any unpublished content']);

    $this->assertFalse($this->updater->access($node, $viewer)->isAllowed());
    $this->assertSame(CasProtectionUpdater::DENIED, $this->updater->apply($node->id(), TRUE, $viewer));
    $this->assertFalse((bool) $this->reloadNode($node->id())->get('field_login_required')->value);
  }

  /**
   * Deleted nodes and content types without the field are reported missing.
   *
   * @covers ::access
   * @covers ::apply
   */
  public function testMissingNodeOrFieldIsReportedMissing(): void {
    $this->assertSame(CasProtectionUpdater::MISSING, $this->updater->apply(999, TRUE, $this->editor));

    NodeType::create(['type' => 'unprotected_type', 'name' => 'Unprotected type'])->save();
    $node = Node::create(['type' => 'unprotected_type', 'title' => 'No field', 'status' => 1]);
    $node->save();
    $this->assertFalse($this->updater->access($node, $this->editor)->isAllowed());
    $this->assertSame(CasProtectionUpdater::MISSING, $this->updater->apply($node->id(), TRUE, $this->editor));
  }

}
