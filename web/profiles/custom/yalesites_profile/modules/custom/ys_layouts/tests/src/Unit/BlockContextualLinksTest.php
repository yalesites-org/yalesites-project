<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Component\Utility\SortArray;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the labels and ordering of a block's Layout Builder contextual links.
 *
 * Two review requirements on issue #190: the actions read "Clone" and "Remove"
 * (not "Clone block" / "Remove block"), and "Remove" stays the last item in the
 * menu now that this module adds a fourth link.
 *
 * Ordering is not testable from the "Clone" definition alone, because core's
 * Configure/Move/Remove links ship no weight and so all sort equal — the
 * outcome depends on the whole set. This test therefore reads both real
 * *.links.contextual.yml files, applies the real alter hook, and sorts with
 * core's own callback, mirroring the three lines of
 * \Drupal\contextual\Element\ContextualLinks::preRenderLinks() that merge the
 * groups and sort them. It asserts the order holds for either group order, so
 * the guarantee does not rest on which group is attached first.
 *
 * @group yalesites
 * @group ys_layouts
 */
class BlockContextualLinksTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The alter hook titles the link with t(), which needs the translation
    // service to render as a string.
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    require_once dirname(__DIR__, 3) . '/ys_layouts.module';
  }

  /**
   * Tests that the menu reads Configure, Move, Clone, Remove — in that order.
   */
  public function testRemoveStaysLastAndLabelsDropTheWordBlock(): void {
    $core_group = $this->definitions($this->root . '/core/modules/layout_builder/layout_builder.links.contextual.yml');
    $clone_group = $this->definitions(dirname(__DIR__, 3) . '/ys_layouts.links.contextual.yml');

    // preRenderLinks() unions the groups in the order they are attached to the
    // element, so assert the result for both possible orders.
    $expected = [
      'layout_builder_block_update' => 'Configure',
      'layout_builder_block_move' => 'Move',
      'layout_builder_block_clone' => 'Clone',
      'layout_builder_block_remove' => 'Remove',
    ];
    foreach ([$core_group + $clone_group, $clone_group + $core_group] as $items) {
      uasort($items, [SortArray::class, 'sortByWeightElement']);

      $this->assertSame($expected, array_map(fn (array $item) => (string) $item['title'], $items));
    }
  }

  /**
   * Reads a *.links.contextual.yml the way the plugin manager does.
   *
   * Titles are left as the raw YAML strings — YamlDiscovery wraps them in a
   * TranslatableMarkup in production, which casts to the same string.
   *
   * @param string $file
   *   Absolute path to the YAML file.
   *
   * @return array
   *   Contextual link definitions, keyed by plugin id, with the manager's
   *   'weight' default applied and the module's alter hook run over them.
   *
   * @see \Drupal\Core\Menu\ContextualLinkManager::$defaults
   * @see \Drupal\Core\Plugin\Discovery\YamlDiscovery::getDefinitions()
   */
  protected function definitions(string $file): array {
    $this->assertFileExists($file);
    $definitions = [];
    foreach (Yaml::parseFile($file) as $plugin_id => $definition) {
      $definitions[$plugin_id] = $definition + ['weight' => NULL];
    }
    ys_layouts_contextual_links_plugins_alter($definitions);

    return $definitions;
  }

}
