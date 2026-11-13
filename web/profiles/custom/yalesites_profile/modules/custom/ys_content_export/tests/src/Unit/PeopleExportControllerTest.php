<?php

namespace Drupal\Tests\ys_content_export\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Drupal\ys_content_export\Controller\ExportControllerBase;
use Drupal\ys_content_export\Controller\PeopleExportController;

/**
 * Unit tests for the streamed CSV body of PeopleExportController.
 *
 * @coversDefaultClass \Drupal\ys_content_export\Controller\PeopleExportController
 * @group ys_content_export
 * @group yalesites
 */
class PeopleExportControllerTest extends UnitTestCase {

  /**
   * The mocked logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The mocked user storage.
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
   * Streams the People export for the given users and parses the output.
   *
   * @param \Drupal\user\UserInterface[] $users
   *   The users to export, keyed by uid.
   * @param callable|null $build_row
   *   Optional row builder, to simulate a failing row.
   *
   * @return array
   *   The CSV rows, header first.
   */
  protected function exportRows(array $users, ?callable $build_row = NULL): array {
    $this->storage->method('loadMultiple')->willReturn($users);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('user')->willReturn($this->storage);
    $controller = new PeopleExportController($manager, $this->createMock(DateFormatterInterface::class));

    $handle = fopen('php://memory', 'w+');
    $build_row ??= fn($user) => ['netid' . $user->id(), 'Active'];
    (new \ReflectionMethod(ExportControllerBase::class, 'writeCsv'))
      ->invoke($controller, $handle, $this->storage, 'user', array_keys($users), ['NetID', 'Status'], $build_row);
    rewind($handle);
    $output = stream_get_contents($handle);
    fclose($handle);

    $lines = array_filter(explode("\n", substr($output, 3)), 'strlen');
    return array_map('str_getcsv', $lines);
  }

  /**
   * Builds a viewable user mock.
   */
  protected function user(int $uid): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn($uid);
    $user->method('access')->with('view')->willReturn(TRUE);
    return $user;
  }

  /**
   * Tests the last row of a People export is an account, not a status line.
   *
   * @covers \Drupal\ys_content_export\Controller\ExportControllerBase::writeCsv
   */
  public function testLastRowIsAnAccount(): void {
    $rows = $this->exportRows([1 => $this->user(1), 2 => $this->user(2)]);
    $this->assertCount(3, $rows);
    $this->assertSame(['NetID', 'Status'], $rows[0]);
    $this->assertSame(['netid2', 'Active'], $rows[2]);
    $this->assertStringNotContainsString('Export complete', json_encode($rows));
  }

  /**
   * Tests a failed row is still reported outside the file, in the log.
   *
   * @covers \Drupal\ys_content_export\Controller\ExportControllerBase::writeCsv
   */
  public function testFailedRowIsLogged(): void {
    $this->logger->expects($this->atLeastOnce())->method('error');
    $rows = $this->exportRows(
      [1 => $this->user(1), 2 => $this->user(2)],
      fn($user) => $user->id() === 2 ? throw new \RuntimeException('boom') : ['netid1', 'Active']
    );
    $this->assertSame('Export failed for user 2', $rows[2][0]);
    $this->assertCount(3, $rows);
  }

}
