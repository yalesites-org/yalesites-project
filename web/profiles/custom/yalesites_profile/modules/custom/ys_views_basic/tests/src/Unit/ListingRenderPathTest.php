<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\views\Plugin\views\row\EntityRow;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;
use Drupal\ys_views_basic\ListingRowDisplay;
use Drupal\ys_views_basic\Plugin\views\style\ViewsBasicDynamicStyle;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests how a listing is configured and rendered on the views/ajax path.
 *
 * On that path hook_views_pre_view() only configures the view; the request
 * that fired it runs and renders it once. Arguments from the browser that
 * cannot configure a listing stop the view instead of reaching its plugins.
 * The style plugin hands each row its listing's display flags.
 *
 * @group yalesites
 */
class ListingRenderPathTest extends UnitTestCase {

  /**
   * The mocked views basic manager service.
   *
   * @var \Drupal\ys_views_basic\ViewsBasicManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/../../../ys_views_basic.module';
    $this->manager = $this->createMock(ViewsBasicManager::class);
    $container = new ContainerBuilder();
    $container->set('ys_views_basic.views_basic_manager', $this->manager);
    \Drupal::setContainer($container);
  }

  /**
   * Returns a scaffold view mock that must never be run or rendered here.
   */
  private function scaffoldView(): ViewExecutable {
    $view = $this->createMock(ViewExecutable::class);
    $view->method('id')->willReturn('views_basic_scaffold');
    $view->expects($this->never())->method('execute');
    $view->expects($this->never())->method('preview');
    $view->expects($this->never())->method('render');
    return $view;
  }

  /**
   * Returns a full scaffold argument list with the given arguments by name.
   */
  private function args(array $named): array {
    $args = array_fill(0, count(ViewsBasicManager::VIEW_ARGUMENT_ORDER), '');
    foreach ($named as $name => $value) {
      $args[ViewsBasicManager::viewArgumentIndex($name)] = $value;
    }
    return $args;
  }

  /**
   * The AJAX re-render configures the view once, without running it.
   *
   * @covers \ys_views_basic_views_pre_view
   */
  public function testPreViewConfiguresWithoutExecuting(): void {
    $params = json_encode(['filters' => ['types' => ['post']], 'display' => 'pager']);
    $uuid = '6f1d0c1e-9a3b-4c2d-8e7f-0a1b2c3d4e5f';
    $args = $this->args([
      'original_settings' => $params,
      'field_display_options' => json_encode([
        'block_has_heading' => 1,
        'block_uuid' => $uuid,
        'host_nid' => 12,
        'listing_key' => 'abcd1234',
      ]),
    ]);
    $view = $this->scaffoldView();
    $this->manager->expects($this->once())
      ->method('configureView')
      ->with($view, $params, $uuid, TRUE, 12, 'abcd1234')
      ->willReturn(['field' => []]);

    ys_views_basic_views_pre_view($view, 'block_1', $args);

    $this->assertEmpty($view->build_info['fail'] ?? NULL);
    $this->assertEmpty($view->executed);
  }

  /**
   * A view the block render already ran is left alone.
   *
   * @covers \ys_views_basic_views_pre_view
   */
  public function testPreViewSkipsAnExecutedView(): void {
    $view = $this->scaffoldView();
    $view->executed = TRUE;
    $this->manager->expects($this->never())->method('configureView');
    $args = $this->args(['original_settings' => json_encode(['filters' => ['types' => ['post']]])]);

    ys_views_basic_views_pre_view($view, 'block_1', $args);

    $this->assertEmpty($view->build_info['fail'] ?? NULL);
  }

  /**
   * Arguments that cannot configure a listing stop the view.
   *
   * @covers \ys_views_basic_views_pre_view
   *
   * @dataProvider badArgumentsProvider
   */
  public function testPreViewFailsTheViewOnBadArguments(array $args): void {
    $view = $this->scaffoldView();
    $this->manager->expects($this->never())->method('configureView');

    ys_views_basic_views_pre_view($view, 'block_1', $args);

    $this->assertTrue($view->build_info['fail']);
    $this->assertTrue($view->built);
    $this->assertTrue($view->executed);
    $this->assertSame([], $view->result);
  }

