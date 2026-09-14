<?php

namespace Drupal\Tests\ys_toolbar\Unit;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Menu\LocalTaskManagerInterface;
use Drupal\Core\Routing\RedirectDestination;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_toolbar\ToolbarItemsService;

/**
 * Unit tests for ToolbarItemsService.
 *
 * @coversDefaultClass \Drupal\ys_toolbar\ToolbarItemsService
 * @group ys_toolbar
 * @group yalesites
 */
class ToolbarItemsServiceTest extends UnitTestCase {

  /**
   * The access manager mock.
   *
   * @var \Drupal\Core\Access\AccessManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $accessManager;

  /**
   * The route match mock.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $routeMatch;

  /**
   * The redirect destination mock.
   *
   * @var \Drupal\Core\Routing\RedirectDestination|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $redirectDestination;

  /**
   * The current user mock.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $account;

  /**
   * The local task manager mock.
   *
   * @var \Drupal\Core\Menu\LocalTaskManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $localTaskManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->accessManager = $this->createMock(AccessManagerInterface::class);
    $this->routeMatch = $this->createMock(RouteMatchInterface::class);
    $this->redirectDestination = $this->createMock(RedirectDestination::class);
    $this->account = $this->createMock(AccountInterface::class);
    $this->localTaskManager = $this->createMock(LocalTaskManagerInterface::class);
  }

  /**
   * Creates a service instance for a given route parameter and route name.
   *
   * @param mixed $node
   *   The value returned for the route's "node" parameter.
   * @param string $routeName
   *   The current route name.
   *
   * @return \Drupal\ys_toolbar\ToolbarItemsService
   *   The service under test.
   */
  protected function createService($node, string $routeName = ''): ToolbarItemsService {
    $this->routeMatch->method('getParameter')
      ->with('node')
      ->willReturn($node);
    $this->routeMatch->method('getRouteName')
      ->willReturn($routeName);

    return new ToolbarItemsService(
      $this->accessManager,
      $this->routeMatch,
      $this->redirectDestination,
      $this->account,
      $this->localTaskManager
    );
  }

  /**
   * Creates a mock node.
   *
   * @param bool $published
   *   Whether the mock node reports as published.
   * @param int $id
   *   The node ID.
   *
   * @return \Drupal\node\NodeInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mock node.
   */
  protected function createMockNode(bool $published = TRUE, int $id = 1) {
    $node = $this->createMock(NodeInterface::class);
    $node->method('isPublished')->willReturn($published);
    $node->method('id')->willReturn($id);
    return $node;
  }

  /**
   * Invokes a protected or private method via reflection.
   *
   * @param object $object
   *   The object to invoke the method on.
   * @param string $methodName
   *   The method name.
   * @param array $args
   *   Arguments to pass to the method.
   *
   * @return mixed
   *   The method's return value.
   */
  protected function invokeMethod($object, string $methodName, array $args = []) {
    $reflection = new \ReflectionClass($object);
    $method = $reflection->getMethod($methodName);
    $method->setAccessible(TRUE);
    return $method->invokeArgs($object, $args);
  }

  /**
   * Tests isCurrentRouteNode() when the route has a node parameter.
   *
   * @covers ::isCurrentRouteNode
   */
  public function testIsCurrentRouteNodeTrueForNode() {
    $service = $this->createService($this->createMockNode());
    $this->assertTrue($service->isCurrentRouteNode());
  }

  /**
   * Tests isCurrentRouteNode() when there is no node parameter.
   *
   * @covers ::isCurrentRouteNode
   */
  public function testIsCurrentRouteNodeFalseWhenNoNode() {
    $service = $this->createService(NULL);
    $this->assertFalse($service->isCurrentRouteNode());
  }

  /**
   * Tests isCurrentRouteNode() when the "node" parameter isn't a node.
   *
   * @covers ::isCurrentRouteNode
   */
  public function testIsCurrentRouteNodeFalseForNonNodeParameter() {
    $service = $this->createService('not-a-node-object');
    $this->assertFalse($service->isCurrentRouteNode());
  }

