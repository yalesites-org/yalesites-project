<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Serialization\Yaml;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\views\Kernel\ViewsKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;
use Drupal\views\Views;

/**
 * Tests that the Dashboard "Site editors" table lists each user once.
 *
 * The view filters users by three roles, and a person can hold more than one of
 * them. The role filter joins user__roles, which holds one row per user per
 * role, so without something collapsing that join a user matching N of the
 * three roles produces N identical rows. Combined with the 10-row pager, a site
 * whose editors all hold two roles could only ever surface five real people.
 *
 * These tests exercise the *real* view from the profile's sync directory rather
 * than a fixture copy, so they fail if that config regresses.
 *
 * The Roles column is deliberately not asserted here. It uses core's
 * user_roles field plugin, a PrerenderList whose query() adds only
 * users_field_data.uid and whose preRender() runs its own separate query
 * against user__roles keyed on those uids. It never joins user__roles into the
 * main query, so it cannot be affected by the join shape this file is about.
 *
 * @group ys_core
 * @group yalesites
 *
 * @see \Drupal\views\ManyToOneHelper::ensureMyTable()
 * @see \Drupal\user\Plugin\views\field\Roles::preRender()
 */
class DashboardSiteEditorsViewTest extends ViewsKernelTestBase {

  use UserCreationTrait;

  // This test needs ViewsKernelTestBase, so it cannot extend YsKernelTestBase.
  // These three must stay in sync with that class - see its docblock for why
  // they move together, and KernelTestIsolationContractTest, which enforces it.
  /**
   * {@inheritdoc}
   */
  protected $runTestInSeparateProcess = FALSE;

  /**
   * {@inheritdoc}
   */
  protected $backupGlobals = FALSE;

  /**
   * {@inheritdoc}
   */
  protected $backupStaticAttributes = FALSE;

  /**
   * The view under test.
   */
  private const VIEW_ID = 'ys_dashboard_site_editors';

  /**
   * The roles the view's filter selects on.
   */
  private const FILTERED_ROLES = ['contributor', 'editor', 'site_admin'];

  /**
   * A role the view's filter does not select on.
   */
  private const UNFILTERED_ROLE = 'file_manager';

  /**
   * {@inheritdoc}
   */
  protected function setUp($import_test_views = TRUE): void {
    parent::setUp(FALSE);

    $this->installEntitySchema('user');
    $this->installConfig(['user']);

    foreach ([...self::FILTERED_ROLES, self::UNFILTERED_ROLE] as $rid) {
      Role::create(['id' => $rid, 'label' => $rid])->save();
    }

    $sync_dir = \Drupal::root() . '/' . \Drupal::service('extension.list.profile')
      ->getPath('yalesites_profile') . '/config/sync';
    $path = $sync_dir . '/views.view.' . self::VIEW_ID . '.yml';
    View::create(Yaml::decode(file_get_contents($path)))->save();
  }

  /**
   * Creates an active user holding the given roles.
   *
   * @param string $name
   *   The account name.
   * @param array $roles
   *   Role IDs to assign.
   * @param int $login
   *   Last-login timestamp, so sort order is deterministic.
   *
   * @return \Drupal\user\Entity\User
   *   The saved user.
   */
  private function createEditor(string $name, array $roles, int $login): User {
    return $this->createUser([], $name, FALSE, [
      'roles' => $roles,
      'login' => $login,
    ]);
  }

  /**
   * Executes a display and returns the uid of every result row, in order.
   *
   * @param string $display_id
   *   Defaults to the block the dashboard template renders.
   *
   * @return string[]
   *   One entry per row, so duplicate rows show up as repeated uids.
   */
  private function executedUids(string $display_id = 'block_1'): array {
    $view = Views::getView(self::VIEW_ID);
    $view->setDisplay($display_id);
    $this->executeView($view);
    return array_map(static fn($row) => (string) $row->uid, $view->result);
  }

  /**
   * A user appears exactly once however many of the filtered roles they hold.
   *
   * Covers the reported defect directly: before the fix the three-role user
   * yielded three rows and the two-role user two.
   */
  public function testEachUserListedOnceRegardlessOfRoleCount(): void {
    $three = $this->createEditor('three-roles', self::FILTERED_ROLES, 3000);
    $two = $this->createEditor('two-roles', ['contributor', 'editor'], 2000);
    $one = $this->createEditor('one-role', ['editor'], 1000);
    // Deliberately the most recent login, so a leak in the role filter would
    // put this user first and make the failure diff unmissable.
    $none = $this->createEditor('no-filtered-role', [self::UNFILTERED_ROLE], 4000);

    $uids = $this->executedUids();

    $this->assertSame(
      [$three->id(), $two->id(), $one->id()],
      $uids,
      'Each qualifying user should appear exactly once, most recent login first.'
    );
    $this->assertNotContains(
      (string) $none->id(),
      $uids,
      'A user holding none of the filtered roles should not appear at all.'
    );
  }

  /**
   * The 10 rows are filled with 10 distinct users, not with duplicates.
   *
   * This is the practical damage in the report: every one of these users holds
   * two of the filtered roles, so before the fix the pager's 10 rows were spent
   * on five people.
   */
  public function testTenRowCapFillsWithDistinctUsers(): void {
    $expected = [];
    // Twelve, so the cap is genuinely exceeded rather than exactly met.
    for ($i = 0; $i < 12; $i++) {
      // Descending login, so creation order is also the expected row order.
      $expected[] = $this->createEditor("editor-$i", ['contributor', 'editor'], 9000 - $i)->id();
    }

    $uids = $this->executedUids();

    $this->assertSame($uids, array_unique($uids), 'No user should be repeated.');
    $this->assertSame(
      array_slice($expected, 0, 10),
      $uids,
      'The 10 rows should be the 10 most recently logged-in distinct users.'
    );
  }

  /**
   * The page display reaches the users the block's 10-row cap hides.
   *
   * The block is capped at 10 by a "some" pager, so the dashboard links to this
   * display to get at the rest. The assertion that matters is that the page is
   * NOT subject to that cap: a page returning 10 would mean the display's pager
   * override silently failed and it had inherited the block's.
   */
  public function testPageDisplayReachesUsersPastTheBlockCap(): void {
    $expected = [];
    for ($i = 0; $i < 12; $i++) {
      $expected[] = $this->createEditor("editor-$i", ['contributor', 'editor'], 9000 - $i)->id();
    }

    $this->assertSame(
      $expected,
      $this->executedUids('page_1'),
      'The page display should list every qualifying user, not just the block cap of 10.'
    );
  }

}
