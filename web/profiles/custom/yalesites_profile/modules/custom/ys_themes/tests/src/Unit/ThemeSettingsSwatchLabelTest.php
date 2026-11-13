<?php

namespace Drupal\Tests\ys_themes\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * The palette swatch label template must be registered by the module.
 *
 * Formdazzle turns each radio on the theme settings form into a
 * `form_element_label__ys_themes_settings_form__<setting>` theme hook, and
 * matching templates lived in the atomic theme. They never rendered (#1700):
 * /admin/yalesites/themes is an admin route, so the active theme is
 * ys_admin_theme (base chain ys_admin_theme -> gin -> claro -> stable9), atomic
 * is not in that chain, and the templates were therefore never in the registry
 * for the request -- in the off-canvas tray either, which resolves the same
 * route. Editors saw bare radio labels with no color chips.
 *
 * Registering the hook from the module is what makes it theme-agnostic: a
 * module's hook_theme() entries are in every theme's registry. That is the fix
 * this guards, which is why it asserts on the registration rather than on the
 * existence of a template file somewhere.
 *
 * @group ys_themes
 * @group yalesites
 */
class ThemeSettingsSwatchLabelTest extends UnitTestCase {

  /**
   * Registers the swatch label template for every setting on the form.
   *
   * One entry, not one per setting: core's ThemeManager strips trailing `__`
   * segments until it finds a registered hook, so the per-setting hooks
   * Formdazzle generates all fall back to this one -- including those of any
   * setting added later.
   */
  public function testTheSwatchLabelHookIsRegisteredWithAnExistingTemplate(): void {
    $module_dir = dirname(__DIR__, 3);
    // The file holds only `use` statements and function definitions, so it is
    // safe to require here; the hook itself touches no services.
    require_once $module_dir . '/ys_themes.module';

    $hooks = ys_themes_theme([], 'module', 'ys_themes', $module_dir);
    $hook = 'form_element_label__ys_themes_settings_form';

    $this->assertArrayHasKey($hook, $hooks);
    $this->assertSame(
      'form_element_label',
      $hooks[$hook]['base hook'] ?? NULL,
      'Without the base hook the registry treats this as an unrelated hook and the fallback never reaches it.'
    );
    $this->assertFileExists($module_dir . '/templates/' . $hooks[$hook]['template'] . '.html.twig');
  }

}
