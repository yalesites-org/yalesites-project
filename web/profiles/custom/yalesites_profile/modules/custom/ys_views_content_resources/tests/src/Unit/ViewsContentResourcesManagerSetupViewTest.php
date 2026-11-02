<?php

namespace Drupal\Tests\ys_views_content_resources\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityDisplayRepository;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\taxonomy\TermStorageInterface;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\ViewExecutable;
use Drupal\ys_views_content_resources\ViewsContentResourcesManager;

/**
 * Unit tests for ViewsContentResourcesManager::setupView().
 *
 * Drives setupView() against a mock view and checks the contextual
 * arguments it sets from the stored JSON parameters. On AJAX requests those
 * parameters come from the request, so anything not listed must fall back
 * to a safe default instead of reaching the view or raising an error.
 *
 * SetupView() keeps a function-static re-entry flag that is reset at the
 * end of every completed call, so the tests do not depend on run order.
 *
 * @coversDefaultClass \Drupal\ys_views_content_resources\ViewsContentResourcesManager
 * @group ys_views_content_resources
 * @group yalesites
 */
class ViewsContentResourcesManagerSetupViewTest extends UnitTestCase {

  /**
   * The manager under test.
   *
   * @var \Drupal\ys_views_content_resources\ViewsContentResourcesManager
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

    $this->manager = new ViewsContentResourcesManager(
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
   * Runs setupView() with the given raw parameter string.
   *
   * @param string $params
   *   The parameters as stored or sent by the client.
   */
  protected function runSetupView(string $params): void {
    $view = $this->createView();
    $this->manager->setupView($view, $params);
  }

  /**
   * Provides parameters that are not usable as stored settings.
   *
   * @return array
   *   Test cases keyed by description.
   */
  public static function unusableParamsProvider(): array {
    return [
      'invalid json' => ['not json'],
      'json scalar' => ['"resource"'],
      'missing filters' => ['{}'],
      'types not an array' => ['{"filters":{"types":"resource"}}'],
      'filters not an array' => ['{"filters":"resource"}'],
      'types holds a nested array' => ['{"filters":{"types":[["resource"]]}}'],
      'terms not an array' => ['{"filters":{"types":["resource"],"terms_include":"3"}}'],
    ];
  }

  /**
   * Unusable parameters fall back to the default resource listing.
   *
   * @dataProvider unusableParamsProvider
   * @covers ::setupView
   */
  public function testUnusableParamsUseDefaults(string $params) {
    $this->runSetupView($params);

    $this->assertNotNull($this->viewArgs, 'setupView() set view arguments.');
    $this->assertSame('resource', $this->viewArgs['type']);
    $this->assertSame('field_publish_date:DESC', $this->viewArgs['sort']);
    $this->assertSame('card', $this->viewArgs['view']);
    $this->assertNull($this->viewArgs['items']);
    $this->assertSame(0, $this->viewArgs['offset']);
    $this->assertSame('all', $this->viewArgs['terms_include']);
  }

  /**
   * An unlisted sort and view mode are replaced by the defaults.
   *
   * @covers ::setupView
   */
  public function testUnknownSortAndViewModeUseDefaults() {
    $this->runSetupView(json_encode([
      'filters' => ['types' => ['resource']],
      'sort_by' => '(1):ASC',
      'view_mode' => 'nope',
    ]));

    $this->assertSame('field_publish_date:DESC', $this->viewArgs['sort']);
    $this->assertSame('card', $this->viewArgs['view']);
  }

  /**
   * Non-string sort and view mode values are replaced by the defaults.
   *
   * @covers ::setupView
   */
  public function testNonStringSortAndViewModeUseDefaults() {
    $this->runSetupView(json_encode([
      'filters' => ['types' => ['resource']],
      'sort_by' => ['field_publish_date:ASC'],
      'view_mode' => ['list_item'],
    ]));

    $this->assertSame('field_publish_date:DESC', $this->viewArgs['sort']);
    $this->assertSame('card', $this->viewArgs['view']);
  }

  /**
   * Limit and offset are passed as non-negative integers.
   *
   * @covers ::setupView
   */
  public function testLimitAndOffsetAreNonNegativeIntegers() {
    $this->runSetupView(json_encode([
      'filters' => ['types' => ['resource']],
      'limit' => '5; DROP',
      'offset' => -3,
    ]));

    $this->assertSame(5, $this->viewArgs['items']);
    $this->assertSame(0, $this->viewArgs['offset']);

    $this->runSetupView(json_encode([
      'filters' => ['types' => ['resource']],
      'limit' => -10,
      'offset' => 'abc',
    ]));

    $this->assertSame(0, $this->viewArgs['items']);
    $this->assertSame(0, $this->viewArgs['offset']);
  }

