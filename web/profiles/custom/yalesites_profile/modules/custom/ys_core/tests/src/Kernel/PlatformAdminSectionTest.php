<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests the platform-admin-only admin menu section.
 *
 * @group ys_core
 * @group yalesites
 */
class PlatformAdminSectionTest extends YsKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'ys_core'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);

    // enableModules() skips the menu rebuild ModuleInstaller would do, so the
    // menu_tree table is empty until asked. The router needs no explicit
    // rebuild: KernelTestBase's route provider builds itself on first use.
    $this->container->get('plugin.manager.menu.link')->rebuild();
  }

  /**
   * The section is top level and the platform-admin-only screens hang under it.
   */
  public function testSectionMenuShape(): void {
    $manager = $this->container->get('plugin.manager.menu.link');

    $this->assertSame('system.admin', $manager->getDefinition('ys_core.admin_platform_admin')['parent']);

    $children = array_keys($manager->getChildIds('ys_core.admin_platform_admin'));
    $this->assertContains('ys_core.platform_admin_settings', $children);
    $this->assertContains('system.admin_reports', $children);

    // The everyday settings section is for settings site admins actually use.
    $this->assertNotContains(
      'ys_core.platform_admin_settings',
      array_keys($manager->getChildIds('ys_core.admin_yalesites'))
    );

    // Titled just "Settings" now that the parent names the audience.
    $this->assertSame('Settings', (string) $manager->getDefinition('ys_core.platform_admin_settings')['title']);
  }

  /**
   * The landing route is reachable by anyone who can reach one of its children.
   *
   * The middle case is the subtree-pruning guard: were the landing route gated
   * on 'administer platform admin settings', an account holding only
   * 'access site reports' would lose Reports from the menu entirely even though
   * it can still open the page by URL.
   */
  public function testSectionAccessFollowsChildAccess(): void {
    // User 1 bypasses every permission check, so burn uid 1 first.
    $this->createUser();

    $platform_admin = $this->createUser(['administer platform admin settings']);
    // Reports is itself a menu-block page, so this account also needs a
    // permission reaching one of its children for Reports to be reachable.
    $reports_only = $this->createUser([
      'access site reports',
      'administer site configuration',
    ]);
    $site_admin = $this->createUser(['yalesites manage settings']);

    $this->assertTrue($this->hasSectionAccess($platform_admin));
    $this->assertTrue($this->hasSectionAccess($reports_only));
    $this->assertFalse($this->hasSectionAccess($site_admin));
  }

  /**
   * The exported menu override agrees with the reparenting hook.
   *
   * The config object core.menu.static_menu_link_overrides is applied after
   * hook_menu_links_discovered_alter() and is what actually takes effect on a
   * built site, so the two must not drift. Kernel tests do not load the
   * profile's config/sync, hence reading the exported file directly.
   */
  public function testExportedOverrideAgreesWithTheHook(): void {
    // .../yalesites_profile/modules/custom/ys_core -> .../yalesites_profile.
    $profile = dirname(\Drupal::service('extension.list.module')->getPath('ys_core'), 3);
    $exported = DRUPAL_ROOT . '/' . $profile . '/config/sync/core.menu.static_menu_link_overrides.yml';
    $this->assertFileExists($exported);

    $definitions = Yaml::decode(file_get_contents($exported))['definitions'];
    $this->assertSame(
      'ys_core.admin_platform_admin',
      $definitions['system__admin_reports']['parent']
    );

    // The section's own link is pinned there too, so its parent and weight must
    // match what ys_core.links.menu.yml declares.
    $declared = $this->container->get('plugin.manager.menu.link')
      ->getDefinition('ys_core.admin_platform_admin');
    $override = $definitions['ys_core__admin_platform_admin'];
    $this->assertSame($declared['parent'], $override['parent']);
    $this->assertSame((int) $declared['weight'], $override['weight']);
  }

  /**
   * Another module's link hangs off a parent ID this module actually defines.
   *
   * A parent ID that does not resolve is not an error in Drupal:
   * MenuTreeStorage::rebuild() forces such links to the top level instead. That
   * would silently promote a platform-admin-only report into the toolbar for
   * everyone, so the cross-module contract is asserted rather than assumed.
   * ys_layouts is not installed here (its dependency chain is heavy for a
   * kernel test), so its declared parent is read from its own YAML.
   */
  public function testOtherModulesParentIdResolves(): void {
    $ys_layouts = dirname(\Drupal::service('extension.list.module')->getPath('ys_core'))
      . '/ys_layouts/ys_layouts.links.menu.yml';
    $this->assertFileExists(DRUPAL_ROOT . '/' . $ys_layouts);

    $links = Yaml::decode(file_get_contents(DRUPAL_ROOT . '/' . $ys_layouts));
    $parent = $links['ys_layouts.orphaned_blocks_report']['parent'];

    $this->assertSame('ys_core.admin_platform_admin', $parent);
    $this->assertNotNull(
      $this->container->get('plugin.manager.menu.link')->getDefinition($parent),
      sprintf('ys_layouts hangs a link off "%s", which ys_core must define.', $parent)
    );
  }

  /**
   * Checks access to the section landing route for an account.
   */
  private function hasSectionAccess(AccountInterface $account): bool {
    return $this->container->get('access_manager')
      ->checkNamedRoute('ys_core.admin_platform_admin', [], $account);
  }

}
