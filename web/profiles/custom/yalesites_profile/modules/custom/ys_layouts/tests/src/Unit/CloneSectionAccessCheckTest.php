<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\layout_builder\DefaultsSectionStorageInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\layout_builder_lock\LayoutBuilderLock;
use Drupal\ys_layouts\Access\CloneSectionAccessCheck;

/**
 * Tests which layout_builder_lock settings stop a section being cloned.
 *
 * The layout_builder_lock module enforces its locks on core's section routes at
 * the route level, so without an equivalent check on the clone route an editor
 * could duplicate a curated section by visiting the URL directly.
 *
 * The distinction under test is content locks versus positional locks: the
 * former say a section's contents are not the editor's to change and refuse the
 * clone, while LOCKED_SECTION_BEFORE / LOCKED_SECTION_AFTER only govern where a
 * new section may be added and do not. The Content Section of Event and Post
 * carries exactly the positional pair, so it is the case that distinction
 * exists to allow.
 *
 * @group yalesites
 * @group ys_layouts
 *
 * @coversDefaultClass \Drupal\ys_layouts\Access\CloneSectionAccessCheck
 */
class CloneSectionAccessCheckTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The access results declare the 'user.permissions' cache context, and
    // validating a context token goes through the container.
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);

    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);
  }

  /**
   * Builds a route match reporting the given delta.
   *
   * @param string|null $delta
   *   The raw delta route parameter, or NULL when absent.
   *
   * @return \Drupal\Core\Routing\RouteMatchInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked route match for the route being checked.
   */
  protected function routeMatch(?string $delta = '0') {
    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getRawParameter')->with('delta')->willReturn($delta);

    return $route_match;
  }

  /**
   * Builds a section storage serving one section at delta 0.
   *
   * @param array $lock
   *   The layout_builder_lock lock settings to put on the section.
   * @param string $interface
   *   The section storage interface to mock, so the defaults and overrides
   *   branches can both be exercised.
   * @param array $regions
   *   The layout_builder_lock per-region lock settings to put on the section.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked section storage.
   */
  protected function storageWithLock(array $lock, string $interface = SectionStorageInterface::class, array $regions = []) {
    $section = new Section('layout_onecol');
    if ($lock) {
      $section->setThirdPartySetting('layout_builder_lock', 'lock', $lock);
    }
    if ($regions) {
      $section->setThirdPartySetting('layout_builder_lock', 'regions', $regions);
    }

    $section_storage = $this->createMock($interface);
    $section_storage->method('getSection')->willReturn($section);

    return $section_storage;
  }

  /**
   * Builds an account with or without a named permission.
   *
   * @param string|null $permission
   *   The single permission the account holds, or NULL for none.
   *
   * @return \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked account.
   */
  protected function account(?string $permission = NULL) {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      fn (string $requested) => $permission !== NULL && $requested === $permission
    );

    return $account;
  }

  /**
   * Every content lock on its own refuses the clone.
   *
   * Each of these says some part of the section's contents is not the editor's
   * to change, so a copy would be a section they cannot populate or correct.
   *
   * @covers ::access
   *
   * @dataProvider contentLockProvider
   */
  public function testContentLockedSectionCannotBeCloned(int $lock): void {
    $result = (new CloneSectionAccessCheck())->access(
      $this->storageWithLock([$lock => $lock]),
      $this->account(),
      $this->routeMatch()
    );

    $this->assertTrue($result->isForbidden(), 'Cloning a section with this content lock is refused.');
  }

  /**
   * Supplies every layout_builder_lock setting that is not positional.
   *
   * @return array[]
   *   Test cases of [lock value], keyed by the setting's name.
   */
  public static function contentLockProvider(): array {
    return [
      'LOCKED_BLOCK_UPDATE' => [LayoutBuilderLock::LOCKED_BLOCK_UPDATE],
      'LOCKED_BLOCK_DELETE' => [LayoutBuilderLock::LOCKED_BLOCK_DELETE],
      'LOCKED_BLOCK_MOVE' => [LayoutBuilderLock::LOCKED_BLOCK_MOVE],
      'LOCKED_BLOCK_ADD' => [LayoutBuilderLock::LOCKED_BLOCK_ADD],
      'LOCKED_SECTION_CONFIGURE' => [LayoutBuilderLock::LOCKED_SECTION_CONFIGURE],
      'LOCKED_SECTION_BLOCK_MOVE' => [LayoutBuilderLock::LOCKED_SECTION_BLOCK_MOVE],
    ];
  }

  /**
   * The positional locks alone do not stop a section being cloned.
   *
   * This is the Content Section of Event and Post: it carries exactly this
   * pair, which only removes the "Add section" links either side of it and says
   * nothing about the blocks inside, so it is cloneable.
   *
   * @covers ::access
   */
  public function testPositionallyLockedSectionCanBeCloned(): void {
    $result = (new CloneSectionAccessCheck())->access(
      $this->storageWithLock([
        LayoutBuilderLock::LOCKED_SECTION_BEFORE => LayoutBuilderLock::LOCKED_SECTION_BEFORE,
        LayoutBuilderLock::LOCKED_SECTION_AFTER => LayoutBuilderLock::LOCKED_SECTION_AFTER,
      ]),
      $this->account(),
      $this->routeMatch()
    );

    $this->assertTrue($result->isAllowed(), 'A section locked only against neighbouring sections is cloneable.');
  }

  /**
   * A content lock still refuses when positional locks sit alongside it.
   *
   * The skeleton sections carry the whole set, so the positional pair must not
   * mask the content locks it is mixed with.
   *
   * @covers ::access
   */
  public function testPositionalLocksDoNotExcuseContentLocks(): void {
    $result = (new CloneSectionAccessCheck())->access(
      $this->storageWithLock([
        LayoutBuilderLock::LOCKED_SECTION_BEFORE => LayoutBuilderLock::LOCKED_SECTION_BEFORE,
        LayoutBuilderLock::LOCKED_SECTION_AFTER => LayoutBuilderLock::LOCKED_SECTION_AFTER,
        LayoutBuilderLock::LOCKED_BLOCK_ADD => LayoutBuilderLock::LOCKED_BLOCK_ADD,
      ]),
      $this->account(),
      $this->routeMatch()
    );

    $this->assertTrue($result->isForbidden(), 'A content lock mixed with positional locks still refuses.');
  }

  /**
   * A per-region lock refuses the clone.
   *
   * Region locks freeze the blocks of one region, so they are content locks in
   * every meaningful sense. Without reading them, a section locked only
   * per-region would have become cloneable when the positional locks stopped
   * blocking.
   *
   * @covers ::access
   */
  public function testRegionLockedSectionCannotBeCloned(): void {
    $result = (new CloneSectionAccessCheck())->access(
      $this->storageWithLock([], regions: ['content' => ['content']]),
      $this->account(),
      $this->routeMatch()
    );

    $this->assertTrue($result->isForbidden(), 'Cloning a section with a locked region is refused.');
  }

  /**
   * Region settings with nothing selected count as unlocked.
   *
   * @covers ::access
   */
  public function testSectionWithEmptyRegionLocksCanBeCloned(): void {
    $result = (new CloneSectionAccessCheck())->access(
      $this->storageWithLock([], regions: ['content' => []]),
      $this->account(),
      $this->routeMatch()
    );

    $this->assertTrue($result->isAllowed(), 'A section whose region locks are all unselected is cloneable.');
  }

  /**
   * A section with no lock settings can be cloned.
   *
   * @covers ::access
   */
  public function testUnlockedSectionCanBeCloned(): void {
    $result = (new CloneSectionAccessCheck())->access($this->storageWithLock([]), $this->account(), $this->routeMatch());

    $this->assertTrue($result->isAllowed(), 'Cloning an unlocked section is allowed.');
  }

  /**
   * Lock settings that are all unchecked count as unlocked.
   *
   * The lock form stores an unchecked box as 0, so the raw setting is only
   * meaningful after filtering — matching how layout_builder_lock reads it.
   *
   * @covers ::access
   */
  public function testSectionWithOnlyUncheckedLocksCanBeCloned(): void {
    $result = (new CloneSectionAccessCheck())->access(
      $this->storageWithLock([
        LayoutBuilderLock::LOCKED_BLOCK_ADD => 0,
        LayoutBuilderLock::LOCKED_SECTION_CONFIGURE => 0,
      ]),
      $this->account(),
      $this->routeMatch()
    );

    $this->assertTrue($result->isAllowed(), 'A section whose locks are all unchecked is cloneable.');
  }

  /**
   * The overrides bypass permission allows cloning a locked section.
   *
   * @covers ::access
   */
  public function testBypassPermissionAllowsCloningLockedSection(): void {
    $result = (new CloneSectionAccessCheck())->access(
      $this->storageWithLock([LayoutBuilderLock::LOCKED_BLOCK_ADD => LayoutBuilderLock::LOCKED_BLOCK_ADD]),
      $this->account('bypass lock settings on layout overrides'),
      $this->routeMatch()
    );

    $this->assertTrue($result->isAllowed(), 'A user who may bypass lock settings can clone a locked section.');
  }

  /**
   * On the default layout, the manage-locks permission is what applies.
   *
   * @covers ::access
   */
  public function testManageLocksPermissionAppliesToTheDefaultLayout(): void {
    $lock = [LayoutBuilderLock::LOCKED_BLOCK_ADD => LayoutBuilderLock::LOCKED_BLOCK_ADD];

    $allowed = (new CloneSectionAccessCheck())->access(
      $this->storageWithLock($lock, DefaultsSectionStorageInterface::class),
      $this->account('manage lock settings on sections'),
      $this->routeMatch()
    );
    $this->assertTrue($allowed->isAllowed(), 'A user who manages lock settings can clone a locked default section.');

    $forbidden = (new CloneSectionAccessCheck())->access(
      $this->storageWithLock($lock, DefaultsSectionStorageInterface::class),
      $this->account('bypass lock settings on layout overrides'),
      $this->routeMatch()
    );
    $this->assertTrue($forbidden->isForbidden(), 'The overrides bypass permission does not apply to the default layout.');
  }

  /**
   * A missing delta is left to the router rather than refused here.
   *
   * @covers ::access
   */
  public function testMissingDeltaIsNotRefusedHere(): void {
    $result = (new CloneSectionAccessCheck())->access($this->storageWithLock([]), $this->account(), $this->routeMatch(NULL));

    $this->assertTrue($result->isAllowed(), 'A missing delta is not this check\'s decision to make.');
  }

  /**
   * A delta that does not resolve is left to the controller.
   *
   * @covers ::access
   */
  public function testUnresolvableDeltaIsNotRefusedHere(): void {
    $section_storage = $this->createMock(SectionStorageInterface::class);
    $section_storage->method('getSection')->willThrowException(new \OutOfBoundsException('Invalid delta "9"'));

    $result = (new CloneSectionAccessCheck())->access($section_storage, $this->account(), $this->routeMatch('9'));

    $this->assertTrue($result->isAllowed(), 'An out-of-range delta is not refused as a lock decision.');
  }

}
