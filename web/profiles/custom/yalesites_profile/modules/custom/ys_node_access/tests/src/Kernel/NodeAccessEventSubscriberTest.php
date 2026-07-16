<?php

namespace Drupal\Tests\ys_node_access\Kernel;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use Drupal\ys_node_access\EventSubscriber\NodeAccessEventSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests NodeAccessEventSubscriber's CAS-login redirect on 403 responses.
 *
 * For an anonymous visitor hitting a published node with
 * field_login_required set, the subscriber replaces the default 403 with a
 * TrustedRedirectResponse to cas.login. These tests exercise the real 'cas'
 * route so the generated redirect is characterized against actual behavior
 * rather than an assumption about what Url::fromRoute() would produce.
 *
 * @group yalesites
 * @group ys_node_access
 */
class NodeAccessEventSubscriberTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'node',
    'field',
    'text',
    'user',
    'externalauth',
    'cas',
    'ys_node_access',
  ];

  /**
   * A stub HTTP kernel for the ExceptionEvent constructor (unused by on403()).
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    // Saving a node runs ys_node_access_node_access_records(), which writes to
    // the node_access table; 'user' config supplies the default roles.
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['user']);

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

    $this->httpKernel = $this->createMock(HttpKernelInterface::class);
  }

  /**
   * Builds an ExceptionEvent carrying a 403 for the given node.
   */
  protected function build403Event(Node $node): ExceptionEvent {
    $request = Request::create('/node/' . $node->id());
    $request->attributes->set('node', $node);
    return new ExceptionEvent($this->httpKernel, $request, HttpKernelInterface::MAIN_REQUEST, new AccessDeniedHttpException());
  }

  /**
   * Anonymous visitor to a published CAS-gated node redirects to cas.login.
   *
   * The original node is the redirect destination and the response is not
   * cached.
   */
  public function testRedirectsAnonymousToCasLoginForPublishedCasGatedNode() {
    $node = Node::create([
      'type' => 'protected_type',
      'title' => 'CAS gated',
      'field_login_required' => TRUE,
      'status' => 1,
    ]);
    $node->save();

    $subscriber = new NodeAccessEventSubscriber(new AnonymousUserSession());
    $event = $this->build403Event($node);
    $subscriber->on403($event);

    $response = $event->getResponse();
    $this->assertInstanceOf(TrustedRedirectResponse::class, $response);
    $target = $response->getTargetUrl();
    $target_parts = parse_url($target);
    parse_str($target_parts['query'] ?? '', $query);
    $this->assertStringContainsString('/caslogin', $target_parts['path']);
    $this->assertSame($node->toUrl()->toString(), $query['destination']);
    $this->assertSame(0, $response->getCacheableMetadata()->getCacheMaxAge());
  }

  /**
   * An authenticated visitor is never redirected, even to a gated node.
   */
  public function testDoesNotRedirectAuthenticatedUser() {
    $node = Node::create([
      'type' => 'protected_type',
      'title' => 'CAS gated',
      'field_login_required' => TRUE,
      'status' => 1,
    ]);
    $node->save();

    $authenticated = User::create(['name' => 'authenticated_user', 'uid' => 2]);
    $authenticated->save();

    $subscriber = new NodeAccessEventSubscriber($authenticated);
    $event = $this->build403Event($node);
    $subscriber->on403($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * No redirect when the node does not require login.
   */
  public function testDoesNotRedirectWhenLoginNotRequired() {
    $node = Node::create([
      'type' => 'protected_type',
      'title' => 'Public',
      'field_login_required' => FALSE,
      'status' => 1,
    ]);
    $node->save();

    $subscriber = new NodeAccessEventSubscriber(new AnonymousUserSession());
    $event = $this->build403Event($node);
    $subscriber->on403($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * No redirect for an unpublished, gated node.
   *
   * The redirect only fires for published nodes, so an unpublished gated
   * node's 403 is left as-is.
   */
  public function testDoesNotRedirectWhenNodeUnpublished() {
    $node = Node::create([
      'type' => 'protected_type',
      'title' => 'Unpublished, CAS gated',
      'field_login_required' => TRUE,
      'status' => 0,
    ]);
    $node->save();

    $subscriber = new NodeAccessEventSubscriber(new AnonymousUserSession());
    $event = $this->build403Event($node);
    $subscriber->on403($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * No redirect (and no error) when the content type lacks the CAS field.
   */
  public function testDoesNotRedirectWhenFieldAbsent() {
    NodeType::create(['type' => 'unprotected_type', 'name' => 'Unprotected type'])->save();
    $node = Node::create([
      'type' => 'unprotected_type',
      'title' => 'No CAS field',
      'status' => 1,
    ]);
    $node->save();

    $subscriber = new NodeAccessEventSubscriber(new AnonymousUserSession());
    $event = $this->build403Event($node);
    $subscriber->on403($event);

    $this->assertNull($event->getResponse());
  }

}
