<?php

namespace Drupal\Tests\ys_core\Unit\EventSubscriber;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\EventSubscriber\AlterModerationSidebarController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests AlterModerationSidebarController's button-label overrides.
 *
 * @coversDefaultClass \Drupal\ys_core\EventSubscriber\AlterModerationSidebarController
 *
 * @group ys_core
 * @group yalesites
 */
class AlterModerationSidebarControllerTest extends UnitTestCase {

  /**
   * The subscriber under test.
   *
   * @var \Drupal\ys_core\EventSubscriber\AlterModerationSidebarController
   */
  protected $subscriber;

  /**
   * The kernel mock used to build real ViewEvent instances.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $kernel;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->subscriber = new AlterModerationSidebarController();
    $this->subscriber->setStringTranslation($this->getStringTranslationStub());
    $this->kernel = $this->createMock(HttpKernelInterface::class);
  }

  /**
   * Builds a real ViewEvent for the given route and controller result.
   */
  protected function buildEvent(string $route, $controllerResult): ViewEvent {
    $request = Request::create('/some-path');
    $request->attributes->set('_route', $route);
    return new ViewEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $controllerResult);
  }

  /**
   * @covers ::onView
   *
   * @dataProvider sidebarRouteProvider
   */
  public function testOnViewRelabelsButtonsOnSidebarRoutes(string $route): void {
    $build = [
      'actions' => [
        'primary' => [
          'edit' => ['#title' => 'Edit'],
        ],
        'secondary' => [
          'layout_builder_ui:layout_builder.overrides.node.view' => ['#title' => 'Layout'],
        ],
      ],
    ];
    $event = $this->buildEvent($route, $build);

    $this->subscriber->onView($event);

    $result = $event->getControllerResult();
    $this->assertSame('Manage Settings', (string) $result['actions']['primary']['edit']['#title']);
    $this->assertSame(
      'Edit Layout And Content',
      (string) $result['actions']['secondary']['layout_builder_ui:layout_builder.overrides.node.view']['#title']
    );
  }

  /**
   * Provides the two moderation sidebar route names that get relabeled.
   *
   * @return array
   *   Each case: [route name].
   */
  public static function sidebarRouteProvider(): array {
    return [
      'sidebar route' => ['moderation_sidebar.sidebar'],
      'sidebar latest route' => ['moderation_sidebar.sidebar_latest'],
    ];
  }

  /**
   * @covers ::onView
   */
  public function testOnViewLeavesOtherRoutesUnchanged(): void {
    $build = ['actions' => ['primary' => ['edit' => ['#title' => 'Edit']]]];
    $event = $this->buildEvent('some.other_route', $build);

    $this->subscriber->onView($event);

    $this->assertSame($build, $event->getControllerResult());
  }

  /**
   * @covers ::onView
   */
  public function testOnViewIgnoresNonArrayControllerResult(): void {
    $response = new \stdClass();
    $event = $this->buildEvent('moderation_sidebar.sidebar', $response);

    $this->subscriber->onView($event);

    $this->assertSame($response, $event->getControllerResult());
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents(): void {
    $events = AlterModerationSidebarController::getSubscribedEvents();
    $this->assertSame([['onView', 50]], $events[KernelEvents::VIEW]);
  }

}
