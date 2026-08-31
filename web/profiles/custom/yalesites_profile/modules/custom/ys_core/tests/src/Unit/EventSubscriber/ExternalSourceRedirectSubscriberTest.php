<?php

namespace Drupal\Tests\ys_core\Unit\EventSubscriber;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_core\EventSubscriber\ExternalSourceRedirectSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests ExternalSourceRedirectSubscriber's external-source redirect logic.
 *
 * @coversDefaultClass \Drupal\ys_core\EventSubscriber\ExternalSourceRedirectSubscriber
 *
 * @group ys_core
 * @group yalesites
 */
class ExternalSourceRedirectSubscriberTest extends UnitTestCase {

  /**
   * The route match mock.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $routeMatch;

  /**
   * The node storage mock, returned by the entity type manager for 'node'.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $nodeStorage;

  /**
   * The kernel mock used to build real ViewEvent instances.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $kernel;

  /**
   * The subscriber under test.
   *
   * @var \Drupal\ys_core\EventSubscriber\ExternalSourceRedirectSubscriber
   */
  protected $subscriber;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->routeMatch = $this->createMock(RouteMatchInterface::class);

    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($this->nodeStorage);

    $this->subscriber = new ExternalSourceRedirectSubscriber($this->routeMatch, $entityTypeManager);
    $this->kernel = $this->createMock(HttpKernelInterface::class);
  }

  /**
   * Builds a real ViewEvent for the canonical node route.
   */
  protected function buildEvent(): ViewEvent {
    return new ViewEvent($this->kernel, Request::create('/some-page'), HttpKernelInterface::MAIN_REQUEST, []);
  }

  /**
   * Creates a mock node with the given bundle and SOURCE_FIELD presence.
   */
  protected function mockNode(string $bundle, bool $hasSourceField, int $id = 1): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn($bundle);
    $node->method('hasField')->with(ExternalSourceRedirectSubscriber::SOURCE_FIELD)->willReturn($hasSourceField);
    $node->method('id')->willReturn($id);
    return $node;
  }

  /**
   * Creates a mock link field whose first item resolves to the given URI.
   */
  protected function mockFieldWithUri(?string $uri): FieldItemListInterface {
    $item = $this->createMock(FieldItemInterface::class);
    $item->method('getValue')->willReturn($uri ? ['uri' => $uri] : []);

    $fieldList = $this->createMock(FieldItemListInterface::class);
    $fieldList->method('first')->willReturn($item);
    return $fieldList;
  }

  /**
   * @covers ::onKernelView
   */
  public function testNoRedirectOnNonCanonicalRoute(): void {
    $this->routeMatch->method('getRouteName')->willReturn('entity.node.edit_form');
    $event = $this->buildEvent();

    $this->subscriber->onKernelView($event);

    $this->assertFalse($event->hasResponse());
  }

  /**
   * @covers ::onKernelView
   */
  public function testNoRedirectWhenNoNodeOnRoute(): void {
    $this->routeMatch->method('getRouteName')->willReturn('entity.node.canonical');
    $this->routeMatch->method('getParameter')->with('node')->willReturn(NULL);
    $event = $this->buildEvent();

    $this->subscriber->onKernelView($event);

    $this->assertFalse($event->hasResponse());
  }

  /**
   * @covers ::onKernelView
   */
  public function testNoRedirectForResourceBundle(): void {
    $this->routeMatch->method('getRouteName')->willReturn('entity.node.canonical');
    $node = $this->mockNode('resource', TRUE);
    $this->routeMatch->method('getParameter')->with('node')->willReturn($node);
    $event = $this->buildEvent();

    $this->subscriber->onKernelView($event);

    $this->assertFalse($event->hasResponse());
  }

  /**
   * @covers ::onKernelView
   */
  public function testNoRedirectWhenNodeLacksSourceField(): void {
    $this->routeMatch->method('getRouteName')->willReturn('entity.node.canonical');
    $node = $this->mockNode('page', FALSE);
    $this->routeMatch->method('getParameter')->with('node')->willReturn($node);
    $event = $this->buildEvent();

    $this->subscriber->onKernelView($event);

    $this->assertFalse($event->hasResponse());
  }

  /**
   * @covers ::onKernelView
   */
  public function testNoRedirectWhenSourceUriIsEmpty(): void {
    $this->routeMatch->method('getRouteName')->willReturn('entity.node.canonical');
    $node = $this->mockNode('page', TRUE);
    $this->routeMatch->method('getParameter')->with('node')->willReturn($node);

    $freshNode = $this->mockNode('page', TRUE);
    $freshNode->method('get')
      ->with(ExternalSourceRedirectSubscriber::SOURCE_FIELD)
      ->willReturn($this->mockFieldWithUri(NULL));
    $this->nodeStorage->method('load')->with(1)->willReturn($freshNode);

    $event = $this->buildEvent();
    $this->subscriber->onKernelView($event);

    $this->assertFalse($event->hasResponse());
  }

  /**
   * @covers ::onKernelView
   */
  public function testRedirectsToExternalSourceUri(): void {
    $this->routeMatch->method('getRouteName')->willReturn('entity.node.canonical');
    $node = $this->mockNode('page', TRUE);
    $this->routeMatch->method('getParameter')->with('node')->willReturn($node);

    $freshNode = $this->mockNode('page', TRUE);
    $freshNode->method('get')
      ->with(ExternalSourceRedirectSubscriber::SOURCE_FIELD)
      ->willReturn($this->mockFieldWithUri('https://example.com/source'));
    $this->nodeStorage->method('load')->with(1)->willReturn($freshNode);

    $event = $this->buildEvent();
    $this->subscriber->onKernelView($event);

    $this->assertTrue($event->hasResponse());
    $response = $event->getResponse();
    $this->assertSame('https://example.com/source', $response->getTargetUrl());

    // setMaxAge()/setSharedMaxAge() rewrite the Cache-Control header from its
    // parsed directives, reordering and merging with the earlier manual
    // 'no-cache, no-store, must-revalidate, max-age=0' string rather than
    // leaving it untouched.
    $this->assertSame(
      'max-age=0, must-revalidate, no-cache, no-store, private, s-maxage=0',
      $response->headers->get('Cache-Control')
    );
    $this->assertSame('no-cache', $response->headers->get('Pragma'));
    $this->assertSame('*', $response->headers->get('Vary'));
  }

}