  /**
   * Tests isViewRoute() on the node canonical route.
   *
   * @covers ::isViewRoute
   */
  public function testIsViewRouteTrueOnCanonicalRoute() {
    $service = $this->createService($this->createMockNode(), 'entity.node.canonical');
    $this->assertTrue($this->invokeMethod($service, 'isViewRoute'));
  }

  /**
   * Tests isViewRoute() on an unrelated route.
   *
   * @covers ::isViewRoute
   */
  public function testIsViewRouteFalseOnOtherRoute() {
    $service = $this->createService($this->createMockNode(), 'entity.node.edit_form');
    $this->assertFalse($this->invokeMethod($service, 'isViewRoute'));
  }

  /**
   * Tests isEditRoute() on the node edit route.
   *
   * @covers ::isEditRoute
   */
  public function testIsEditRouteTrueOnEditForm() {
    $service = $this->createService($this->createMockNode(), 'entity.node.edit_form');
    $this->assertTrue($this->invokeMethod($service, 'isEditRoute'));
  }

  /**
   * Tests isEditRoute() on an unrelated route.
   *
   * @covers ::isEditRoute
   */
  public function testIsEditRouteFalseOnOtherRoute() {
    $service = $this->createService($this->createMockNode(), 'entity.node.canonical');
    $this->assertFalse($this->invokeMethod($service, 'isEditRoute'));
  }

  /**
   * Tests showEditButton() is true on a node route other than edit.
   *
   * @covers ::showEditButton
   */
  public function testShowEditButtonTrueOnViewRoute() {
    $service = $this->createService($this->createMockNode(), 'entity.node.canonical');
    $this->assertTrue($this->invokeMethod($service, 'showEditButton'));
  }

  /**
   * Tests showEditButton() is false on the edit form itself.
   *
   * @covers ::showEditButton
   */
  public function testShowEditButtonFalseOnEditRoute() {
    $service = $this->createService($this->createMockNode(), 'entity.node.edit_form');
    $this->assertFalse($this->invokeMethod($service, 'showEditButton'));
  }

  /**
   * Tests showEditButton() is false when not viewing a node.
   *
   * @covers ::showEditButton
   */
  public function testShowEditButtonFalseWhenNotNode() {
    $service = $this->createService(NULL, 'entity.node.canonical');
    $this->assertFalse($this->invokeMethod($service, 'showEditButton'));
  }

  /**
   * Tests showPublishButton() is true for an unpublished node on view route.
   *
   * @covers ::showPublishButton
   */
  public function testShowPublishButtonTrueWhenUnpublishedOnViewRoute() {
    $service = $this->createService($this->createMockNode(FALSE), 'entity.node.canonical');
    $this->assertTrue($this->invokeMethod($service, 'showPublishButton'));
  }

  /**
   * Tests showPublishButton() is false for a published node.
   *
   * @covers ::showPublishButton
   */
  public function testShowPublishButtonFalseWhenPublished() {
    $service = $this->createService($this->createMockNode(TRUE), 'entity.node.canonical');
    $this->assertFalse($this->invokeMethod($service, 'showPublishButton'));
  }

  /**
   * Tests showPublishButton() is false off the view route.
   *
   * @covers ::showPublishButton
   */
  public function testShowPublishButtonFalseWhenNotViewRoute() {
    $service = $this->createService($this->createMockNode(FALSE), 'entity.node.edit_form');
    $this->assertFalse($this->invokeMethod($service, 'showPublishButton'));
  }

  /**
   * Tests showUnpublishButton() is true for a published node on view route.
   *
   * @covers ::showUnpublishButton
   */
  public function testShowUnpublishButtonTrueWhenPublishedOnViewRoute() {
    $service = $this->createService($this->createMockNode(TRUE), 'entity.node.canonical');
    $this->assertTrue($this->invokeMethod($service, 'showUnpublishButton'));
  }

