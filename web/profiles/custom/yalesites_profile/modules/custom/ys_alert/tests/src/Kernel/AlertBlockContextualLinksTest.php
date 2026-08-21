<?php

namespace Drupal\Tests\ys_alert\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\block\BlockViewBuilder;
use Drupal\block\Entity\Block;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests that a placed alert block renders with no contextual links.
 *
 * The Unit test covers what the alter hook does to a render array. This one
 * covers the part that only real hook discovery can: that the hook is *named*
 * so core actually calls it for this plugin. The name embeds the plugin's base
 * id (`block_view_<base_id>_alter`), so renaming the `alert_block` plugin id
 * would silently stop the hook firing and bring the pencil back. Reading the
 * plugin id out of the shipped block placement instead of hardcoding it here is
 * what makes that regression fail this test.
 *
 * @see \Drupal\block\BlockViewBuilder::buildPreRenderableBlock()
 * @see \Drupal\Tests\ys_alert\Unit\AlertBlockContextualLinksTest
 *
 * @group yalesites
 * @group ys_alert
 */
class AlertBlockContextualLinksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'block',
    'ys_alert',
  ];

  /**
   * {@inheritdoc}
   *
   * The ys_alert.settings config ships without a schema file.
   *
   * @see \Drupal\Tests\ys_alert\Kernel\AlertSettingsFormTest
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['ys_alert']);
    $this->container->get('theme_installer')->install(['stark']);
  }

  /**
   * The sitewide alert placement ends up with an empty contextual-links set.
   */
  public function testPlacedAlertBlockHasNoContextualLinks(): void {
    $build = $this->buildPlacedBlock($this->alertPluginId(), 'alert_block');

    // Empty rather than absent: core's contextual_preprocess() guards on
    // !empty(), so this is what stops the pencil rendering for every role.
    $this->assertArrayHasKey('#contextual_links', $build);
    $this->assertEmpty($build['#contextual_links']);
  }

  /**
   * Other placed blocks keep the "Configure block" link core gives them.
   *
   * Guards against the fix over-reaching — the hook is keyed to one plugin, and
   * this is the control proving it.
   */
  public function testOtherPlacedBlocksKeepTheirContextualLinks(): void {
    $build = $this->buildPlacedBlock('system_powered_by_block', 'powered_by');

    $this->assertArrayHasKey('block', $build['#contextual_links']);
  }

  /**
   * Places a block and returns the render array core hands to the alter hooks.
   *
   * @param string $plugin_id
   *   The block plugin to place.
   * @param string $block_id
   *   The block config entity id to create.
   *
   * @return array
   *   The pre-renderable block render array, post-alter.
   */
  protected function buildPlacedBlock(string $plugin_id, string $block_id): array {
    Block::create([
      'id' => $block_id,
      'plugin' => $plugin_id,
      'theme' => 'stark',
      'region' => 'content',
    ])->save();

    // lazyBuilder() is the path a themed region takes, and it is where core
    // attaches the contextual links and then invokes the alter hooks.
    return BlockViewBuilder::lazyBuilder($block_id, 'full');
  }

  /**
   * Reads the alert block's plugin id from the placement the profile ships.
   *
   * Deliberately not hardcoded — see the class docblock.
   *
   * @return string
   *   The block plugin id used by the sitewide alert placement.
   */
  protected function alertPluginId(): string {
    $placement = dirname(__DIR__, 6) . '/config/sync/block.block.alert_block.yml';
    $this->assertFileExists($placement);

    return Yaml::parseFile($placement)['plugin'];
  }

}
