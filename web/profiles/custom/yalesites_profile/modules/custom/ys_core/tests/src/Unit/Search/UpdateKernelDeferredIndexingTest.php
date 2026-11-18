<?php

namespace Drupal\Tests\ys_core\Unit\Search;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Update\UpdateKernel;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\Search\UpdateKernelDeferredIndexing;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests that direct indexing is skipped when running under the update kernel.
 *
 * See \Drupal\ys_core\Search\UpdateKernelDeferredIndexing for why rendering
 * under the update kernel produces empty page bodies, and why deferring to
 * cron is safe.
 *
 * @coversDefaultClass \Drupal\ys_core\Search\UpdateKernelDeferredIndexing
 *
 * @group ys_core
 * @group yalesites
 */
class UpdateKernelDeferredIndexingTest extends UnitTestCase {

  /**
   * Builds the service under test with a given kernel and entity type manager.
   */
  protected function buildService(HttpKernelInterface $kernel, EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger): UpdateKernelDeferredIndexing {
    $service = new UpdateKernelDeferredIndexing($entity_type_manager, $kernel);
    $service->setLogger($logger);
    return $service;
  }

  /**
   * Outside the update kernel the inherited behaviour is left alone.
   *
   * @covers ::destruct
   */
  public function testIndexesDirectlyUnderTheNormalKernel(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn(NULL);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->once())
      ->method('getStorage')
      ->with('search_api_index')
      ->willReturn($storage);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('notice');

    $service = $this->buildService($this->createMock(HttpKernelInterface::class), $entity_type_manager, $logger);
    $service->registerIndexingOperation('node_index', ['entity:node/1:en']);
    $service->destruct();
  }

  /**
   * Under the update kernel nothing is indexed and the deferral is logged.
   *
   * @covers ::destruct
   */
  public function testDefersIndexingUnderTheUpdateKernel(): void {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->never())->method('getStorage');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('notice');

    $service = $this->buildService($this->createMock(UpdateKernel::class), $entity_type_manager, $logger);
    $service->registerIndexingOperation('node_index', ['entity:node/1:en']);
    $service->destruct();
  }

  /**
   * With nothing queued the update kernel path stays silent.
   *
   * @covers ::destruct
   */
  public function testDefersSilentlyWhenNothingIsQueued(): void {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->never())->method('getStorage');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('notice');

    $service = $this->buildService($this->createMock(UpdateKernel::class), $entity_type_manager, $logger);
    $service->destruct();
  }

}
