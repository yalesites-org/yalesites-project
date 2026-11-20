<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the account and page-delete permissions of the editing roles.
 *
 * @group ys_core
 * @group yalesites
 */
class UserRolePermissionsTest extends UnitTestCase {

  /**
   * Loads a role's permission list from the profile config.
   */
  protected function permissions(string $role): array {
    $config = dirname(__DIR__, 6) . '/config/sync/user.role.' . $role . '.yml';
    $this->assertFileExists($config);
    return Yaml::parseFile($config)['permissions'];
  }

  /**
   * Contributors and Editors cannot manage other users' accounts.
   *
   * @dataProvider roleProvider
   */
  public function testAdministerUsersByRole(string $role, bool $expected): void {
    $this->assertSame($expected, in_array('administer users', $this->permissions($role), TRUE));
  }

  /**
   * Roles and whether they hold "administer users".
   */
  public static function roleProvider(): array {
    return [
      'contributor' => ['contributor', FALSE],
      'editor' => ['editor', FALSE],
      'site admin' => ['site_admin', TRUE],
      'platform admin' => ['platform_admin', TRUE],
    ];
  }

  /**
   * Contributors hold no content-type node delete, but keep block deletes.
   */
  public function testContributorNodeDelete(): void {
    $permissions = $this->permissions('contributor');
    $node_delete = array_filter($permissions, fn($p) => preg_match('/^delete (any|own) [a-z_]+ content$/', $p) && !str_ends_with($p, ' block content'));
    $this->assertSame([], array_values($node_delete));
    $this->assertContains('delete any text block content', $permissions);
  }

  /**
   * Editors keep their node delete permissions.
   */
  public function testEditorKeepsNodeDelete(): void {
    $permissions = $this->permissions('editor');
    $this->assertContains('delete any page content', $permissions);
    $this->assertContains('delete own post content', $permissions);
  }

}