  /**
   * Tests showUnpublishButton() is false for an unpublished node.
   *
   * @covers ::showUnpublishButton
   */
  public function testShowUnpublishButtonFalseWhenUnpublished() {
    $service = $this->createService($this->createMockNode(FALSE), 'entity.node.canonical');
    $this->assertFalse($this->invokeMethod($service, 'showUnpublishButton'));
  }

  /**
   * Tests showUnpublishButton() is false off the view route.
   *
   * @covers ::showUnpublishButton
   */
  public function testShowUnpublishButtonFalseWhenNotViewRoute() {
    $service = $this->createService($this->createMockNode(TRUE), 'entity.node.edit_form');
    $this->assertFalse($this->invokeMethod($service, 'showUnpublishButton'));
  }

  /**
   * Tests getNodeRouteParams() returns the current node's ID.
   *
   * @covers ::getNodeRouteParams
   */
  public function testGetNodeRouteParamsReturnsNodeId() {
    $service = $this->createService($this->createMockNode(TRUE, 42));
    $this->assertSame(['node' => 42], $this->invokeMethod($service, 'getNodeRouteParams'));
  }

  /**
   * Tests buildButton() grants access when the access manager allows it.
   *
   * @covers ::buildButton
   */
  public function testBuildButtonAccessGranted() {
    $this->accessManager->method('checkNamedRoute')->willReturn(TRUE);
    $service = $this->createService($this->createMockNode(TRUE, 7));

    $button = $this->invokeMethod($service, 'buildButton', ['entity.node.edit_form', 'Manage Settings']);

    $this->assertSame('toolbar_item', $button['#type']);
    $this->assertSame('Manage Settings', $button['tab']['#title']);
    $this->assertTrue($button['tab']['#access']);
    $this->assertSame('entity.node.edit_form', $button['tab']['#url']->getRouteName());
    $this->assertSame(['node' => 7], $button['tab']['#url']->getRouteParameters());
    $this->assertSame(['url.path'], $button['tab']['#cache']['contexts']);
  }

  /**
   * Tests buildButton() denies access when the access manager disallows it.
   *
   * @covers ::buildButton
   */
  public function testBuildButtonAccessDenied() {
    $this->accessManager->method('checkNamedRoute')->willReturn(FALSE);
    $service = $this->createService($this->createMockNode());

    $button = $this->invokeMethod($service, 'buildButton', ['entity.node.edit_form', 'Manage Settings']);

    $this->assertFalse($button['tab']['#access']);
  }

  /**
   * Tests buildButton() derives its CSS class from the label.
   *
   * @covers ::buildButton
   */
  public function testBuildButtonClassNameDerivedFromLabel() {
    $this->accessManager->method('checkNamedRoute')->willReturn(TRUE);
    $service = $this->createService($this->createMockNode());

    $button = $this->invokeMethod($service, 'buildButton', ['entity.node.edit_form', 'Manage Settings']);

    $this->assertSame(
      ['toolbar-icon', 'toolbar-icon-edit', 'manage-settings'],
      $button['tab']['#attributes']['class']
    );
  }

  /**
   * Tests buildOffCanvasButton() includes the redirect destination in the URL.
   *
   * @covers ::buildOffCanvasButton
   */
  public function testBuildOffCanvasButtonUrlIncludesRedirectDestination() {
    $this->redirectDestination->method('getAsArray')->willReturn(['destination' => '/node/1']);
    $service = $this->createService(NULL);

    $button = $this->invokeMethod($service, 'buildOffCanvasButton', ['ys_themes.theme_settings', 'Theme Settings']);

    $this->assertSame(['destination' => '/node/1'], $button['tab']['#url']->getRouteParameters());
  }

  /**
   * The off-canvas button's #access reflects the manage-settings permission.
   *
   * BuildOffCanvasButton() sets '#access' to the result of
   * $account->hasPermission('yalesites manage settings'), so the Theme Settings
   * link is denied for a user without that permission (Drupal's renderer treats
   * a FALSE '#access' as denied).
   *
   * @covers ::buildOffCanvasButton
   */
  public function testBuildOffCanvasButtonAccessChecksPermission() {
    $this->account->method('hasPermission')->with('yalesites manage settings')->willReturn(FALSE);
    $service = $this->createService(NULL);

    $button = $this->invokeMethod($service, 'buildOffCanvasButton', ['ys_themes.theme_settings', 'Theme Settings']);

    $this->assertFalse($button['tab']['#access']);
  }

