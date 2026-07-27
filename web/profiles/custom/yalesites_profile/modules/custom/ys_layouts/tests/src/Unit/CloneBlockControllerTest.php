<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_layouts\Controller\CloneBlockController;
use Drupal\ys_layouts\Service\BlockCloner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the controller behind the "Clone block" contextual link.
 *
 * Covers the guarantees the editor actually sees: the copy lands directly
 * after the original, the layout tempstore is written so the copy survives to
 * the next AJAX step, the change is announced to assistive technology, and
 * anything that must not be cloned is refused rather than half-cloned.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Controller\CloneBlockController
 *
 * @group yalesites
 * @group ys_layouts
 */
class CloneBlockControllerTest extends UnitTestCase {

  /**
   * The mocked layout tempstore repository.
   *
   * @var \Drupal\layout_builder\LayoutTempstoreRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $tempstore;

  /**
   * The mocked block cloner service.
   *
   * @var \Drupal\ys_layouts\Service\BlockCloner|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $cloner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->tempstore = $this->createMock(
      LayoutTempstoreRepositoryInterface::class
    );
    $this->cloner = $this->createMock(BlockCloner::class);
  }

  /**
   * Builds the controller under test.
   *
   * Core's LayoutRebuildTrait::rebuildLayout() renders the whole Layout Builder
   * element through the renderer, which needs a bootstrapped site. It is core's
   * own tested code, so it is stubbed out here while the section storage it was
   * handed is recorded for assertion.
   *
   * @return \Drupal\ys_layouts\Controller\CloneBlockController
   *   The controller.
   */
  protected function buildController(): CloneBlockController {
    $controller = new class($this->tempstore, $this->cloner) extends CloneBlockController {

      /**
       * The section storage the layout was rebuilt from.
       *
       * @var \Drupal\layout_builder\SectionStorageInterface
       */
      public $rebuiltFrom;

      /**
       * {@inheritdoc}
       */
      protected function rebuildLayout(
        SectionStorageInterface $section_storage,
      ) {
        $this->rebuiltFrom = $section_storage;
        return new AjaxResponse();
      }

    };
    $controller->setStringTranslation($this->getStringTranslationStub());

    return $controller;
  }

  /**
   * Builds a section storage holding one section with three inline blocks.
   *
   * @param \Drupal\layout_builder\Section|null $section
   *   Receives the section, so tests can inspect the component order.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The section storage.
   */
  protected function buildSectionStorage(?Section &$section = NULL) {
    $components = [];
    foreach (['first', 'second', 'third'] as $weight => $uuid) {
      $component = new SectionComponent(
        $uuid,
        'content',
        ['id' => 'inline_block:content_spotlight']
      );
      $component->setWeight($weight);
      $components[] = $component;
    }
    $section = new Section('layout_onecol', [], $components);

    $storage = $this->createMock(SectionStorageInterface::class);
    $storage->method('getSection')->willReturn($section);

    return $storage;
  }

  /**
   * The copy is inserted directly after the original and persisted.
   *
   * @covers ::build
   */
  public function testCloneLandsDirectlyAfterTheOriginal(): void {
    $storage = $this->buildSectionStorage($section);
    $clone = new SectionComponent(
      'clone',
      'content',
      ['id' => 'inline_block:content_spotlight']
    );

    $this->cloner->method('isClonable')->willReturn(TRUE);
    $this->cloner->expects($this->once())
      ->method('cloneComponent')
      ->willReturn($clone);
    // Without this the copy is gone by the next AJAX request.
    $this->tempstore->expects($this->once())
      ->method('set')
      ->with($storage);

    $controller = $this->buildController();
    $response = $controller->build($storage, 0, 'content', 'first');

    $this->assertSame(
      ['first', 'clone', 'second', 'third'],
      array_keys($section->getComponentsByRegion('content'))
    );
    $this->assertSame($storage, $controller->rebuiltFrom);
    $this->assertInstanceOf(AjaxResponse::class, $response);
  }

  /**
   * A middle block is cloned into its own region, not appended to the end.
   *
   * @covers ::build
   */
  public function testCloneOfMiddleBlockKeepsRegionOrder(): void {
    $storage = $this->buildSectionStorage($section);
    $clone = new SectionComponent(
      'clone',
      'content',
      ['id' => 'inline_block:content_spotlight']
    );

    $this->cloner->method('isClonable')->willReturn(TRUE);
    $this->cloner->method('cloneComponent')->willReturn($clone);

    $this->buildController()->build($storage, 0, 'content', 'second');

    $this->assertSame(
      ['first', 'second', 'clone', 'third'],
      array_keys($section->getComponentsByRegion('content'))
    );
  }

  /**
   * Cloning is announced, because the whole layout is silently replaced.
   *
   * @covers ::build
   */
  public function testCloningIsAnnounced(): void {
    $storage = $this->buildSectionStorage($section);
    $this->cloner->method('isClonable')->willReturn(TRUE);
    $this->cloner->method('cloneComponent')->willReturn(
      new SectionComponent('clone', 'content', ['id' => 'inline_block:text'])
    );

    $response = $this->buildController()
      ->build($storage, 0, 'content', 'first');

    $announcements = array_filter(
      $response->getCommands(),
      fn (array $command) => $command['command'] === 'announce'
    );
    $this->assertCount(1, $announcements);
    $this->assertStringContainsString(
      'Block cloned',
      (string) reset($announcements)['text']
    );
  }

  /**
   * A component that must not be cloned is refused, even by URL.
   *
   * @covers ::build
   */
  public function testNonClonableComponentIsDenied(): void {
    $storage = $this->buildSectionStorage($section);
    $this->cloner->method('isClonable')->willReturn(FALSE);
    $this->cloner->expects($this->never())->method('cloneComponent');
    $this->tempstore->expects($this->never())->method('set');

    $this->expectException(AccessDeniedHttpException::class);
    $this->buildController()->build($storage, 0, 'content', 'first');
  }

  /**
   * An unknown component UUID is a 404, not a broken layout.
   *
   * @covers ::build
   */
  public function testUnknownComponentIsNotFound(): void {
    $storage = $this->buildSectionStorage($section);
    $this->tempstore->expects($this->never())->method('set');

    $this->expectException(NotFoundHttpException::class);
    $this->buildController()->build($storage, 0, 'content', 'nope');
  }

  /**
   * A missing section delta is a 404.
   *
   * @covers ::build
   */
  public function testMissingSectionIsNotFound(): void {
    $storage = $this->createMock(SectionStorageInterface::class);
    $storage->method('getSection')
      ->willThrowException(new \OutOfBoundsException('No section.'));
    $this->tempstore->expects($this->never())->method('set');

    $this->expectException(NotFoundHttpException::class);
    $this->buildController()->build($storage, 7, 'content', 'first');
  }

  /**
   * A block whose content cannot be copied is a bad request, not a 500.
   *
   * @covers ::build
   */
  public function testUncopyableBlockIsBadRequest(): void {
    $storage = $this->buildSectionStorage($section);
    $this->cloner->method('isClonable')->willReturn(TRUE);
    $this->cloner->method('cloneComponent')
      ->willThrowException(new \InvalidArgumentException('No content.'));
    $this->tempstore->expects($this->never())->method('set');

    $this->expectException(BadRequestHttpException::class);
    $this->buildController()->build($storage, 0, 'content', 'first');
  }

}
