<?php

namespace Drupal\Tests\ys_content_export\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_content_export\ContentExportBuilder;
use Drupal\ys_content_export\Controller\ContentExportController;
use Drupal\ys_content_export\Controller\ExportControllerBase;

/**
 * Unit tests for the streamed CSV body of ContentExportController.
 *
 * @coversDefaultClass \Drupal\ys_content_export\Controller\ContentExportController
 * @group ys_content_export
 * @group yalesites
 */
class ContentExportControllerTest extends UnitTestCase {

  /**
   * The mocked logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The mocked node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $storage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->with('ys_content_export')->willReturn($this->logger);
    $container = new ContainerBuilder();
    $container->set('logger.factory', $factory);
    \Drupal::setContainer($container);
    $this->storage = $this->createMock(EntityStorageInterface::class);
  }

  /**
   * Builds a page node mock that the real row builder can export.
   *
   * @param int $nid
   *   The node id.
   * @param string $title
   *   The node title.
   * @param bool $access
   *   Whether the current user may view the node.
   * @param string $broken
   *   Where the node throws: 'label' (building the row), 'access' (the access
   *   check), or an empty string for neither.
   *
   * @return \Drupal\node\NodeInterface
   *   The mock.
   */
  protected function node(int $nid, string $title, bool $access = TRUE, string $broken = ''): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($nid);
    if ($broken === 'label') {
      $node->method('label')->willThrowException(new \TypeError('boom'));
    }
    else {
      $node->method('label')->willReturn($title);
    }
    if ($broken === 'access') {
      $node->method('access')->willThrowException(new \RuntimeException('hook broke'));
    }
    else {
      $node->method('access')->with('view')->willReturn($access);
    }
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn('/page-' . $nid);
    $node->method('toUrl')->willReturn($url);
    $node->method('isPublished')->willReturn(TRUE);
    $node->method('hasField')->willReturn(FALSE);
    return $node;
  }

  /**
   * Builds the controller over the mocked node storage.
   *
   * @return \Drupal\ys_content_export\Controller\ContentExportController
   *   The controller.
   */
  protected function controller(): ContentExportController {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('node')->willReturn($this->storage);
    return new ContentExportController($manager, $this->createMock(DateFormatterInterface::class));
  }

  /**
   * Runs the protected writeCsv for the page bundle and parses the output.
   *
   * @param int[] $nids
   *   The nids to export, in order.
   *
   * @return array
   *   The CSV rows, header first.
   */
  protected function exportRows(array $nids): array {
    $columns = array_values(ContentExportBuilder::getColumns('page'));
    $handle = fopen('php://memory', 'w+');
    $build_row = fn($node) => ContentExportBuilder::getRow($node, 'page', $this->createMock(DateFormatterInterface::class));
    (new \ReflectionMethod(ExportControllerBase::class, 'writeCsv'))
      ->invoke($this->controller(), $handle, $this->storage, 'node', $nids, $columns, $build_row);
    rewind($handle);
    $output = stream_get_contents($handle);
    fclose($handle);

    $this->assertStringStartsWith("\xEF\xBB\xBF", $output);
    $lines = array_filter(explode("\n", substr($output, 3)), 'strlen');
    return array_map('str_getcsv', $lines);
  }

  /**
   * Tests a clean export: header, every row, a complete trailing row.
   *
   * @covers \Drupal\ys_content_export\Controller\ExportControllerBase::writeCsv
   */
  public function testAllRowsExported(): void {
    $this->logger->expects($this->never())->method('error');
    $this->storage->method('loadMultiple')->willReturn([1 => $this->node(1, 'A'), 2 => $this->node(2, 'B')]);
    $rows = $this->exportRows([1, 2]);
    $this->assertSame('Title', $rows[0][0]);
    $this->assertSame(['A', '/page-1', 'Yes', 'No'], array_slice($rows[1], 0, 4));
    $this->assertSame(['B', '/page-2', 'Yes', 'No'], array_slice($rows[2], 0, 4));
    $this->assertSame('Export complete: 2 of 2 rows exported (0 failed, 0 skipped)', $rows[3][0]);
    $this->assertCount(4, $rows);
  }

  /**
   * Tests that a throwing row becomes a placeholder and the export continues.
   *
   * @covers \Drupal\ys_content_export\Controller\ExportControllerBase::writeCsv
   */
  public function testFailedRowGetsPlaceholder(): void {
    $this->logger->expects($this->once())->method('error')
      ->with($this->anything(), ['@nid' => 2, '@message' => 'boom']);
    $this->storage->method('loadMultiple')->willReturn([
      1 => $this->node(1, 'A'),
      2 => $this->node(2, 'B', TRUE, 'label'),
      3 => $this->node(3, 'C'),
    ]);
    $rows = $this->exportRows([1, 2, 3]);
    $this->assertCount(count($rows[0]), $rows[2]);
    $this->assertSame('Export failed for node 2', $rows[2][0]);
    $this->assertSame([''], array_unique(array_slice($rows[2], 1)));
    $this->assertSame('C', $rows[3][0]);
    $this->assertSame('Export complete: 2 of 3 rows exported (1 failed, 0 skipped)', $rows[4][0]);
  }

  /**
   * Tests that an access check that throws becomes a bare placeholder row.
   *
   * @covers \Drupal\ys_content_export\Controller\ExportControllerBase::writeCsv
   */
  public function testThrowingAccessCheckGetsPlaceholder(): void {
    $this->logger->expects($this->once())->method('error')
      ->with($this->anything(), ['@nid' => 2, '@message' => 'hook broke']);
    $this->storage->method('loadMultiple')->willReturn([
      1 => $this->node(1, 'A'),
      2 => $this->node(2, 'Hidden title', TRUE, 'access'),
    ]);
    $rows = $this->exportRows([1, 2]);
    $this->assertSame('Export failed for node 2', $rows[2][0]);
    $this->assertSame([''], array_unique(array_slice($rows[2], 1)));
    $this->assertStringNotContainsString('Hidden', json_encode($rows));
    $this->assertSame('Export complete: 1 of 2 rows exported (1 failed, 0 skipped)', $rows[3][0]);
  }

  /**
   * Tests that nodes the user cannot view are skipped without a trace.
   *
   * @covers \Drupal\ys_content_export\Controller\ExportControllerBase::writeCsv
   */
  public function testAccessDeniedNodeIsSkipped(): void {
    $this->logger->expects($this->never())->method('error');
    $this->logger->expects($this->once())->method('notice')
      ->with($this->anything(), ['@count' => 1, '@nids' => '2']);
    $this->storage->method('loadMultiple')->willReturn([
      1 => $this->node(1, 'A'),
      2 => $this->node(2, 'Secret title', FALSE),
    ]);
    $rows = $this->exportRows([1, 2]);
    $this->assertCount(3, $rows);
    $this->assertSame('Export complete: 1 of 2 rows exported (0 failed, 1 skipped)', $rows[2][0]);
    $this->assertStringNotContainsString('Secret', json_encode($rows));
  }

  /**
   * Tests that a nid missing from loadMultiple counts as skipped.
   *
   * @covers \Drupal\ys_content_export\Controller\ExportControllerBase::writeCsv
   */
  public function testMissingNodeIsSkipped(): void {
    $this->logger->expects($this->once())->method('notice')
      ->with($this->anything(), ['@count' => 1, '@nids' => '2']);
    $this->storage->method('loadMultiple')->willReturn([1 => $this->node(1, 'A')]);
    $rows = $this->exportRows([1, 2]);
    $this->assertCount(3, $rows);
    $this->assertSame('Export complete: 1 of 2 rows exported (0 failed, 1 skipped)', $rows[2][0]);
  }

  /**
   * Tests that a chunk that will not load is logged and counted as failed.
   *
   * @covers \Drupal\ys_content_export\Controller\ExportControllerBase::writeCsv
   */
  public function testChunkLoadFailureIsCountedAsFailed(): void {
    $this->logger->expects($this->once())->method('error')
      ->with($this->anything(), ['@type' => 'node', '@ids' => '1, 2', '@message' => 'db gone']);
    $this->storage->method('loadMultiple')->willThrowException(new \RuntimeException('db gone'));
    $rows = $this->exportRows([1, 2]);
    $this->assertCount(2, $rows);
    $this->assertSame('Export complete: 0 of 2 rows exported (2 failed, 0 skipped)', $rows[1][0]);
  }

  /**
   * Tests that a failed chunk does not stop later chunks from exporting.
   *
   * @covers \Drupal\ys_content_export\Controller\ExportControllerBase::writeCsv
   */
  public function testExportContinuesAfterFailedChunk(): void {
    $this->logger->expects($this->once())->method('error');
    $calls = 0;
    $this->storage->method('loadMultiple')->willReturnCallback(function () use (&$calls) {
      if ($calls++ === 0) {
        throw new \RuntimeException('db gone');
      }
      return [51 => $this->node(51, 'Last')];
    });
    $rows = $this->exportRows(range(1, 51));
    $this->assertSame('Last', $rows[1][0]);
    $this->assertSame('Export complete: 1 of 51 rows exported (50 failed, 0 skipped)', $rows[2][0]);
  }

}
