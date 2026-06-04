<?php

namespace Drupal\Tests\ys_ai\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai\Commands\YsAiCommands;
use Drupal\ys_ai\Service\BeaconIndexProvisioner;
use Drupal\ys_ai\Service\BeaconIndexResult;
use Drush\Commands\DrushCommands;
use Psr\Log\AbstractLogger;

/**
 * @coversDefaultClass \Drupal\ys_ai\Commands\YsAiCommands
 *
 * @group yalesites
 */
class YsAiCreateIndexCommandTest extends UnitTestCase {

  /**
   * Builds the command wired to return a fixed provisioning result.
   *
   * @param \Drupal\ys_ai\Service\BeaconIndexResult $result
   *   The result the provisioner should return.
   * @param \Psr\Log\AbstractLogger $logger
   *   The recording logger to attach.
   *
   * @return \Drupal\ys_ai\Commands\YsAiCommands
   *   The command under test.
   */
  protected function command(BeaconIndexResult $result, AbstractLogger $logger): YsAiCommands {
    $provisioner = $this->createMock(BeaconIndexProvisioner::class);
    $provisioner->method('ensureIndexExists')->willReturn($result);
    $command = new YsAiCommands($provisioner);
    $command->setLogger($logger);
    return $command;
  }

  /**
   * Builds a logger that records success() and PSR level calls.
   *
   * @return \Psr\Log\AbstractLogger
   *   The recording logger.
   */
  protected function recordingLogger(): AbstractLogger {
    return new class() extends AbstractLogger {

      /**
       * The names of the logger methods that were called.
       *
       * @var string[]
       */
      public array $calls = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, $message, array $context = []): void {
        $this->calls[] = (string) $level;
      }

      /**
       * Records a Drush "success" message.
       *
       * @param string $message
       *   The message (ignored; only the call is recorded).
       */
      public function success($message): void {
        $this->calls[] = 'success';
      }

    };
  }

  /**
   * @covers ::createIndex
   */
  public function testCreatedReportsSuccess(): void {
    $logger = $this->recordingLogger();
    $command = $this->command(BeaconIndexResult::created('mysite-dev'), $logger);

    $this->assertSame(DrushCommands::EXIT_SUCCESS, $command->createIndex());
    $this->assertContains('success', $logger->calls);
    $this->assertNotContains('error', $logger->calls);
  }

  /**
   * @covers ::createIndex
   */
  public function testExistsReportsSuccess(): void {
    $logger = $this->recordingLogger();
    $command = $this->command(BeaconIndexResult::alreadyExists('mysite-dev'), $logger);

    $this->assertSame(DrushCommands::EXIT_SUCCESS, $command->createIndex());
    $this->assertContains('success', $logger->calls);
    $this->assertNotContains('error', $logger->calls);
  }

  /**
   * @covers ::createIndex
   */
  public function testFailedReportsError(): void {
    $logger = $this->recordingLogger();
    $command = $this->command(BeaconIndexResult::failed('Beacon search server is not configured.'), $logger);

    $this->assertSame(DrushCommands::EXIT_FAILURE, $command->createIndex());
    $this->assertContains('error', $logger->calls);
    $this->assertNotContains('success', $logger->calls);
  }

}
