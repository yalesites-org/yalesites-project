<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_layouts\Access\CloneBlockAccessCheck;
use Drupal\ys_layouts\Service\BlockCloner;

/**
 * Tests the route guard that restricts cloning to inline blocks.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Access\CloneBlockAccessCheck
 *
 * @group yalesites
 * @group ys_layouts
 */
class CloneBlockAccessCheckTest extends UnitTestCase {

  /**
   * The mocked block cloner service.
   *
   * @var \Drupal\ys_layouts\Service\BlockCloner|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $cloner;

  /**
   * The mocked layout tempstore repository.
   *
   * @var \Drupal\layout_builder\LayoutTempstoreRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $repository;

  /**
   * The access check under test.
   *
   * @var \Drupal\ys_layouts\Access\CloneBlockAccessCheck
   */
  protected CloneBlockAccessCheck $accessCheck;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->cloner = $this->createMock(BlockCloner::class);
    $this->repository = $this->createMock(
      LayoutTempstoreRepositoryInterface::class
    );
    $this->accessCheck = new CloneBlockAccessCheck(
      $this->repository,
      $this->cloner
    );
  }

  /**
   * Builds a section storage holding a single component.
   *
   * @param \Drupal\layout_builder\SectionComponent|null $component
   *   The component to place in delta 0, or NULL for an empty layout.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface
   *   The mocked section storage.
   */
  protected function storage(?SectionComponent $component = NULL) {
    $storage = $this->createMock(SectionStorageInterface::class);
    if ($component) {
      $section = new Section('layout_onecol', [], [$component]);
      $storage->method('getSection')->willReturn($section);
    }
    else {
      $storage->method('getSection')
        ->willThrowException(new \OutOfBoundsException('No section.'));
    }
    return $storage;
  }

  /**
   * Access is granted for a clonable inline block in the tempstore layout.
   *
   * @covers ::access
   */
  public function testClonableBlockIsAllowed(): void {
    $component = new SectionComponent('block-uuid', 'content', [
      'id' => 'inline_block:text',
    ]);
    // The saved layout is deliberately empty: only the tempstore copy knows
    // about the block, exactly as when an editor has unsaved changes.
    $saved = $this->storage();
    $this->repository->method('get')->willReturn($this->storage($component));
    $this->cloner->method('isClonable')->willReturn(TRUE);

    $result = $this->accessCheck->access($saved, 0, 'block-uuid');

    $this->assertTrue($result->isAllowed());
    $this->assertSame(0, $result->getCacheMaxAge());
  }

  /**
   * Access is denied for a block the cloner refuses, such as a reusable one.
   *
   * @covers ::access
   */
  public function testNonClonableBlockIsForbidden(): void {
    $component = new SectionComponent('block-uuid', 'content', [
      'id' => 'block_content:some-uuid',
    ]);
    $storage = $this->storage($component);
    $this->repository->method('get')->willReturn($storage);
    $this->cloner->method('isClonable')->willReturn(FALSE);

    $this->assertFalse(
      $this->accessCheck->access($storage, 0, 'block-uuid')->isAllowed()
    );
  }

  /**
   * Access is denied when the component is not in the layout.
   *
   * @covers ::access
   */
  public function testUnknownComponentIsForbidden(): void {
    $component = new SectionComponent('block-uuid', 'content', [
      'id' => 'inline_block:text',
    ]);
    $storage = $this->storage($component);
    $this->repository->method('get')->willReturn($storage);
    $this->cloner->expects($this->never())->method('isClonable');

    $this->assertFalse(
      $this->accessCheck->access($storage, 0, 'other-uuid')->isAllowed()
    );
  }

  /**
   * Access is denied when the section itself does not exist.
   *
   * @covers ::access
   */
  public function testUnknownSectionIsForbidden(): void {
    $storage = $this->storage();
    $this->repository->method('get')->willReturn($storage);
    $this->cloner->expects($this->never())->method('isClonable');

    $this->assertFalse(
      $this->accessCheck->access($storage, 9, 'block-uuid')->isAllowed()
    );
  }

}