  /**
   * Data provider for ::testPreViewFailsTheViewOnBadArguments().
   */
  public static function badArgumentsProvider(): array {
    $at = function (string $value): array {
      $args = array_fill(0, count(ViewsBasicManager::VIEW_ARGUMENT_ORDER), '');
      $args[ViewsBasicManager::viewArgumentIndex('original_settings')] = $value;
      return $args;
    };
    return [
      'two arguments' => [['a', 'b']],
      'no arguments' => [[]],
      // The old fallback would have taken the last argument instead.
      'short list ending in params' => [['post', json_encode(['filters' => ['types' => ['post']]])]],
      'params without types' => [$at(json_encode(['filters' => []]))],
      'invalid JSON' => [$at('{x')],
    ];
  }

  /**
   * The configure step refusing the params also stops the view.
   *
   * @covers \ys_views_basic_views_pre_view
   */
  public function testPreViewFailsTheViewWhenConfigureRefuses(): void {
    $view = $this->scaffoldView();
    $this->manager->method('configureView')->willReturn(NULL);

    $args = $this->args(['original_settings' => json_encode(['filters' => ['types' => ['post']]])]);
    ys_views_basic_views_pre_view($view, 'block_1', $args);

    $this->assertTrue($view->build_info['fail']);
  }

  /**
   * Views other than the scaffolds are not touched.
   *
   * @covers \ys_views_basic_views_pre_view
   */
  public function testPreViewIgnoresOtherViews(): void {
    $view = $this->createMock(ViewExecutable::class);
    $view->method('id')->willReturn('content');
    $this->manager->expects($this->never())->method('configureView');
    $args = ['a'];

    ys_views_basic_views_pre_view($view, 'default', $args);

    $this->assertEmpty($view->build_info['fail'] ?? NULL);
  }

  /**
   * The style plugin attaches the listing's flags to each row it renders.
   *
   * @covers \Drupal\ys_views_basic\Plugin\views\style\ViewsBasicDynamicStyle::render
   */
  public function testStyleRenderAttachesRowFlags(): void {
    $sticky = $this->createMock(NodeInterface::class);
    $sticky->method('isSticky')->willReturn(TRUE);
    $plain = $this->createMock(NodeInterface::class);
    $plain->method('isSticky')->willReturn(FALSE);

    $view = $this->createMock(ViewExecutable::class);
    $view->method('id')->willReturn('views_basic_scaffold');
    $view->args = $this->args([
      'type' => 'post',
      'field_display_options' => json_encode(['show_tags' => 1, 'block_has_heading' => 0]),
      'pin_settings' => json_encode(['pinned_to_top' => TRUE, 'pin_label' => 'Pinned']),
    ]);
    $view->result = [
      new ResultRow(['_entity' => $sticky]),
      new ResultRow(['_entity' => $plain]),
    ];
    $row_plugin = $this->createMock(EntityRow::class);
    $row_plugin->options = ['view_mode' => 'card'];
    $row_plugin->method('render')->willReturnCallback(fn(ResultRow $row) => [
      '#node' => $row->_entity,
      '#pre_render' => ['build'],
    ]);
    $view->rowPlugin = $row_plugin;

    $plugin = (new \ReflectionClass(ViewsBasicDynamicStyle::class))->newInstanceWithoutConstructor();
    $plugin->view = $view;
    $route_match = new \ReflectionProperty(ViewsBasicDynamicStyle::class, 'routeMatch');
    $route_match->setAccessible(TRUE);
    $route_match->setValue($plugin, $this->createMock(RouteMatchInterface::class));

    $build = $plugin->render();

    [$first, $second] = $build['#rows'];
    $this->assertSame(1, $first[ListingRowDisplay::FLAGS_KEY]['show_tags']);
    $this->assertSame('2', $first[ListingRowDisplay::FLAGS_KEY]['card_heading_level']);
    $this->assertSame('Pinned', $first[ListingRowDisplay::FLAGS_KEY]['pin_label']);
    $this->assertNull($second[ListingRowDisplay::FLAGS_KEY]['pin_label'], 'A row that is not pinned shows no pin label.');
    $this->assertSame([ListingRowDisplay::class, 'preRender'], $first['#pre_render'][0]);
    $this->assertSame([[ListingRowDisplay::class, 'postRender']], $second['#post_render']);
  }

}
