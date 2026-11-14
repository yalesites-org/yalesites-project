<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\ys_core\Plugin\Action\ModeratedPublish;
use Drupal\ys_core\Plugin\Action\ModeratedUnpublish;

/**
 * Tests access on the moderated publish and unpublish bulk actions.
 *
 * @group ys_core
 * @group yalesites
 */
class ModeratedActionAccessTest extends YsKernelTestBase {

  use ContentModerationTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'workflows',
    'content_moderation',
    'ys_core',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('workflow');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'filter']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $workflow = $this->createEditorialWorkflow();
    $workflow->getTypePlugin()->addEntityTypeAndBundle('node', 'page');
    $workflow->save();

    // Uid 1 bypasses every access check, so burn it first.
    $this->setUpCurrentUser();
  }

  /**
   * Both actions, as the plugin each one is.
   */
  public static function actionProvider(): array {
    return [
      'publish' => [ModeratedPublish::class, 'entity:publish_moderated_action'],
      'unpublish' => [ModeratedUnpublish::class, 'entity:unpublish_moderated_action'],
    ];
  }

  /**
   * Builds the action plugin for nodes.
   */
  protected function action(string $class, string $id) {
    $manager = $this->container->get('plugin.manager.action');
    return $manager->createInstance($id . ':node');
  }

  /**
   * Creates an editor who can update pages and use the editorial transitions.
   */
  protected function createEditor() {
    return $this->createUser([
      'access content',
      'edit any page content',
      'use editorial transition publish',
      'use editorial transition archive',
    ]);
  }

  /**
   * Creates a saved node of the page bundle.
   */
  protected function node(): Node {
    $node = Node::create(['type' => 'page', 'title' => 'Test']);
    $node->moderation_state = 'draft';
    $node->save();
    return $node;
  }

  /**
   * An editor blocked only by moderation is allowed, as a plain boolean.
   *
   * @dataProvider actionProvider
   */
  public function testModerationOnlyBlockIsAllowed(string $class, string $id): void {
    $account = $this->createEditor();
    $action = $this->action($class, $id);
    $this->assertInstanceOf($class, $action);
    $node = $this->node();

    $this->assertTrue($action->access($node, $account, FALSE));
  }

  /**
   * Asking for the object returns an object, even in the moderation case.
   *
   * @dataProvider actionProvider
   */
  public function testModerationOnlyBlockReturnsObjectWhenAsked(string $class, string $id): void {
    $account = $this->createEditor();
    $action = $this->action($class, $id);
    $node = $this->node();

    $result = $action->access($node, $account, TRUE);
    $this->assertInstanceOf(AccessResultInterface::class, $result);
    $this->assertTrue($result->isAllowed());
  }

  /**
   * An editor without update access is denied, in both return formats.
   *
   * @dataProvider actionProvider
   */
  public function testEditorWithoutUpdateAccessIsDenied(string $class, string $id): void {
    $account = $this->createUser(['access content']);
    $action = $this->action($class, $id);
    $node = $this->node();

    $this->assertFalse($action->access($node, $account, FALSE));
    $result = $action->access($node, $account, TRUE);
    $this->assertInstanceOf(AccessResultInterface::class, $result);
    $this->assertFalse($result->isAllowed());
  }

}
