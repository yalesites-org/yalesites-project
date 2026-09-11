<?php

namespace Drupal\Tests\ys_alert\Unit;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests that the sitewide alert block carries no contextual "configure" link.
 *
 * Regression coverage for the bug where hovering the sitewide alert on the
 * front end revealed Drupal's contextual edit pencil to anyone who can
 * administer blocks. The pencil pointed at the block instance's configure form
 * — which only exposes the block label and visibility conditions, not the alert
 * content — so it was at best confusing and at worst an invitation to unplace
 * the alert. Alerts are managed only through Alert Settings at
 * /admin/yalesites/alert.
 *
 * Core attaches the group unconditionally to every placed block, then invokes
 * this alter hook on the same render array.
 *
 * Scope: this covers what the hook does to a render array. That core actually
 * calls it for this plugin — the hook name embeds the plugin's base id — is
 * covered by the Kernel test, which goes through real hook discovery.
 *
 * @see \Drupal\block\BlockViewBuilder::buildPreRenderableBlock()
 * @see \Drupal\Tests\ys_alert\Kernel\AlertBlockContextualLinksTest
 *
 * @group yalesites
 * @group ys_alert
 */
class AlertBlockContextualLinksTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once dirname(__DIR__, 3) . '/ys_alert.module';
  }

  /**
   * The block group core attaches is removed, so no pencil is ever rendered.
   */
  public function testConfigureLinkIsRemoved(): void {
    $build = $this->build();

    ys_alert_block_view_alert_block_alter($build, $this->blockPlugin());

    $this->assertArrayNotHasKey('block', $build['#contextual_links']);
    // Core only renders the contextual placeholder for a non-empty
    // #contextual_links, so emptying it suppresses the pencil for every role
    // rather than relying on the configure form's own permission check.
    // @see contextual_preprocess()
    $this->assertEmpty($build['#contextual_links']);
  }

  /**
   * Only the block group is targeted; anything else on the alert survives.
   */
  public function testUnrelatedGroupsAreUntouched(): void {
    $build = $this->build();
    $build['#contextual_links']['some_other_group'] = ['route_parameters' => []];

    ys_alert_block_view_alert_block_alter($build, $this->blockPlugin());

    $this->assertSame(
      ['some_other_group' => ['route_parameters' => []]],
      $build['#contextual_links']
    );
  }

  /**
   * The hook must not fail on a build that has no contextual links at all.
   */
  public function testIsNoOpWhenGroupAbsent(): void {
    $build = $this->build();
    unset($build['#contextual_links']);
    $expected = $build;

    ys_alert_block_view_alert_block_alter($build, $this->blockPlugin());

    $this->assertSame($expected, $build);
  }

  /**
   * Builds the render array core hands to the alter hook.
   *
   * Mirrors the relevant keys of BlockViewBuilder::buildPreRenderableBlock().
   *
   * @return array
   *   A placed-block render array with core's "block" contextual-links group.
   */
  protected function build(): array {
    return [
      '#theme' => 'block',
      '#contextual_links' => [
        'block' => [
          'route_parameters' => ['block' => 'alert_block'],
        ],
      ],
      '#plugin_id' => 'alert_block',
      '#id' => 'alert_block',
    ];
  }

  /**
   * The block plugin core passes as the hook's second argument.
   *
   * @return \Drupal\Core\Block\BlockPluginInterface
   *   A block plugin double.
   */
  protected function blockPlugin(): BlockPluginInterface {
    return $this->createMock(BlockPluginInterface::class);
  }

}
