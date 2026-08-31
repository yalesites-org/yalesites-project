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

/**
 * Tests NodeAccessEventSubscriber::on403() when the 'cas' module is absent.
 *
 * Ys_node_access.info.yml declares only "drupal:node" as a dependency, but
 * on403() redirects to the 'cas.login' route, which only exists when the
 * contrib 'cas' module is installed. On every real YaleSites site 'cas' is
 * enabled via config (core.extension.yml), but nothing in Drupal's dependency
 * system stops 'cas' from being disabled while ys_node_access stays enabled.
 * on403() must degrade gracefully (leave the default 403 in place) rather than
 * throw a RouteNotFoundException.
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
   * On403() degrades gracefully when the 'cas' module is absent.
   *
   * 'cas' is not a declared dependency of ys_node_access, so its login route
   * may be unavailable. In that case on403() must leave the default 403 in
   * place (set no redirect response) instead of throwing a
   * RouteNotFoundException.
   */
  public function testMissingCasRouteShouldDegradeGracefully() {
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
    $subscriber->on403($event);

    // With 'cas' absent, no redirect response is set and the default 403
    // response stands.
    $this->assertNull($event->getResponse());
  }

}
