<?php

namespace Drupal\Tests\ys_node_access\Kernel;

use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\Tests\ys_node_access\Traits\CasProtectedNodeTypeTrait;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;

/**
 * Sets up a moderated, CAS-protectable content type and the roles that edit it.
 *
 * The bulk CAS protection actions make different decisions for published and
 * unpublished content, for content with a draft in progress, and for people who
 * can or cannot publish -- so every test of them needs the editorial workflow
 * and both an Editor-like and a Contributor-like account. The workflow and the
 * permissions mirror the platform's own editorial workflow and roles.
 */
abstract class CasProtectionKernelTestBase extends YsKernelTestBase {

  use CasProtectedNodeTypeTrait;
  use ContentModerationTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'workflows',
    'content_moderation',
    'ys_node_access',
  ];

  /**
   * Can edit and publish the content type, like a Site Administrator or Editor.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $editor;

  /**
   * Can edit the content type but only save drafts, like a Contributor.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $contributor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'user', 'content_moderation']);

    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('access content')->save();
    Role::load(RoleInterface::AUTHENTICATED_ID)->grantPermission('access content')->save();

    $this->createCasProtectedNodeType();
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'protected_type');

    // User 1 bypasses every access check, so it is taken here, before the
    // accounts under test are created.
    $this->createUser();

    $edit = [
      'access content',
      'access content overview',
      'edit any protected_type content',
      'view any unpublished content',
    ];
    $this->editor = $this->createUser(array_merge($edit, [
      'use editorial transition create_new_draft',
      'use editorial transition publish',
      'use editorial transition archive',
      'use editorial transition archived_draft',
      'use editorial transition archived_published',
    ]), 'editor');
    $this->contributor = $this->createUser(array_merge($edit, [
      'use editorial transition create_new_draft',
    ]), 'contributor');
  }

  /**
   * Creates and saves a node of the CAS-protectable content type.
   *
   * @param string $moderation_state
   *   The moderation state to save it in: draft, published or archived.
   * @param bool $protected
   *   Whether it requires CAS login to view.
   * @param string $title
   *   The node's title.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  protected function createCasNode(string $moderation_state, bool $protected, string $title = 'Test page'): NodeInterface {
    $node = Node::create([
      'type' => 'protected_type',
      'title' => $title,
      'moderation_state' => $moderation_state,
      'field_login_required' => $protected,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Adds a draft on top of a published node, as an editor working on it would.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The published node.
   * @param string $title
   *   The draft's title, so a test can tell the draft from the live revision.
   * @param bool|null $protected
   *   The draft's CAS protection value, or NULL to keep the live value.
   *
   * @return \Drupal\node\NodeInterface
   *   The draft revision.
   */
  protected function addDraft(NodeInterface $node, string $title, ?bool $protected = NULL): NodeInterface {
    $draft = Node::load($node->id());
    $draft->setTitle($title);
    $draft->set('moderation_state', 'draft');
    if ($protected !== NULL) {
      $draft->set('field_login_required', $protected);
    }
    $draft->setNewRevision(TRUE);
    $draft->save();
    return $draft;
  }

  /**
   * Loads a node's default revision, bypassing the entity static cache.
   *
   * @param int|string $nid
   *   The node ID.
   *
   * @return \Drupal\node\NodeInterface
   *   The default revision as it is stored now.
   */
  protected function reloadNode($nid): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$nid]);
    return $storage->load($nid);
  }

  /**
   * Loads a node's latest revision, bypassing the entity static cache.
   *
   * @param int|string $nid
   *   The node ID.
   *
   * @return \Drupal\node\NodeInterface
   *   The latest revision, which is the draft when one is in progress.
   */
  protected function latestRevision($nid): NodeInterface {
    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$nid]);
    return $storage->loadRevision($storage->getLatestRevisionId($nid));
  }

}
