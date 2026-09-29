<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\ScrollTopCommand;
use Drupal\Tests\UnitTestCase;
use Drupal\views\Ajax\ViewAjaxResponse;
use Drupal\views\ViewExecutable;
use Drupal\ys_views_basic\EventSubscriber\PagerScrollSubscriber;
use Drupal\ys_views_basic\ViewsBasicManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests that paging a views basic listing leaves the reader where they were.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\EventSubscriber\PagerScrollSubscriber
 *
 * @group ys_views_basic
 */
class PagerScrollSubscriberTest extends UnitTestCase {

  /**
   * The subscriber under test.
   *
   * @var \Drupal\ys_views_basic\EventSubscriber\PagerScrollSubscriber
   */
  protected PagerScrollSubscriber $subscriber;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->subscriber = new PagerScrollSubscriber();
  }

  /**
   * Builds a view AJAX response carrying the commands core would add.
   *
   * @param string $viewId
   *   The view the response belongs to.
   *
   * @return \Drupal\views\Ajax\ViewAjaxResponse
   *   The response, with a scrollTop command and the two that follow it.
   */
  protected function viewResponse(string $viewId): ViewAjaxResponse {
    $view = $this->createMock(ViewExecutable::class);
    $view->method('id')->willReturn($viewId);

    $response = new ViewAjaxResponse();
    $response->setView($view);
    // The order core adds them in, and the order matters to these tests:
    // ViewAjaxController::ajaxView() adds ScrollTopCommand first (:195), then
    // ReplaceCommand (:213) and PrependCommand (:214). Because scrollTop is
    // index 0, dropping it leaves a gap that array_filter alone would not
    // close -- which is the whole reason the subscriber re-indexes. Building
    // the fixture in any other order makes the re-index test vacuous.
    $response->addCommand(new ScrollTopCommand('.js-view-dom-id-abc'));
    $response->addCommand(new ReplaceCommand('.js-view-dom-id-abc', 'markup'));
    $response->addCommand(new PrependCommand('.js-view-dom-id-abc', 'messages'));

    return $response;
  }

  /**
   * Dispatches a response through the subscriber.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response to pass through.
   */
  protected function dispatch(Response $response): void {
    $this->subscriber->onResponse(new ResponseEvent(
      $this->createMock(HttpKernelInterface::class),
      new Request(),
      HttpKernelInterface::MAIN_REQUEST,
      $response
    ));
  }

  /**
   * Returns the command names on a response, in order.
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The response to read.
   *
   * @return string[]
   *   The command names.
   */
  protected function commandNames($response): array {
    return array_column($response->getCommands(), 'command');
  }

  /**
   * The scroll command is dropped for every scaffold view.
   *
   * @covers ::onResponse
   */
  public function testScrollCommandIsDroppedForScaffoldViews(): void {
    foreach (ViewsBasicManager::SCAFFOLD_VIEWS as $viewId) {
      $response = $this->viewResponse($viewId);
      $this->dispatch($response);

      $this->assertSame(
        ['insert', 'insert'],
        $this->commandNames($response),
        "scrollTop should be gone from $viewId, and nothing else touched"
      );
    }
  }

  /**
   * The remaining commands keep sequential keys after the filter.
   *
   * @covers ::onResponse
   */
  public function testRemainingCommandsAreReindexed(): void {
    // AjaxResponse's commands are serialized to JSON. array_filter preserves
    // keys, so without a re-index a gap turns the list into a JSON object and
    // the client stops iterating it as an array.
    $response = $this->viewResponse('views_basic_scaffold');
    $this->dispatch($response);

    $this->assertSame([0, 1], array_keys($response->getCommands()));
  }

  /**
   * Other views keep core's scroll behaviour.
   *
   * @covers ::onResponse
   */
  public function testScrollCommandSurvivesOnOtherViews(): void {
    $response = $this->viewResponse('frontpage');
    $this->dispatch($response);

    $this->assertSame(
      ['scrollTop', 'insert', 'insert'],
      $this->commandNames($response)
    );
  }

  /**
   * A response that is not a view response is left alone.
   *
   * @covers ::onResponse
   */
  public function testNonViewResponseIsUntouched(): void {
    $response = new Response('hello');
    $this->dispatch($response);

    $this->assertSame('hello', $response->getContent());
  }

  /**
   * The subscriber runs before the commands are rendered out.
   *
   * @covers ::getSubscribedEvents
   */
  public function testRunsBeforeAjaxResponseSubscriber(): void {
    // AjaxResponseSubscriber::onResponse() renders the command list at -100.
    // Filtering after that point would change nothing.
    $events = PagerScrollSubscriber::getSubscribedEvents();

    $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
    foreach ($events[KernelEvents::RESPONSE] as $listener) {
      $this->assertGreaterThan(-100, $listener[1] ?? 0);
    }
  }

}
