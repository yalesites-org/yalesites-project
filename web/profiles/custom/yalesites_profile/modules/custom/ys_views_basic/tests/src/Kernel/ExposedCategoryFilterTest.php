<?php

namespace Drupal\Tests\ys_views_basic\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Entity\View;

/**
 * Guards the scaffold views' exposed category-filter pass-through (#1299).
 *
 * QA reported that filtering a post listing by category "wasn't passing any
 * terms through". End-to-end testing on the live site showed category filtering
 * does work: a post listing narrows from all posts to only the matching post
 * when a category term is submitted. The single subtlety is that the category
 * filter is exposed as a *multi-value* select, so the visitor's selection
 * arrives as an array (`field_category_target_id[]=<tid>`); a scalar value is
 * silently dropped by Drupal's taxonomy_index_tid filter.
 *
 * There is no separately unit-testable seam for the full programmatic execute
 * path (the scaffold view is coupled to the argument array ViewsBasicManager
 * builds and to node-access + row rendering), so this test locks in the
 * configuration contract that makes the pass-through possible: the scaffold
 * views must keep an *exposed, multi-value* taxonomy category filter on the
 * node category field. If a future change drops `exposed`, flips `multiple`, or
 * repoints the field, filtering breaks again and this test fails.
 *
 * @group yalesites
 */
class ExposedCategoryFilterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'views',
  ];

  /**
   * Loads a scaffold view config entity from the site's config/sync directory.
   *
   * The scaffold views live in site config (not in the module), so they are
   * read straight from config/sync and instantiated for inspection.
   */
  private function loadScaffoldView(string $view_id): View {
    // The module lives at
    // profiles/custom/yalesites_profile/modules/custom/ys_views_basic; the
    // config/sync directory is three levels up, in the profile root.
    $path = $this->container->get('extension.list.module')->getPath('ys_views_basic');
    $sync = dirname($path, 3) . '/config/sync/views.view.' . $view_id . '.yml';
    $this->assertFileExists($sync, "Scaffold view config $view_id exists in config/sync.");
    $data = Yaml::decode(file_get_contents($sync));
    return View::create($data);
  }

  /**
   * Data provider: each scaffold view and its category filter field id.
   */
  public static function scaffoldViewProvider(): array {
    return [
      'listing scaffold' => ['views_basic_scaffold'],
      'events scaffold' => ['views_basic_scaffold_events'],
    ];
  }

  /**
   * The category filter stays exposed and multi-value on every scaffold view.
   *
   * @dataProvider scaffoldViewProvider
   */
  public function testCategoryFilterIsExposedMultiValue(string $view_id): void {
    $view = $this->loadScaffoldView($view_id);
    $filters = $view->get('display')['default']['display_options']['filters'];

    $this->assertArrayHasKey('field_category_target_id', $filters, "$view_id exposes a category filter.");
    $filter = $filters['field_category_target_id'];

    $this->assertSame('taxonomy_index_tid', $filter['plugin_id']);
    $this->assertSame('node__field_category', $filter['table']);
    $this->assertTrue((bool) $filter['exposed'], "$view_id category filter is exposed.");
    $this->assertSame('field_category_target_id', $filter['expose']['identifier']);
    // Multi-value is what makes the visitor selection arrive as an array; a
    // scalar submission is dropped, so this must stay TRUE for pass-through.
    $this->assertTrue((bool) $filter['expose']['multiple'], "$view_id category filter is multi-value.");
  }

}