  /**
   * Provides tag operators that are not "+" or ",".
   *
   * @return array
   *   Test cases keyed by description.
   */
  public static function unknownOperatorProvider(): array {
    return [
      'sql-ish string' => [' OR 1=1 '],
      'empty string' => [''],
      'array' => [['+']],
      'number' => [1],
    ];
  }

  /**
   * An unknown tag operator falls back to "+".
   *
   * @dataProvider unknownOperatorProvider
   * @covers ::setupView
   */
  public function testUnknownOperatorFallsBackToPlus($operator) {
    $this->runSetupView(json_encode([
      'filters' => [
        'types' => ['resource'],
        'terms_include' => [3, 4],
        'terms_exclude' => [7, 8],
      ],
      'operator' => $operator,
    ]));

    $this->assertSame('3+4', $this->viewArgs['terms_include']);
    $this->assertSame('7+8', $this->viewArgs['terms_exclude']);
  }

  /**
   * Provides stored term lists and the expected terms argument.
   *
   * @return array
   *   Test cases keyed by description: [terms, expected argument].
   */
  public static function termsProvider(): array {
    return [
      'non-numeric string dropped' => [['x', 5], '5'],
      'nested list dropped' => [[['y'], 5], '5'],
      'array without target_id dropped' => [[['foo' => 1], 5], '5'],
      'null dropped' => [[NULL, 5], '5'],
      'non-numeric target_id dropped' => [[['target_id' => 'z'], 5], '5'],
      'nothing usable left' => [['x', NULL], NULL],
      'string ids keyed by id' => [['92' => '92', '93' => '93'], '92+93'],
      'legacy target_id shape' => [[['target_id' => 4], ['target_id' => '6']], '4+6'],
    ];
  }

  /**
   * Crafted term values are dropped; valid stored shapes are kept.
   *
   * @dataProvider termsProvider
   * @covers ::setupView
   */
  public function testTermValues(array $terms, ?string $expected) {
    $this->runSetupView(json_encode([
      'filters' => [
        'types' => ['resource'],
        'terms_include' => $terms,
        'terms_exclude' => $terms,
      ],
    ]));

    $this->assertSame($expected ?? 'all', $this->viewArgs['terms_include']);
    $this->assertSame($expected, $this->viewArgs['terms_exclude']);
  }

  /**
   * Valid stored settings reach the view unchanged.
   *
   * @covers ::setupView
   */
  public function testValidSettingsPassThroughUnchanged() {
    $settings = [
      'filters' => [
        'types' => ['resource'],
        'terms_include' => [3, 4],
        'terms_exclude' => [7],
      ],
      'operator' => ',',
      'sort_by' => 'field_publish_date:ASC',
      'view_mode' => 'list_item',
      'display' => 'limit',
      'limit' => 12,
      'offset' => 2,
      'field_options' => ['show_category' => 1],
    ];
    $params = json_encode($settings);

    $this->runSetupView($params);

    $this->assertSame([
      'type' => 'resource',
      'terms_include' => '3,4',
      'terms_exclude' => '7',
      'sort' => 'field_publish_date:ASC',
      'view' => 'list_item',
      'items' => 12,
      'offset' => 2,
    ], array_intersect_key($this->viewArgs, array_flip([
      'type',
      'terms_include',
      'terms_exclude',
      'sort',
      'view',
      'items',
      'offset',
    ])));
    $this->assertSame($params, $this->viewArgs['original_settings']);

    // Every listed sort and view mode passes through as given.
    foreach (array_keys($this->manager->sortByList()) as $sort) {
      $settings['sort_by'] = $sort;
      $this->runSetupView(json_encode($settings));
      $this->assertSame($sort, $this->viewArgs['sort']);
    }
    foreach (array_keys(ViewsContentResourcesManager::ALLOWED_VIEW_MODES) as $viewMode) {
      $settings['view_mode'] = $viewMode;
      $this->runSetupView(json_encode($settings));
      $this->assertSame($viewMode, $this->viewArgs['view']);
    }
  }

  /**
   * Display "all" shows every item.
   *
   * @covers ::setupView
   */
  public function testDisplayAllSetsNoLimit() {
    $this->runSetupView(json_encode([
      'filters' => ['types' => ['resource']],
      'display' => 'all',
      'limit' => 12,
    ]));

    $this->assertSame(0, $this->viewArgs['items']);
  }

}
