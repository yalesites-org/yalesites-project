<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests that every themed Section layout declares its settings schema.
 *
 * Without a `layout_plugin.settings.<layout_id>` entry, a layout's settings
 * fall back to layout_discovery's generic `layout_plugin.settings` mapping,
 * which declares only `label` and `context_mapping`. Saving a config entity
 * whose section uses that layout then throws SchemaIncompleteException under
 * strict schema checking -- which every kernel and browser test runs with, and
 * which some config-inspection tooling applies too.
 *
 * That gap is not hypothetical here: this repo's own exported config already
 * carries the section-padding key on a `layout_onecol` section
 * (core.entity_view_display.node.resource.default.yml), and
 * ys_layouts_form_layout_builder_configure_section_alter() adds it to every
 * layout with no per-layout filter.
 *
 * @group yalesites
 * @group ys_layouts
 */
class YsLayoutsSectionSchemaTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'quick_node_clone',
    'formdazzle',
    'ys_themes',
    'ys_layouts',
  ];

  /**
   * Section layouts that YSLayoutOptions backs, so carry theme and divider.
   */
  const THEMED_LAYOUT_IDS = [
    'layout_onecol',
    'ys_layout_two_column',
    'ys_layout_two_column_50_50',
    'ys_layout_three_column_33_33_33',
  ];

  /**
   * Schema type name to the PHP type a matching default must have.
   */
  const SCHEMA_TYPE_ASSERTIONS = [
    'integer' => 'integer',
    'string' => 'string',
    'label' => 'string',
    'text' => 'string',
    'boolean' => 'boolean',
  ];

  /**
   * Every themed layout's settings schema declares theme, divider, padding.
   */
  public function testThemedLayoutsDeclareTheirSettingsSchema(): void {
    $typed_config = $this->container->get('config.typed');

    foreach (self::THEMED_LAYOUT_IDS as $layout_id) {
      $definition = $typed_config->getDefinition('layout_plugin.settings.' . $layout_id);
      $mapping = $definition['mapping'] ?? [];

      // A layout with no entry of its own resolves to the generic
      // layout_plugin.settings mapping, which has none of these keys.
      $this->assertArrayHasKey('theme', $mapping, "{$layout_id} does not declare 'theme' in its settings schema.");
      $this->assertArrayHasKey('divider', $mapping, "{$layout_id} does not declare 'divider' in its settings schema.");
      $this->assertArrayHasKey(
        'ys_layouts_sections_config',
        $mapping,
        "{$layout_id} does not declare the section-padding key that ys_layouts adds to every layout."
      );
    }
  }

  /**
   * A declared type matches the PHP type of the plugin's own default.
   *
   * Declaring the key is only half of it. A section can be written straight
   * from a plugin's defaultConfiguration() without ever passing through the
   * configure-section form -- a config entity's default section does exactly
   * that, and core.entity_view_display.node.profile.default.yml ships a
   * ys_layout_two_column section. If the declared type and the default's PHP
   * type disagree, strict schema checking still fails; it just reports the
   * type instead of the missing mapping, which is a lateral move rather than
   * a fix.
   */
  public function testDeclaredTypesMatchThePluginDefaults(): void {
    $typed_config = $this->container->get('config.typed');
    $layout_manager = $this->container->get('plugin.manager.core.layout');
    $asserted = 0;

    foreach (self::THEMED_LAYOUT_IDS as $layout_id) {
      $mapping = $typed_config->getDefinition('layout_plugin.settings.' . $layout_id)['mapping'] ?? [];
      $defaults = $layout_manager->createInstance($layout_id)->getConfiguration();

      foreach ($mapping as $key => $spec) {
        $expected_php_type = self::SCHEMA_TYPE_ASSERTIONS[$spec['type'] ?? ''] ?? NULL;
        // Only scalar keys the plugin actually defaults are checkable here.
        if ($expected_php_type === NULL || !array_key_exists($key, $defaults) || $defaults[$key] === NULL) {
          continue;
        }

        $this->assertSame(
          $expected_php_type,
          gettype($defaults[$key]),
          sprintf(
            "%s: schema declares '%s' as %s, but the plugin defaults it to %s.",
            $layout_id,
            $key,
            $spec['type'],
            gettype($defaults[$key])
          )
        );
        $asserted++;
      }
    }

    // Guards against the loop silently skipping everything and passing.
    $this->assertGreaterThan(0, $asserted, 'No schema-declared defaults were checked.');
  }

  /**
   * Every layout id the schema file maps is a real layout plugin.
   *
   * Read out of the YAML rather than from a list in this file, so an entry
   * added or misspelled there is caught even though this test was not touched.
   * A mapping for an id that does not exist silently protects nothing.
   */
  public function testSchemaMappedLayoutIdsAreRealPlugins(): void {
    $layout_manager = $this->container->get('plugin.manager.core.layout');
    $path = $this->container->get('extension.list.module')->getPath('ys_layouts');
    $schema = Yaml::decode(file_get_contents($path . '/config/schema/ys_layouts.schema.yml'));

    $mapped_ids = [];
    foreach (array_keys($schema) as $key) {
      if (str_starts_with($key, 'layout_plugin.settings.')) {
        $mapped_ids[] = substr($key, strlen('layout_plugin.settings.'));
      }
    }

    $this->assertNotEmpty($mapped_ids, 'The schema file maps no layout ids at all.');

    foreach ($mapped_ids as $layout_id) {
      $this->assertTrue(
        $layout_manager->hasDefinition($layout_id),
        "The schema maps '{$layout_id}', which is not a defined layout plugin."
      );
    }

    // Every layout that gets the theme picker must be mapped, so extending the
    // picker to another layout without extending the schema fails here.
    foreach (self::THEMED_LAYOUT_IDS as $layout_id) {
      $this->assertContains($layout_id, $mapped_ids, "{$layout_id} has the theme picker but no schema mapping.");
    }
  }

}
