<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\Yaml\Yaml;

/**
 * Characterizes the Facts and Figures icon allowed-values bridge.
 *
 * Phase 0 of the ys_core cleanup (yalesites-org/YaleSites-Internal#579)
 * requires pinning the current behavior of everything scheduled to move in a
 * later phase. The procedural bridge ys_core_facts_icon_allowed_values() had
 * no coverage at all: it calls \Drupal::service(), so a unit test cannot
 * exercise it, and no test in the tree loaded ys_core.module.
 *
 * The bridge matters more than its size suggests. Field storage config names
 * it as an 'allowed_values_function' string, so the wiring is a
 * stringly-typed contract that no static analysis or refactoring tool can
 * follow. If a later phase moves the icon manager to a new module and renames
 * the function without updating every synced field storage YAML, the icon
 * field silently loses all of its options and nothing else in the suite
 * fails.
 *
 * @group ys_core
 * @group yalesites
 *
 * @see ys_core_facts_icon_allowed_values()
 * @see \Drupal\ys_core\FactsAndFiguresIconManager
 */
class FactsIconAllowedValuesBridgeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Because ys_core declares cas + role_delegation + the two form-element
   * modules as dependencies, and cas in turn needs externalauth, enabling
   * ys_core in a kernel test pulls the whole chain in. Omitting externalauth
   * fails at container compile with a non-obvious "non-existent service
   * externalauth.externalauth".
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'multivalue_form_element',
    'media_library_form_element',
    'role_delegation',
    'externalauth',
    'cas',
    'ys_core',
  ];

  /**
   * The icon manager service.
   *
   * @var \Drupal\ys_core\FactsAndFiguresIconManager
   */
  protected $iconManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->iconManager = $this->container->get('ys_core.facts_and_figures_icon_manager');
    // Start from a cold cache so the YAML-parsing path is the one under test,
    // not a warm entry left by another test in the same kernel.
    $this->iconManager->clearCache();
  }

  /**
   * Calls the procedural bridge the way Drupal's field system calls it.
   *
   * @return array
   *   The allowed values returned by the bridge.
   */
  protected function callBridge(): array {
    $definition = $this->createMock(FieldStorageDefinitionInterface::class);
    return ys_core_facts_icon_allowed_values($definition);
  }

  /**
   * The bridge is reachable and delegates to the icon manager service.
   */
  public function testBridgeDelegatesToIconManager(): void {
    $this->assertTrue(
      function_exists('ys_core_facts_icon_allowed_values'),
      'Enabling ys_core loads ys_core.module, which defines the bridge.'
    );

    $options = $this->callBridge();
    // Without this, two empty arrays would satisfy the comparison below and the
    // test would survive the service returning nothing.
    $this->assertNotEmpty($options, 'The bridge returns actual options.');

    $this->assertSame(
      $this->iconManager->getFlatIconOptions(),
      $options,
      'The bridge returns the icon manager service output unchanged.'
    );
  }

  /**
   * The bridge returns the "none" option plus icons from the CLT manifest.
   *
   * The component library YAML is the documented single source of truth, so
   * this asserts against the manifest rather than a hardcoded icon list:
   * adding or removing an icon in the component library must not fail this
   * test, but losing the manifest wiring entirely must.
   *
   * Comparing the service against the file it reads looks circular, but the
   * point is to catch a *silent fallback*. When the manifest cannot be found or
   * parsed, getIconConfig() logs and returns getFallbackConfig() -- three
   * hardcoded icons instead of the manifest's full set -- and every caller
   * carries on as if nothing happened. The exact-count assertion below is what
   * distinguishes "read the real manifest" from "quietly degraded".
   */
  public function testBridgeReturnsNoneOptionAndManifestIcons(): void {
    $manifest = $this->readIconManifest();
    $options = $this->callBridge();

    $this->assertArrayHasKey(
      $manifest['config']['default_value'],
      $options,
      'The default ("none") value is offered as an option.'
    );
    $this->assertSame(
      $manifest['config']['none_label'],
      $options[$manifest['config']['default_value']],
      'The none option uses the label from the manifest.'
    );

    // Every icon in the manifest is offered, with the manifest's label.
    $this->assertNotEmpty($manifest['icons'], 'Guard: the manifest defines icons.');
    foreach ($manifest['icons'] as $key => $label) {
      $this->assertArrayHasKey($key, $options, "Manifest icon '$key' is offered.");
      $this->assertSame($label, $options[$key], "Icon '$key' keeps its manifest label.");
    }

    // The none option is the only entry that is not a manifest icon.
    $this->assertCount(count($manifest['icons']) + 1, $options);
  }

  /**
   * Every ys_core allowed-values function named in synced config exists.
   *
   * This is the guard for constraint 5 of the cleanup issue. The
   * 'allowed_values_function' setting is stored as a plain string in field
   * storage config, so a rename that misses a YAML file breaks the field with
   * no other symptom. Any relocation phase that renames or moves the bridge
   * must update every synced field storage in the same change, and this test
   * is what enforces that.
   */
  public function testSyncedAllowedValuesFunctionsExist(): void {
    $sync_dir = \Drupal::root() . '/' . \Drupal::service('extension.list.profile')
      ->getPath('yalesites_profile') . '/config/sync';
    $this->assertDirectoryExists($sync_dir);

    $checked = [];
    foreach (glob($sync_dir . '/field.storage.*.yml') as $file) {
      $storage = Yaml::parseFile($file);
      $function = $storage['settings']['allowed_values_function'] ?? '';
      // Only our own callbacks; contrib and core ones are not our contract.
      if ($function === '' || !str_starts_with($function, 'ys_core_')) {
        continue;
      }
      $checked[$function][] = basename($file);
      $this->assertTrue(
        function_exists($function),
        sprintf(
          "Synced config %s names allowed_values_function '%s', which does not exist. "
          . 'Moving or renaming that function requires updating the field storage config in the same change.',
          basename($file),
          $function
        )
      );
    }

    // Anti-vacuity guard: if the glob or the key path ever stops matching, this
    // test must fail loudly rather than silently checking nothing.
    $this->assertNotEmpty(
      $checked,
      'Expected at least one synced field storage to name a ys_core_* allowed_values_function.'
    );
    $this->assertArrayHasKey(
      'ys_core_facts_icon_allowed_values',
      $checked,
      'The Facts and Figures icon bridge is still wired up in synced field storage config.'
    );
  }

  /**
   * A cache miss parses the manifest and caches it with the manifest's tags.
   *
   * The existing unit test stubs a permanent cache hit, so the cache-write path
   * in FactsAndFiguresIconManager::getIconConfig() was never executed. Real TTL
   * and tag behavior cannot be expressed with a mocked backend.
   */
  public function testIconConfigIsCachedWithManifestTagsAndMaxAge(): void {
    $manifest = $this->readIconManifest();
    $cache = $this->container->get('cache.default');

    // setUp() cleared the cache; confirm the starting state before asserting a
    // write, so an always-warm cache cannot make this pass vacuously.
    $this->assertFalse($cache->get('ys_core_facts_figures_icons'), 'Cache starts cold.');

    $before = time();
    $this->iconManager->getFlatIconOptions();

    $cached = $cache->get('ys_core_facts_figures_icons');
    $this->assertNotFalse($cached, 'Reading options on a cold cache writes the cache entry.');
    $this->assertSame($manifest['icons'], $cached->data['icons'], 'The parsed manifest is what gets cached.');

    // Tags and TTL come from the manifest's own cache section.
    $this->assertNotEmpty(
      $manifest['cache']['tags'],
      'Guard: the manifest declares cache tags, so the loop asserts something.'
    );
    foreach ($manifest['cache']['tags'] as $tag) {
      $this->assertContains($tag, $cached->tags, "Cache entry carries the '$tag' tag.");
    }
    $this->assertGreaterThanOrEqual($before + $manifest['cache']['max_age'], $cached->expire);

    // clearCache() removes it again.
    $this->iconManager->clearCache();
    $this->assertFalse($cache->get('ys_core_facts_figures_icons'), 'clearCache() deletes the entry.');
  }

  /**
   * Reads the component library icon manifest the service reads.
   *
   * @return array
   *   The parsed manifest.
   */
  protected function readIconManifest(): array {
    $path = \Drupal::root() . '/themes/contrib/atomic/'
      . 'node_modules/@yalesites-org/component-library-twig/components/02-molecules/'
      . 'facts-and-figures/facts-and-figures-icons.yml';

    if (!file_exists($path)) {
      $this->markTestSkipped(
        'The component library icon manifest is not installed at ' . $path
        . ' (run the theme npm install). The service falls back to a hardcoded'
        . ' list in that case, which is a different code path.'
      );
    }

    return Yaml::parseFile($path);
  }

}
