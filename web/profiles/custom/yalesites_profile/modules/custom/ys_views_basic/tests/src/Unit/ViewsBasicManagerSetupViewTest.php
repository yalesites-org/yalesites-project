<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityDisplayRepository;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\taxonomy\TermStorageInterface;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\ViewExecutable;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Unit tests for ViewsBasicManager::setupView() and getView().
 *
 * Drives setupView() against a mock view and checks the contextual
 * arguments and display options it sets from the stored JSON parameters.
 *
 * SetupView() keeps a function-static re-entry flag. Every path exercised
 * here either returns before setting it or resets it at the end, so the
 * tests do not depend on run order and need no process isolation.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\ViewsBasicManager
 * @group ys_views_basic
 * @group yalesites
 */
class ViewsBasicManagerSetupViewTest extends UnitTestCase {

  /**
   * The manager under test.
   *
   * @var \Drupal\ys_views_basic\ViewsBasicManager
   */
  protected $manager;

  /**
   * Display options passed to setOption(), keyed by option name.
   *
   * @var array
   */
  protected $displayOptions = [];

  /**
   * Arguments passed to the view's setArguments(), or NULL if never called.
   *
   * @var array|null
   */
  protected $viewArgs;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturn($this->createMock(TermStorageInterface::class));

    $this->manager = new ViewsBasicManager(
      $entityTypeManager,
      $this->createMock(EntityDisplayRepository::class),
      $this->createMock(RouteMatchInterface::class),
      $this->createMock(CacheTagsInvalidatorInterface::class),
    );
  }

  /**
   * Builds a mock view that records the options and arguments it receives.
   *
   * @return \Drupal\views\ViewExecutable|\PHPUnit\Framework\MockObject\MockObject
   *   The mock view.
   */
  protected function createView() {
    $this->displayOptions = [];
    $this->viewArgs = NULL;

    $display = $this->createMock(DisplayPluginBase::class);
    $display->method('getOption')->willReturn([]);
    $display->method('setOption')->willReturnCallback(function ($option, $value) {
      $this->displayOptions[$option] = $value;
    });

    $view = $this->createMock(ViewExecutable::class);
    $view->method('getDisplay')->willReturn($display);
    $view->method('setArguments')->willReturnCallback(function (array $args) {
      $this->viewArgs = $args;
    });
    $view->method('preview')->willReturn(['#rows' => []]);

    return $view;
  }

  /**
   * Runs setupView() with the given parameters.
   *
   * @param array $params
   *   The parameters, JSON encoded before being passed in.
   *
   * @return array
   *   [0] => the view variable after the call, [1] => the original mock.
   */
  protected function runSetupView(array $params): array {
    $mock = $this->createView();
    $view = $mock;
    $this->manager->setupView($view, json_encode($params));
    return [$view, $mock];
  }

  /**
   * An unlisted sort and view mode are replaced by the type defaults.
   *
   * @covers ::setupView
   */
  public function testUnknownSortAndViewModeUseTypeDefaults() {
    $this->runSetupView([
      'filters' => ['types' => ['post']],
      'sort_by' => '(1):ASC',
      'view_mode' => 'nope',
    ]);

    $this->assertSame('post', $this->viewArgs['type']);
    $this->assertSame('field_publish_date:DESC', $this->viewArgs['sort']);
    $this->assertSame('card', $this->viewArgs['view']);
  }

  /**
   * A listed sort and view mode are passed through unchanged.
   *
   * @covers ::setupView
   */
  public function testListedSortAndViewModePassThrough() {
    $this->runSetupView([
      'filters' => ['types' => ['page']],
      'sort_by' => 'title:DESC',
      'view_mode' => 'condensed',
    ]);

    $this->assertSame('title:DESC', $this->viewArgs['sort']);
    $this->assertSame('condensed', $this->viewArgs['view']);
  }

  /**
   * Limit and offset are passed as non-negative integers.
   *
   * @covers ::setupView
   */
  public function testLimitAndOffsetAreNonNegativeIntegers() {
    $this->runSetupView([
      'filters' => ['types' => ['post']],
      'limit' => '5',
      'offset' => -3,
    ]);

    $this->assertSame(5, $this->viewArgs['items']);
    $this->assertSame(0, $this->viewArgs['offset']);
  }

  /**
   * An events view takes its sort direction from a listed sort.
   *
   * @covers ::setupView
   */
  public function testEventSortDirectionComesFromListedSort() {
    $this->runSetupView([
      'filters' => ['types' => ['event']],
      'sort_by' => 'field_event_date:ASC',
    ]);

    $this->assertSame('ASC', $this->displayOptions['sorts'][0]['order']);
    $this->assertSame('field_event_date:ASC', $this->viewArgs['sort']);
  }

  /**
   * An events view with an unlisted sort uses the default direction.
   *
   * @covers ::setupView
   */
  public function testEventSortDirectionFallsBackToDefault() {
    $this->runSetupView([
      'filters' => ['types' => ['event']],
      'sort_by' => 'field_event_date:SIDEWAYS',
    ]);

    $this->assertSame('DESC', $this->displayOptions['sorts'][0]['order']);
    $this->assertSame('field_event_date:DESC', $this->viewArgs['sort']);
  }

  /**
   * With no known content type the view is marked failed and not run.
   *
   * @covers ::setupView
   */
  public function testUnknownTypeRendersNothing() {
    $mock = $this->createView();
    $mock->expects($this->never())->method('setArguments');
    $mock->expects($this->never())->method('execute');
    $view = $mock;

    $this->manager->setupView($view, json_encode(['filters' => ['types' => ['bogus']]]));

    $this->assertSame([], $view);
    $this->assertTrue($mock->build_info['fail']);
    $this->assertTrue($mock->executed);

    // A later call with valid parameters still runs normally.
    $this->runSetupView(['filters' => ['types' => ['page']]]);
    $this->assertSame('title:ASC', $this->viewArgs['sort']);
  }

  /**
   * Parameters that are not valid JSON render nothing.
   *
   * @covers ::setupView
   */
  public function testInvalidJsonRendersNothing() {
    $mock = $this->createView();
    $mock->expects($this->never())->method('execute');
    $view = $mock;

    $this->manager->setupView($view, 'not json');

    $this->assertSame([], $view);
  }

  /**
   * Provides stored parameters with no usable content type.
   *
   * @return array
   *   Test cases keyed by description.
   */
  public static function noKnownTypeProvider(): array {
    return [
      'invalid json' => ['not json'],
      'missing filters' => ['{}'],
      'types not an array' => ['{"filters":{"types":"post"}}'],
      'unknown type' => ['{"filters":{"types":["bogus"]}}'],
      'empty string' => [''],
    ];
  }

  /**
   * GetView() returns an empty render array when no type is known.
   *
   * Valid parameters are not covered here: initView() loads the view through
   * the Views static service, which needs a container and database.
   *
   * @dataProvider noKnownTypeProvider
   * @covers ::getView
   */
  public function testGetViewWithNoKnownTypeReturnsEmptyArray($params) {
    $this->assertSame([], $this->manager->getView('rendered', $params));
    // The re-entry flag was not left set: a second call still returns [].
    $this->assertSame([], $this->manager->getView('rendered', $params));
  }

}
