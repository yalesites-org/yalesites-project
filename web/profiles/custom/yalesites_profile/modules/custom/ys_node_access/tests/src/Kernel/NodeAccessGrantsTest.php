<?php

namespace Drupal\Tests\ys_node_access\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
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
 * Two of the tests below (testAnyAuthenticatedUserCanViewAnotherUsers...
 * and testUnpublishedNodeWithoutLoginRequiredFieldIsPubliclyViewable) are
 * paired with a skipped GAP test documenting current behavior that is
 * broader than the module's stated purpose. See the GAP log at
 * ~/Documents/Claude/not_dave/module-tests-20260710/ys_node_access.md.
 *
 * @group yalesites
 * @group ys_node_access
 */
class NodeAccessGrantsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'node',
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
   * Tests hook_node_access_records() for an unpublished, non-gated node.
   *
   * Unpublished status alone is enough to mark the node private, as long as
   * the content type carries field_login_required.
   *
   * @covers ::ys_node_access_node_access_records
   */
  public function testNodeAccessRecordsPrivateWhenUnpublished() {
    $node = Node::create([
      'type' => 'protected_type',
      'title' => 'Unpublished',
      'field_login_required' => FALSE,
      'status' => 0,
    ]);

    $this->assertEquals(NodeAccessManager::YS_NODE_ACCESS_GRANT_ID_PRIVATE, ys_node_access_node_access_records($node)[0]['gid']);
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
   * Any authenticated user can view another user's unpublished content.
   *
   * Ys_node_access_node_access_records() marks a node private (grant ID
   * PRIVATE) whenever it is unpublished, but ys_node_access_node_grants()
   * hands every authenticated user both the PUBLIC and PRIVATE grant IDs --
   * not only owners or accounts with "view own unpublished content" -- so
   * any logged-in user can view any other user's unpublished draft, on any
   * content type that carries field_login_required (page, post, event,
   * profile, resource in production). This is broader than Drupal's normal
   * unpublished-content protection and broader than this module's stated
   * purpose of CAS-gating specific pages. Paired with
   * testUnpublishedNodeAccessShouldRespectOwnershipAndPermission() -- delete
   * once the GAP is fixed.
   */
  public function testAnyAuthenticatedUserCanViewAnotherUsersUnpublishedNode() {
    $owner = User::create(['name' => 'owner', 'uid' => 3]);
    $owner->save();

    $node = Node::create([
      'type' => 'protected_type',
      'title' => 'Someone else\'s draft',
      'field_login_required' => FALSE,
      'status' => 0,
      'uid' => $owner->id(),
    ]);
    $node->save();

    // $this->authenticated owns nothing here and has no "view own
    // unpublished content" permission, yet can still view the draft.
    $this->assertFalse($this->authenticated->hasPermission('view own unpublished content'));
    $this->assertTrue($node->access('view', $this->authenticated));
  }

  /**
   * Unpublished-view access should require ownership or the permission.
   *
   * GAP: it should still require ownership or the "view own unpublished
   * content" permission, not just being logged in.
   */
  public function testUnpublishedNodeAccessShouldRespectOwnershipAndPermission() {
    $this->markTestSkipped('GAP: any authenticated user can view any other user\'s unpublished node on a content type with field_login_required, regardless of ownership or the "view own unpublished content" permission -- see ~/Documents/Claude/not_dave/module-tests-20260710/ys_node_access.md');
  }

  /**
   * A content type without field_login_required stays public when unpublished.
   *
   * Ys_node_access_node_access_records() only marks a node private when
   * $node->hasField('field_login_required') is true; if a content type
   * never gets the field attached (e.g. a new type added through the
   * Drupal UI, which nothing in this module or its .install hook prevents),
   * $private stays FALSE regardless of publish status, so the node gets a
   * PUBLIC grant record and is visible to anonymous users even while
   * unpublished. In production every shipped content type (page, post,
   * event, profile, resource) currently has the field, so this is latent
   * rather than actively exploited today -- but it activates automatically
   * for any new content type a site builder adds without attaching the
   * field. Paired with testUnpublishedNodeWithoutFieldShouldStayPrivate()
   * -- delete once the GAP is fixed.
   */
  public function testUnpublishedNodeWithoutLoginRequiredFieldIsPubliclyViewable() {
    $node = Node::create([
      'type' => 'unprotected_type',
      'title' => 'Unpublished, no field',
      'status' => 0,
    ]);
    $node->save();

    $this->assertFalse($node->hasField('field_login_required'));
    $this->assertTrue($node->access('view', $this->anonymous));
  }

  /**
   * Nodes without field_login_required should not be public when unpublished.
   *
   * GAP: a content type that never received the field is treated as public
   * even while unpublished.
   */
  public function testUnpublishedNodeWithoutFieldShouldStayPrivate() {
    $this->markTestSkipped('GAP: nodes on a content type that never received field_login_required are always granted a PUBLIC access record by ys_node_access_node_access_records(), so they are visible to anonymous users even while unpublished -- see ~/Documents/Claude/not_dave/module-tests-20260710/ys_node_access.md');
  }

}
