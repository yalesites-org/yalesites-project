<?php

namespace Drupal\Tests\ys_node_access\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use Drupal\ys_node_access\NodeAccessManager;

/**
 * Tests ys_node_access's node grants system end to end.
 *
 * Ys_node_access_node_grants() and ys_node_access_node_access_records() (in
 * ys_node_access.module) are meant to restrict the canonical view of a node
 * to CAS-authenticated users when field_login_required is set. These tests
 * characterize both the hooks directly and the resulting real
 * $node->access('view', $account) outcome, since the node grants system
 * (see \Drupal\node\NodeGrantDatabaseStorage::access()) is what actually
 * enforces this, not the hooks in isolation.
 *
 * testUnpublishedNodeAccessShouldRespectOwnershipAndPermission (below) and
 * testUnpublishedNodeWithoutFieldShouldStayPrivate (below) characterize
 * GAPs logged at
 * ~/Documents/Claude/not_dave/module-tests-20260710/ys_node_access.md.
 * The over-broad-exposure GAP that report describes (any authenticated user
 * viewing another's unpublished draft) was since fixed by commit 01ceadd7f
 * (issue #1396) -- testUnpublishedNodeAccessShouldRespectOwnershipAndPermission
 * now asserts that fixed behavior, not a skipped GAP. That fix's own
 * regression -- unpublished nodes disappearing from node-access-gated
 * listings for everyone, regardless of permissions -- is issue #1486, fixed
 * by the grants added below.
 *
 * @group yalesites
 * @group ys_node_access
 */
class NodeAccessGrantsTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'node',
    'content_moderation',
    'workflows',
    'field',
    'text',
    'user',
    'ys_node_access',
  ];

  /**
   * An anonymous user session.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $anonymous;

  /**
   * A plain authenticated user, with no roles beyond "authenticated".
   *
   * @var \Drupal\user\UserInterface
   */
  protected $authenticated;

  /**
   * A second authenticated user, used as a draft node's owner.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $owner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    // 'user' config provides the anonymous/authenticated roles the grants
    // below load and grant permissions to.
    $this->installConfig(['node', 'user']);

    // Grant the baseline permission node access checks require before they
    // even consult the grants system.
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('access content')->save();
    Role::load(RoleInterface::AUTHENTICATED_ID)->grantPermission('access content')->save();

    // A content type carrying field_login_required, mirroring how it is
    // attached to page/post/event/profile/resource in production.
    NodeType::create(['type' => 'protected_type', 'name' => 'Protected type'])->save();
    $field_storage = FieldStorageConfig::create([
      'field_name' => 'field_login_required',
      'entity_type' => 'node',
      'type' => 'boolean',
    ]);
    $field_storage->save();
    FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'protected_type',
      'label' => 'CAS Login Required',
    ])->save();

    // A content type that never received the field, to characterize the
    // module's fallback when field_login_required is absent.
    NodeType::create(['type' => 'unprotected_type', 'name' => 'Unprotected type'])->save();

    $this->anonymous = new AnonymousUserSession();
    $this->authenticated = User::create(['name' => 'authenticated_user', 'uid' => 2]);
    $this->authenticated->save();
    $this->owner = User::create(['name' => 'owner', 'uid' => 3]);
    $this->owner->save();
  }

  /**
   * Tests hook_node_grants() for the 'view' operation.
   *
   * @covers ::ys_node_access_node_grants
   */
  public function testNodeGrantsForViewOperation() {
    $this->assertEquals(
      [NodeAccessManager::YS_NODE_ACCESS_REALM => [NodeAccessManager::YS_NODE_ACCESS_GRANT_ID_PUBLIC]],
      ys_node_access_node_grants($this->anonymous, 'view')
    );

    $this->assertEquals(
      [
        NodeAccessManager::YS_NODE_ACCESS_REALM => [
          NodeAccessManager::YS_NODE_ACCESS_GRANT_ID_PUBLIC,
          NodeAccessManager::YS_NODE_ACCESS_GRANT_ID_PRIVATE,
        ],
      ],
      ys_node_access_node_grants($this->authenticated, 'view')
    );
  }

  /**
   * Tests the anonymous role never reaches the two unpublished realms.
   *
   * Even if "view own/any unpublished content" were (mis)granted to the
   * anonymous role, ys_node_access_node_grants()'s isAuthenticated() guard
   * must still exclude anonymous -- otherwise a uid-0-owned node (e.g. from
   * a migration) could become publicly visible via the owner realm.
   *
   * @covers ::ys_node_access_node_grants
   */
  public function testNodeGrantsExcludeAnonymousFromUnpublishedRealmsEvenIfGranted() {
    Role::load(RoleInterface::ANONYMOUS_ID)
      ->grantPermission('view own unpublished content')
      ->grantPermission('view any unpublished content')
      ->save();

    $this->assertEquals(
      [NodeAccessManager::YS_NODE_ACCESS_REALM => [NodeAccessManager::YS_NODE_ACCESS_GRANT_ID_PUBLIC]],
      ys_node_access_node_grants(new AnonymousUserSession(), 'view')
    );
  }

  /**
   * Tests hook_node_grants() for operations other than 'view'.
   *
   * @covers ::ys_node_access_node_grants
   */
  public function testNodeGrantsForNonViewOperationsReturnNothing() {
    $this->assertNull(ys_node_access_node_grants($this->authenticated, 'update'));
    $this->assertNull(ys_node_access_node_grants($this->authenticated, 'delete'));
  }

  /**
   * Tests hook_node_access_records() for a published, CAS-gated node.
   *
   * @covers ::ys_node_access_node_access_records
   */
  public function testNodeAccessRecordsPrivateWhenLoginRequired() {
    $node = Node::create([
      'type' => 'protected_type',
      'title' => 'CAS gated',
      'field_login_required' => TRUE,
      'status' => 1,
    ]);

    $this->assertEquals([[
      'realm' => NodeAccessManager::YS_NODE_ACCESS_REALM,
      'gid' => NodeAccessManager::YS_NODE_ACCESS_GRANT_ID_PRIVATE,
      'grant_view' => 1,
      'grant_update' => 0,
      'grant_delete' => 0,
      'priority' => 0,
    ],
    ], ys_node_access_node_access_records($node));
  }

  /**
   * Tests hook_node_access_records() for a published, non-gated node.
   *
   * @covers ::ys_node_access_node_access_records
   */
  public function testNodeAccessRecordsPublicWhenLoginNotRequired() {
    $node = Node::create([
      'type' => 'protected_type',
      'title' => 'Public',
      'field_login_required' => FALSE,
      'status' => 1,
    ]);

    $this->assertEquals([[
      'realm' => NodeAccessManager::YS_NODE_ACCESS_REALM,
      'gid' => NodeAccessManager::YS_NODE_ACCESS_GRANT_ID_PUBLIC,
      'grant_view' => 1,
      'grant_update' => 0,
      'grant_delete' => 0,
      'priority' => 0,
    ],
    ], ys_node_access_node_access_records($node));
  }

  /**
   * Tests hook_node_access_records() grants unpublished nodes to viewers.
   *
   * Grants go to the owner and to "view any unpublished content" holders,
   * replacing the grant_view = 0 record the prior approach used -- see
   * ys_node_access_node_access_records() for why that was insufficient.
   * Regression test for #1486.
   *
   * @covers ::ys_node_access_node_access_records
   */
  public function testNodeAccessRecordsUnpublishedGrantsOwnerAndAnyUnpublishedViewers() {
    $node = Node::create([
      'type' => 'protected_type',
      'title' => 'Unpublished',
      'field_login_required' => FALSE,
      'status' => 0,
      'uid' => $this->owner->id(),
    ]);

    $this->assertEquals([
      [
        'realm' => NodeAccessManager::YS_NODE_ACCESS_UNPUBLISHED_REALM,
        'gid' => NodeAccessManager::YS_NODE_ACCESS_GRANT_ID_UNPUBLISHED_ANY,
        'grant_view' => 1,
        'grant_update' => 0,
        'grant_delete' => 0,
        'priority' => 0,
      ],
      [
        'realm' => NodeAccessManager::YS_NODE_ACCESS_UNPUBLISHED_OWNER_REALM,
        'gid' => $this->owner->id(),
        'grant_view' => 1,
        'grant_update' => 0,
        'grant_delete' => 0,
        'priority' => 0,
      ],
    ], ys_node_access_node_access_records($node));
  }

  /**
   * Tests unpublished-node grants reach owners and permitted viewers only.
   *
   * Owners and "view any unpublished content" holders can see an unpublished
   * node via the grants system; unrelated authenticated users cannot. This
   * is the layer admin/content and "Manage <type>" listings actually
   * enforce (\Drupal\node\NodeGrantDatabaseStorage::access()) -- unlike
   * $node->access(), which independently allows the "view any unpublished
   * content" case via content_moderation's hook_entity_access(), outside
   * the grants system entirely. Regression test for #1486.
   */
  public function testUnpublishedNodeVisibleToPermittedUsersViaNodeAccessGrants() {
    $grant_storage = \Drupal::service('node.grant_storage');

    $node = Node::create([
      'type' => 'protected_type',
      'title' => 'Unpublished',
      'field_login_required' => FALSE,
      'status' => 0,
      'uid' => $this->owner->id(),
    ]);
    $node->save();

    // A plain authenticated user with neither permission still cannot see
    // it via the grants system -- this must not reopen the over-exposure
    // this module's grants were introduced to close.
    $this->assertFalse($grant_storage->access($node, 'view', $this->authenticated)->isAllowed());

    // A "view any unpublished content" holder (e.g. site_admin/editor) can,
    // even though they are not the owner. Each permission below is granted
    // via its own dedicated role (rather than mutating the shared
    // "authenticated" role) to keep each scenario below isolated.
    $any_viewer_role = Role::create(['id' => 'ys_test_any_unpub_viewer', 'label' => 'Any unpub viewer']);
    $any_viewer_role->grantPermission('view any unpublished content')->save();
    $any_viewer = User::create(['name' => 'any_viewer', 'uid' => 4, 'roles' => [$any_viewer_role->id()]]);
    $any_viewer->save();
    $this->assertTrue($grant_storage->access($node, 'view', $any_viewer)->isAllowed());

    // A non-owner holding only "view own unpublished content" (not "view
    // any") must not reach another user's draft -- the owner realm is keyed
    // on the node's actual owner uid, not merely on holding the permission.
    $own_viewer_role = Role::create(['id' => 'ys_test_own_unpub_viewer', 'label' => 'Own unpub viewer']);
    $own_viewer_role->grantPermission('view own unpublished content')->save();
    $non_owner = User::create(['name' => 'non_owner', 'uid' => 5, 'roles' => [$own_viewer_role->id()]]);
    $non_owner->save();
    $this->assertFalse($grant_storage->access($node, 'view', $non_owner)->isAllowed());

    // The owner can see their own draft via "view own unpublished content".
    $this->owner->addRole($own_viewer_role->id());
    $this->owner->save();
    $this->assertTrue($grant_storage->access($node, 'view', User::load($this->owner->id()))->isAllowed());
  }

  /**
   * A published, non-gated node is viewable by anonymous and authenticated.
   */
  public function testPublishedNonCasNodeIsPublic() {
    $node = Node::create([
      'type' => 'protected_type',
      'title' => 'Public',
      'field_login_required' => FALSE,
      'status' => 1,
    ]);
    $node->save();

    $this->assertTrue($node->access('view', $this->anonymous));
    $this->assertTrue($node->access('view', $this->authenticated));
  }

  /**
   * A published CAS-gated node is hidden from anonymous, shown to authed.
   *
   * Any authenticated user, not specifically a CAS-authenticated one, since
   * Drupal's node access layer cannot distinguish how a user authenticated.
   */
  public function testPublishedCasGatedNodeHiddenFromAnonymousVisibleToAuthenticated() {
    $node = Node::create([
      'type' => 'protected_type',
      'title' => 'CAS gated',
      'field_login_required' => TRUE,
      'status' => 1,
    ]);
    $node->save();

    $this->assertFalse($node->access('view', $this->anonymous));
    $this->assertTrue($node->access('view', $this->authenticated));
  }

  /**
   * Unpublished-view access should require ownership or the permission.
   *
   * GAP: it should still require ownership or the "view own unpublished
   * content" permission, not just being logged in.
   */
  public function testUnpublishedNodeAccessShouldRespectOwnershipAndPermission() {
    $access_handler = \Drupal::entityTypeManager()->getAccessControlHandler('node');

    $node = Node::create([
      'type' => 'protected_type',
      'title' => 'Someone else\'s draft',
      'field_login_required' => FALSE,
      'status' => 0,
      'uid' => $this->owner->id(),
    ]);
    $node->save();

    // A plain authenticated user who neither owns the draft nor holds "view
    // own unpublished content" cannot view it.
    $this->assertFalse($this->authenticated->hasPermission('view own unpublished content'));
    $this->assertFalse($node->access('view', $this->authenticated));

    // Once "view own unpublished content" is granted, the owner can view their
    // own draft, but a non-owner still cannot -- ownership is respected, not
    // just login state.
    Role::load(RoleInterface::AUTHENTICATED_ID)->grantPermission('view own unpublished content')->save();
    $access_handler->resetCache();
    $this->assertTrue($node->access('view', User::load($this->owner->id())));
    $access_handler->resetCache();
    $this->assertFalse($node->access('view', User::load($this->authenticated->id())));
  }

  /**
   * Nodes without field_login_required should not be public when unpublished.
   *
   * GAP: a content type that never received the field is treated as public
   * even while unpublished.
   */
  public function testUnpublishedNodeWithoutFieldShouldStayPrivate() {
    $node = Node::create([
      'type' => 'unprotected_type',
      'title' => 'Unpublished, no field',
      'status' => 0,
    ]);
    $node->save();

    // Even though the content type never received field_login_required, an
    // unpublished node must not be exposed to anonymous users.
    $this->assertFalse($node->hasField('field_login_required'));
    $this->assertFalse($node->access('view', $this->anonymous));
  }

}
