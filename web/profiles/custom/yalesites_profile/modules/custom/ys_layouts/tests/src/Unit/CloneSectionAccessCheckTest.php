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
 * Tests that locked sections cannot be cloned.
 *
 * The layout_builder_lock module enforces its locks on core's section routes at
 * the route level, so without an equivalent check on the clone route an editor
 * could duplicate a locked section by visiting the URL directly — and, because
 * the copy inherits the lock settings, be unable to remove what they created.
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
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked section storage.
   */
  protected function storageWithLock(array $lock, string $interface = SectionStorageInterface::class) {
    $section = new Section('layout_onecol');
    if ($lock) {
      $section->setThirdPartySetting('layout_builder_lock', 'lock', $lock);
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
   * A section carrying lock settings cannot be cloned.
   *
   * @covers ::access
   */
  public function testLockedSectionCannotBeCloned(): void {
    $result = (new CloneSectionAccessCheck())->access(
      $this->storageWithLock([LayoutBuilderLock::LOCKED_SECTION_AFTER => LayoutBuilderLock::LOCKED_SECTION_AFTER]),
      $this->account(),
      $this->routeMatch()
    );

    $this->assertTrue($result->isForbidden(), 'Cloning a locked section is refused.');
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
        LayoutBuilderLock::LOCKED_SECTION_BEFORE => 0,
        LayoutBuilderLock::LOCKED_SECTION_AFTER => 0,
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
      $this->storageWithLock([LayoutBuilderLock::LOCKED_SECTION_AFTER => LayoutBuilderLock::LOCKED_SECTION_AFTER]),
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
    $lock = [LayoutBuilderLock::LOCKED_SECTION_AFTER => LayoutBuilderLock::LOCKED_SECTION_AFTER];

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