  /**
   * The button is shown to a user with the manage-settings permission.
   *
   * @covers ::buildOffCanvasButton
   */
  public function testBuildOffCanvasButtonAccessGrantedForPermittedUser() {
    $this->account->method('hasPermission')->with('yalesites manage settings')->willReturn(TRUE);
    $service = $this->createService(NULL);

    $button = $this->invokeMethod($service, 'buildOffCanvasButton', ['ys_themes.theme_settings', 'Theme Settings']);

    $this->assertTrue($button['tab']['#access']);
  }

  /**
   * Tests addItems() returns nothing when not viewing a node.
   *
   * @covers ::addItems
   */
  public function testAddItemsReturnsEmptyWhenNotNodeRoute() {
    $service = $this->createService(NULL, 'some.other.route');
    $this->assertSame([], $service->addItems());
  }

  /**
   * Tests addItems() always includes the layout and theme settings links.
   *
   * @covers ::addItems
   */
  public function testAddItemsAlwaysIncludesLayoutAndThemeSettingsLinks() {
    $this->accessManager->method('checkNamedRoute')->willReturn(TRUE);
    $this->redirectDestination->method('getAsArray')->willReturn([]);
    $service = $this->createService($this->createMockNode(TRUE), 'entity.node.canonical');

    $items = $service->addItems();

    $this->assertArrayHasKey('toolbar_layout_link', $items);
    $this->assertArrayHasKey('toolbar_theme_settings_link', $items);
  }

  /**
   * Tests addItems() includes the edit link on the canonical route.
   *
   * @covers ::addItems
   */
  public function testAddItemsIncludesEditLinkOnViewRoute() {
    $this->accessManager->method('checkNamedRoute')->willReturn(TRUE);
    $this->redirectDestination->method('getAsArray')->willReturn([]);
    $service = $this->createService($this->createMockNode(TRUE), 'entity.node.canonical');

    $items = $service->addItems();

    $this->assertArrayHasKey('toolbar_edit_link', $items);
  }

  /**
   * Tests addItems() excludes the edit link on the edit form itself.
   *
   * @covers ::addItems
   */
  public function testAddItemsExcludesEditLinkOnEditRoute() {
    $this->accessManager->method('checkNamedRoute')->willReturn(TRUE);
    $this->redirectDestination->method('getAsArray')->willReturn([]);
    $service = $this->createService($this->createMockNode(TRUE), 'entity.node.edit_form');

    $items = $service->addItems();

    $this->assertArrayNotHasKey('toolbar_edit_link', $items);
  }

  /**
   * Tests addItems() includes a publish link for an unpublished node.
   *
   * @covers ::addItems
   */
  public function testAddItemsIncludesPublishLinkForUnpublishedNode() {
    $this->accessManager->method('checkNamedRoute')->willReturn(TRUE);
    $this->redirectDestination->method('getAsArray')->willReturn([]);
    $service = $this->createService($this->createMockNode(FALSE), 'entity.node.canonical');

    $items = $service->addItems();

    $this->assertArrayHasKey('toolbar_publish_link', $items);
    $this->assertArrayNotHasKey('toolbar_unpublish_link', $items);
  }

  /**
   * Tests addItems() includes an unpublish link for a published node.
   *
   * @covers ::addItems
   */
  public function testAddItemsIncludesUnpublishLinkForPublishedNode() {
    $this->accessManager->method('checkNamedRoute')->willReturn(TRUE);
    $this->redirectDestination->method('getAsArray')->willReturn([]);
    $service = $this->createService($this->createMockNode(TRUE), 'entity.node.canonical');

    $items = $service->addItems();

    $this->assertArrayHasKey('toolbar_unpublish_link', $items);
    $this->assertArrayNotHasKey('toolbar_publish_link', $items);
  }

}
