<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Layout\LayoutDefinition;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_layouts\Plugin\Layout\YSLayoutBanner;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests the banner layout build logic.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Plugin\Layout\YSLayoutBanner
 *
 * @group yalesites
 */
class YSLayoutBannerTest extends UnitTestCase {

  /**
   * Builds a banner layout plugin with a single content region.
   */
  protected function buildLayout(): YSLayoutBanner {
    $definition = new LayoutDefinition([
      'regions' => ['content' => ['label' => 'Content']],
    ]);
    return new YSLayoutBanner([], 'ys_layout_banner', $definition);
  }

  /**
   * A single block without a #plugin_id must not raise a warning.
   *
   * Regression test for the search_api indexing crash where build() called
   * str_contains() on an undefined #plugin_id key.
   *
   * @covers ::build
   */
  public function testBuildWithBlockMissingPluginId(): void {
    $layout = $this->buildLayout();

    $build = $layout->build(['content' => [['#markup' => 'No plugin id here']]]);

    $this->assertTrue($build['#show_region_content']);
  }

  /**
   * A non-moderation block leaves the region visible.
   *
   * @covers ::build
   */
  public function testBuildWithNonModerationBlock(): void {
    $layout = $this->buildLayout();

    $build = $layout->build([
      'content' => [['#plugin_id' => 'inline_block:text']],
    ]);

    $this->assertTrue($build['#show_region_content']);
  }

  /**
   * Empty content returns early with the region shown.
   *
   * @covers ::build
   */
  public function testBuildWithNoContent(): void {
    $layout = $this->buildLayout();

    $build = $layout->build([]);

    $this->assertTrue($build['#show_region_content']);
  }

  /**
   * The moderation control on a published node hides the region.
   *
   * Confirms the guard does not alter the existing hide behavior.
   *
   * @covers ::build
   */
  public function testBuildHidesRegionForPublishedModerationControl(): void {
    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->method('hasField')->willReturn(TRUE);
    $entity->method('get')->willReturn((object) ['value' => 'published']);

    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getParameter')->willReturnMap([
      ['node', $entity],
      ['entity', NULL],
    ]);

    $container = new ContainerBuilder();
    $container->set('current_route_match', $route_match);
    \Drupal::setContainer($container);

    $layout = $this->buildLayout();

    $build = $layout->build([
      'content' => [
        [
          '#plugin_id' => 'inline_block:moderation_control',
          '#in_preview' => FALSE,
        ],
      ],
    ]);

    $this->assertFalse($build['#show_region_content']);
  }

}
