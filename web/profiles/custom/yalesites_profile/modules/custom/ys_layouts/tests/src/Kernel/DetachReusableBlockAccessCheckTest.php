<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\ys_layouts\Access\DetachReusableBlockAccessCheck;
use Drupal\ys_layouts\ReusableBlockDetacher;
use Prophecy\Argument;
use Psr\Log\LoggerInterface;

/**
 * Tests the access check that gates the "Make non-reusable" contextual link.
 *
 * Covers issue #1449: the "Make non-reusable" affordance must appear on a
 * placed reusable block and must NOT appear on an inline (non-reusable) block.
 * Layout Builder decides whether to render a contextual link by checking that
 * link's route access (see \Drupal\Core\Menu\ContextualLinkManager), so this
 * access check is precisely what makes the affordance present or absent in the
 * UI. ReusableBlockDetacherTest exercises the detach service in isolation but
 * not this gate; this test closes that gap.
 *
 * @group ys_layouts
 * @coversDefaultClass \Drupal\ys_layouts\Access\DetachReusableBlockAccessCheck
 */
class DetachReusableBlockAccessCheckTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // The Section/SectionComponent value objects this test builds autoload via
    // the PHPUnit bootstrap without enabling layout_builder, and the access
    // check only reads the core entity.repository service, so no layout_builder
    // (or block_content) module needs to be enabled here.
    'system',
    'user',
  ];

  /**
   * The access check under test.
   *
   * @var \Drupal\ys_layouts\Access\DetachReusableBlockAccessCheck
   */
  protected DetachReusableBlockAccessCheck $accessCheck;

  /**
   * The logger the access check reports unresolvable components to.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy
   */
  protected $logger;

  /**
   * The layout tempstore holding the layout currently being edited.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy
   */
  protected $tempstore;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The detacher discriminates reusable vs. inline purely from the component
    // plugin id, but its constructor requires the entity repository.
    // Instantiate directly so the test avoids enabling ys_layouts and its
    // dependency chain (ys_localist -> migrate, etc.), matching
    // ReusableBlockDetacherTest.
    $detacher = new ReusableBlockDetacher(
      $this->container->get('entity.repository'),
      $this->container->get('entity_type.manager'),
    );
    $this->logger = $this->prophesize(LoggerInterface::class);
    // By default a layout has no unsaved changes, so the tempstore hands back
    // the storage it was given.
    $this->tempstore = $this->prophesize(LayoutTempstoreRepositoryInterface::class);
    $this->tempstore->get(Argument::any())->willReturnArgument(0);
    $this->accessCheck = new DetachReusableBlockAccessCheck(
      $detacher,
      $this->logger->reveal(),
      $this->tempstore->reveal()
    );
  }

  /**
   * Builds a section storage whose only section holds the given components.
   *
   * @param \Drupal\layout_builder\SectionComponent ...$components
   *   The components placed in the section (delta 0).
   *
   * @return \Drupal\layout_builder\SectionStorageInterface
   *   A section storage double returning that section for delta 0.
   */
  protected function sectionStorageWith(SectionComponent ...$components): SectionStorageInterface {
    $section = new Section('layout_onecol', [], $components);
    $section_storage = $this->prophesize(SectionStorageInterface::class);
    $section_storage->getSection(0)->willReturn($section);
    return $section_storage->reveal();
  }

  /**
   * A placement's component for the given plugin id.
   *
   * @param string $uuid
   *   The component uuid.
   * @param string $plugin_id
   *   The block plugin id (e.g. block_content:... or inline_block:...).
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   The section component.
   */
  protected function component(string $uuid, string $plugin_id): SectionComponent {
    return new SectionComponent($uuid, 'content', ['id' => $plugin_id]);
  }

  /**
   * The detach link is allowed on a placed reusable (block_content) block.
   *
   * @covers ::access
   */
  public function testAllowedForReusablePlacement(): void {
    $uuid = '11111111-1111-1111-1111-111111111111';
    $storage = $this->sectionStorageWith(
      $this->component($uuid, 'block_content:99999999-9999-9999-9999-999999999999'),
    );

    $result = $this->accessCheck->access($storage, 0, $uuid);

    $this->assertTrue($result->isAllowed(), 'The detach link is allowed for a placed reusable block, so it renders.');
    // The decision reflects live per-request layout state and must not be
    // cached, or an allowed result could leak onto other placements.
    $this->assertSame(0, $result->getCacheMaxAge(), 'The access result is not cacheable.');
  }

  /**
   * The detach link is not allowed on an inline (non-reusable) block.
   *
   * @covers ::access
   */
  public function testNotAllowedForInlinePlacement(): void {
    $uuid = '22222222-2222-2222-2222-222222222222';
    $storage = $this->sectionStorageWith(
      $this->component($uuid, 'inline_block:accordion'),
    );

    $result = $this->accessCheck->access($storage, 0, $uuid);

    $this->assertFalse($result->isAllowed(), 'The detach link is hidden on an inline block, which has nothing to detach.');
  }

  /**
   * A component that cannot be resolved is forbidden (nothing to detach).
   *
   * @covers ::access
   */
  public function testForbiddenForUnknownComponent(): void {
    // With nothing placed, any uuid is unresolvable: Section::getComponent()
    // throws, which the access check must swallow into a forbidden result
    // rather than surface as an error.
    $storage = $this->sectionStorageWith();

    $result = $this->accessCheck->access($storage, 0, 'deadbeef-0000-0000-0000-000000000000');

    $this->assertFalse($result->isAllowed());
    $this->assertTrue($result->isForbidden(), 'An unresolvable component is explicitly forbidden.');
  }

  /**
   * An unresolvable component is logged rather than swallowed silently.
   *
   * Layout Builder hides a contextual link whose route access is denied without
   * surfacing any error, so a swallowed exception here makes a missing
   * "Make non-reusable" action impossible to diagnose from the outside. The log
   * entry is the only trace, so it is part of the behavior under test.
   *
   * @covers ::access
   */
  public function testUnresolvableComponentIsLogged(): void {
    $storage = $this->sectionStorageWith();

    $this->accessCheck->access($storage, 0, 'deadbeef-0000-0000-0000-000000000000');

    $this->logger->debug(Argument::type('string'), Argument::type('array'))
      ->shouldHaveBeenCalled();
  }

  /**
   * The check judges the layout being edited, not the last saved one.
   *
   * Layout Builder swaps in the tempstore copy from a route enhancer, which
   * runs only while routing a real request - not from
   * AccessManager::checkNamedRoute(), which is how the contextual link system
   * evaluates this route. Judging the saved layout would keep offering
   * "Make non-reusable" on a placement already detached in this editing
   * session, and acting on it would then fail.
   *
   * @covers ::access
   */
  public function testJudgesTheLayoutBeingEdited(): void {
    $uuid = '33333333-3333-3333-3333-333333333333';
    // The saved layout still holds the reusable placement.
    $saved = $this->sectionStorageWith(
      $this->component($uuid, 'block_content:99999999-9999-9999-9999-999999999999'),
    );
    // The unsaved editing session has already detached it to an inline block.
    $edited = $this->sectionStorageWith(
      $this->component($uuid, 'inline_block:accordion'),
    );
    $this->tempstore->get($saved)->willReturn($edited);

    $result = $this->accessCheck->access($saved, 0, $uuid);

    $this->assertFalse(
      $result->isAllowed(),
      'A placement already detached in this session is no longer offered.'
    );
  }

}
