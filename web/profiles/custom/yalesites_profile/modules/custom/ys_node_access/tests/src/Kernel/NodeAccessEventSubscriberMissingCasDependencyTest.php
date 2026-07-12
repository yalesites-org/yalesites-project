<?php

namespace Drupal\Tests\ys_node_access\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\ys_node_access\EventSubscriber\NodeAccessEventSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Tests NodeAccessEventSubscriber::on403() when the 'cas' module is absent.
 *
 * Ys_node_access.info.yml declares only "drupal:node" as a dependency, but
 * on403() unconditionally calls Url::fromRoute('cas.login', ...), a route
 * that only exists when the contrib 'cas' module is installed. On every
 * real YaleSites site 'cas' is enabled via config (core.extension.yml), so
 * this is latent rather than active -- but nothing in Drupal's dependency
 * system stops 'cas' from being disabled while ys_node_access stays
 * enabled. This test characterizes what happens if that were to occur.
 * Paired with testMissingCasRouteShouldDegradeGracefully() -- delete once
 * the GAP is fixed.
 *
 * @group yalesites
 * @group ys_node_access
 */
class NodeAccessEventSubscriberMissingCasDependencyTest extends KernelTestBase {

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
   * An anonymous visit to a gated node fatals when 'cas' is absent.
   *
   * It throws a RouteNotFoundException instead of falling back to the default
   * 403, because 'cas' (an undeclared dependency) is not installed.
   */
  public function testUncaughtExceptionWhenCasModuleAbsent() {
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    // Saving the node below runs ys_node_access_node_access_records(), which
    // writes to the node_access table.
    $this->installSchema('node', ['node_access']);

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

    $node = Node::create([
      'type' => 'protected_type',
      'title' => 'CAS gated',
      'field_login_required' => TRUE,
      'status' => 1,
    ]);
    $node->save();

    $request = Request::create('/node/' . $node->id());
    $request->attributes->set('node', $node);
    $event = new ExceptionEvent(
      $this->createMock(HttpKernelInterface::class),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
      new AccessDeniedHttpException()
    );

    $subscriber = new NodeAccessEventSubscriber(new AnonymousUserSession());
    $this->expectException(RouteNotFoundException::class);
    $subscriber->on403($event);
  }

  /**
   * GAP: on403() should not fatal when 'cas' is unavailable.
   */
  public function testMissingCasRouteShouldDegradeGracefully() {
    $this->markTestSkipped('GAP: NodeAccessEventSubscriber::on403() calls Url::fromRoute(\'cas.login\', ...) unconditionally; if the \'cas\' module is ever disabled while ys_node_access stays enabled (nothing enforces this, since ys_node_access.info.yml does not declare cas as a dependency), an anonymous visit to a published, login-required node throws an uncaught RouteNotFoundException instead of a normal 403 -- see ~/Documents/Claude/not_dave/module-tests-20260710/ys_node_access.md');
  }

}
