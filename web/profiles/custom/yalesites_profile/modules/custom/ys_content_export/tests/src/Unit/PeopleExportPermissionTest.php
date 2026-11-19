<?php

namespace Drupal\Tests\ys_content_export\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards who can use the People export.
 *
 * @group ys_content_export
 * @group yalesites
 */
class PeopleExportPermissionTest extends UnitTestCase {

  /**
   * The People export route uses its own permission, not "administer users".
   */
  public function testRouteRequiresDedicatedPermission(): void {
    $module = dirname(__DIR__, 3);
    $routes = Yaml::parseFile($module . '/ys_content_export.routing.yml');
    $permissions = Yaml::parseFile($module . '/ys_content_export.permissions.yml');
    $required = $routes['ys_content_export.export_people']['requirements']['_permission'];
    $this->assertSame('export people to csv', $required);
    $this->assertArrayHasKey($required, $permissions);
  }

  /**
   * Only the admin roles are granted the export permission.
   *
   * @dataProvider roleProvider
   */
  public function testRoleGrants(string $role, bool $expected): void {
    $config = dirname(__DIR__, 6) . '/config/sync/user.role.' . $role . '.yml';
    $this->assertFileExists($config);
    $role_config = Yaml::parseFile($config);
    $this->assertSame($expected, in_array('export people to csv', $role_config['permissions'], TRUE));
  }

  /**
   * Roles and whether they may export.
   */
  public static function roleProvider(): array {
    return [
      'site admin' => ['site_admin', TRUE],
      'platform admin' => ['platform_admin', TRUE],
      'editor' => ['editor', FALSE],
      'contributor' => ['contributor', FALSE],
    ];
  }

}
