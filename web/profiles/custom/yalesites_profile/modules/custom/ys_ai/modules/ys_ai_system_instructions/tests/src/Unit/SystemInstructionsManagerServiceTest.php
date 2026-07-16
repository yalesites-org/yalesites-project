<?php

namespace Drupal\Tests\ys_ai_system_instructions\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_system_instructions\Service\SystemInstructionsApiService;
use Drupal\ys_ai_system_instructions\Service\SystemInstructionsManagerService;
use Drupal\ys_ai_system_instructions\Service\SystemInstructionsStorageService;
use Drupal\ys_ai_system_instructions\Service\TextFormatDetectionService;

/**
 * Unit tests for SystemInstructionsManagerService.
 *
 * This service orchestrates the API and storage services; both are mocked
 * here so the tests exercise only the manager's own branching (cooldown,
 * diffing, and success/failure propagation).
 *
 * @coversDefaultClass \Drupal\ys_ai_system_instructions\Service\SystemInstructionsManagerService
 * @group ys_ai_system_instructions
 * @group ys_ai
 * @group yalesites
 */
class SystemInstructionsManagerServiceTest extends UnitTestCase {

  /**
   * The mocked API service.
   *
   * @var \Drupal\ys_ai_system_instructions\Service\SystemInstructionsApiService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $apiService;

  /**
   * The mocked storage service.
   *
   * @var \Drupal\ys_ai_system_instructions\Service\SystemInstructionsStorageService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $storageService;

  /**
   * The mocked logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerChannel;

  /**
   * The mocked key-value store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $keyValueStore;

  /**
   * The mocked time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $time;

  /**
   * The mocked current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * The current request time used by the time mock.
   *
   * @var int
   */
  protected $requestTime = 1000;

  /**
   * The last sync time returned by the key-value store mock.
   *
   * @var int
   */
  protected $lastSyncTime = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->apiService = $this->createMock(SystemInstructionsApiService::class);
    $this->storageService = $this->createMock(SystemInstructionsStorageService::class);

    $this->loggerChannel = $this->createMock(LoggerChannelInterface::class);

    $this->keyValueStore = $this->createMock(KeyValueStoreInterface::class);
    $this->keyValueStore->method('get')->willReturnCallback(
      fn () => $this->lastSyncTime
    );

    $this->time = $this->createMock(TimeInterface::class);
    $this->time->method('getRequestTime')->willReturnCallback(fn () => $this->requestTime);

    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->currentUser->method('id')->willReturn(5);
    $this->currentUser->method('getDisplayName')->willReturn('Test User');
  }

  /**
   * Builds the manager under test with mocked constructor dependencies.
   */
  protected function createManager(): SystemInstructionsManagerService {
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->with('ys_ai_system_instructions')->willReturn($this->loggerChannel);

    $key_value_factory = $this->createMock(KeyValueFactoryInterface::class);
    $key_value_factory->method('get')->with('ys_ai_system_instructions')->willReturn($this->keyValueStore);

    $manager = new SystemInstructionsManagerService(
      $this->apiService,
      $this->storageService,
      $logger_factory,
      $key_value_factory,
      $this->time,
      // The real formatting service is used: it is pure logic, already
      // covered by TextFormatDetectionServiceTest, and mocking it would just
      // restate its behavior instead of exercising the manager's own
      // branching.
      new TextFormatDetectionService(),
      $this->currentUser
    );
    // The manager uses $this->t() to format status messages; give it a
    // translation stub so those calls don't reach an uninitialized container.
    $manager->setStringTranslation($this->getStringTranslationStub());
    return $manager;
  }

  /**
   * Tests syncFromApi() skips the API call within the cooldown window.
   *
   * @covers ::syncFromApi
   */
  public function testSyncFromApiSkipsWhenWithinCooldown(): void {
    // 5 seconds ago, cooldown is 10 seconds.
    $this->lastSyncTime = $this->requestTime - 5;

    $this->apiService->expects($this->never())->method('getSystemInstructions');
    $this->keyValueStore->expects($this->never())->method('set');
    $this->storageService->method('getActiveInstructions')->willReturn(['version' => 3]);

    $result = $this->createManager()->syncFromApi();

    $this->assertTrue($result['success']);
    $this->assertTrue($result['skipped']);
    $this->assertSame(3, $result['version']);
    $this->assertStringContainsString('5 more seconds', (string) $result['message']);
  }

  /**
   * Tests syncFromApi() bypasses the cooldown when forced.
   *
   * @covers ::syncFromApi
   */
  public function testSyncFromApiForceBypassesCooldown(): void {
    $this->lastSyncTime = $this->requestTime - 1;

    $this->keyValueStore->expects($this->once())
      ->method('set')
      ->with('last_api_sync_time', $this->requestTime);
    $this->apiService->expects($this->once())
      ->method('getSystemInstructions')
      ->willReturn(['success' => FALSE, 'data' => '', 'error' => 'boom']);
    $this->storageService->method('getActiveInstructions')->willReturn(NULL);

    $result = $this->createManager()->syncFromApi(TRUE);

    $this->assertFalse($result['success']);
  }

  /**
   * Tests syncFromApi() when the API call itself fails.
   *
   * @covers ::syncFromApi
   */
  public function testSyncFromApiReturnsLocalFallbackOnApiFailure(): void {
    $this->apiService->method('getSystemInstructions')
      ->willReturn(['success' => FALSE, 'data' => '', 'error' => 'Connection refused']);
    $this->storageService->method('getActiveInstructions')->willReturn(['version' => 2]);
    $this->storageService->expects($this->never())->method('createVersion');

    $this->loggerChannel->expects($this->once())->method('warning');

    $result = $this->createManager()->syncFromApi();

    $this->assertFalse($result['success']);
    $this->assertTrue($result['local_success']);
    $this->assertFalse($result['api_success']);
    $this->assertSame(2, $result['version']);
    $this->assertStringContainsString('Connection refused', $result['message']);
  }

  /**
   * Tests syncFromApi() when the API instructions match the active version.
   *
   * @covers ::syncFromApi
   */
  public function testSyncFromApiSkipsVersionCreationWhenUnchanged(): void {
    $this->apiService->method('getSystemInstructions')
      ->willReturn(['success' => TRUE, 'data' => 'Same instructions', 'error' => '']);
    $this->storageService->method('areInstructionsDifferent')->willReturn(FALSE);
    $this->storageService->method('getActiveInstructions')->willReturn(['version' => 4]);
    $this->storageService->expects($this->never())->method('createVersion');

    $result = $this->createManager()->syncFromApi();

    $this->assertTrue($result['success']);
    $this->assertSame('Instructions are already up to date.', $result['message']);
    $this->assertSame(4, $result['version']);
  }

  /**
   * Tests syncFromApi() creates a new version when the API data differs.
   *
   * @covers ::syncFromApi
   */
  public function testSyncFromApiCreatesNewVersionWhenChanged(): void {
    $this->apiService->method('getSystemInstructions')
      ->willReturn(['success' => TRUE, 'data' => 'Updated instructions', 'error' => '']);
    $this->storageService->method('areInstructionsDifferent')->willReturn(TRUE);
    $this->storageService->expects($this->once())
      ->method('createVersion')
      ->with('Updated instructions', 'Synced from API', 1)
      ->willReturn(7);

    $this->loggerChannel->expects($this->once())->method('info');

    $result = $this->createManager()->syncFromApi();

    $this->assertTrue($result['success']);
    $this->assertSame(7, $result['version']);
    $this->assertStringContainsString('New version: 7', $result['message']);
  }

  /**
   * Tests saveInstructions() when nothing has changed.
   *
   * @covers ::saveInstructions
   */
  public function testSaveInstructionsSkipsWhenUnchanged(): void {
    $this->storageService->method('areInstructionsDifferent')->willReturn(FALSE);
    $this->storageService->method('getActiveInstructions')->willReturn(['version' => 6]);
    $this->storageService->expects($this->never())->method('createVersion');
    $this->apiService->expects($this->never())->method('setSystemInstructions');

    $result = $this->createManager()->saveInstructions('Same text');

    $this->assertTrue($result['success']);
    $this->assertSame('No changes detected. Instructions not saved.', $result['message']);
    $this->assertSame(6, $result['version']);
  }

  /**
   * Tests saveInstructions() persists locally and pushes to the API.
   *
   * @covers ::saveInstructions
   */
  public function testSaveInstructionsPersistsAndSyncsToApi(): void {
    $this->storageService->method('areInstructionsDifferent')->willReturn(TRUE);
    $this->storageService->expects($this->once())
      ->method('createVersion')
      ->with('New text', 'my notes')
      ->willReturn(9);
    $this->apiService->expects($this->once())
      ->method('setSystemInstructions')
      ->willReturn(['success' => TRUE, 'error' => '']);

    $result = $this->createManager()->saveInstructions('New text', 'my notes');

    $this->assertTrue($result['success']);
    $this->assertSame(9, $result['version']);
    $this->assertStringContainsString('Version: 9', $result['message']);
  }

  /**
   * Tests saveInstructions() reports the local save when the API push fails.
   *
   * @covers ::saveInstructions
   */
  public function testSaveInstructionsReportsApiFailureAfterLocalSave(): void {
    $this->storageService->method('areInstructionsDifferent')->willReturn(TRUE);
    $this->storageService->method('createVersion')->willReturn(10);
    $this->apiService->method('setSystemInstructions')
      ->willReturn(['success' => FALSE, 'error' => 'API down']);

    $this->loggerChannel->expects($this->once())->method('error');

    $result = $this->createManager()->saveInstructions('New text');

    $this->assertFalse($result['success']);
    $this->assertTrue($result['local_success']);
    $this->assertFalse($result['api_success']);
    $this->assertSame(10, $result['version']);
    $this->assertStringContainsString('API down', $result['message']);
  }

  /**
   * Tests getCurrentInstructions() when there is no active version.
   *
   * @covers ::getCurrentInstructions
   */
  public function testGetCurrentInstructionsWithNoActiveVersion(): void {
    $this->apiService->method('getSystemInstructions')
      ->willReturn(['success' => FALSE, 'data' => '', 'error' => 'API configuration is incomplete.']);
    $this->storageService->method('getActiveInstructions')->willReturn(NULL);

    $result = $this->createManager()->getCurrentInstructions();

    $this->assertSame('', $result['instructions']);
    $this->assertSame(0, $result['version']);
    $this->assertFalse($result['synced']);
    $this->assertNotEmpty($result['sync_error']);
  }

  /**
   * Tests getCurrentInstructions() formats and returns the active version.
   *
   * @covers ::getCurrentInstructions
   */
  public function testGetCurrentInstructionsWithActiveVersion(): void {
    $this->apiService->method('getSystemInstructions')
      ->willReturn(['success' => FALSE, 'data' => '', 'error' => 'API configuration is incomplete.']);
    $this->storageService->method('getActiveInstructions')->willReturn([
      'version' => '3',
      'instructions' => 'Plain text instructions.',
    ]);

    $result = $this->createManager()->getCurrentInstructions();

    $this->assertSame(3, $result['version']);
    $this->assertStringContainsString('Plain text instructions.', $result['instructions']);
  }

  /**
   * Tests revertToVersion() when the target version does not exist.
   *
   * @covers ::revertToVersion
   */
  public function testRevertToVersionFailsWhenVersionNotFound(): void {
    $this->storageService->method('getVersion')->with(99)->willReturn(NULL);
    $this->storageService->expects($this->never())->method('setActiveVersion');

    $this->loggerChannel->expects($this->once())->method('warning');

    $result = $this->createManager()->revertToVersion(99);

    $this->assertFalse($result['success']);
    $this->assertSame('Version 99 not found.', $result['message']);
  }

  /**
   * Tests revertToVersion() activates the version and pushes it to the API.
   *
   * @covers ::revertToVersion
   */
  public function testRevertToVersionSucceedsAndSyncsToApi(): void {
    $this->storageService->method('getVersion')->with(2)->willReturn([
      'instructions' => 'Old instructions',
    ]);
    $this->storageService->expects($this->once())->method('setActiveVersion')->with(2);
    $this->apiService->expects($this->once())
      ->method('setSystemInstructions')
      ->willReturn(['success' => TRUE, 'error' => '']);

    $result = $this->createManager()->revertToVersion(2);

    $this->assertTrue($result['success']);
    $this->assertSame('Successfully reverted to version 2', $result['message']);
  }

  /**
   * Tests revertToVersion() reports the API failure after the local revert.
   *
   * @covers ::revertToVersion
   */
  public function testRevertToVersionReportsApiFailureAfterLocalRevert(): void {
    $this->storageService->method('getVersion')->willReturn([
      'instructions' => 'Old instructions',
    ]);
    $this->storageService->method('setActiveVersion');
    $this->apiService->method('setSystemInstructions')
      ->willReturn(['success' => FALSE, 'error' => 'API down']);

    $this->loggerChannel->expects($this->once())->method('error');

    $result = $this->createManager()->revertToVersion(2);

    $this->assertFalse($result['success']);
    $this->assertStringContainsString('API down', $result['message']);
  }

  /**
   * Tests getAllVersions() delegates to the storage service.
   *
   * @covers ::getAllVersions
   */
  public function testGetAllVersionsDelegatesToStorageService(): void {
    $this->storageService->expects($this->once())
      ->method('getAllVersions')
      ->with(TRUE, 10)
      ->willReturn([['version' => 1]]);

    $result = $this->createManager()->getAllVersions(TRUE, 10);

    $this->assertSame([['version' => 1]], $result);
  }

  /**
   * Tests getVersionStats() when an active version exists.
   *
   * @covers ::getVersionStats
   */
  public function testGetVersionStatsWithActiveVersion(): void {
    $this->storageService->method('getActiveInstructions')->willReturn([
      'version' => '8',
      'created_date' => '12345',
    ]);
    $this->storageService->method('getVersionCount')->willReturn(8);

    $result = $this->createManager()->getVersionStats();

    $this->assertSame([
      'total_versions' => 8,
      'active_version' => 8,
      'active_created' => 12345,
    ], $result);
  }

  /**
   * Tests getVersionStats() when there is no active version.
   *
   * @covers ::getVersionStats
   */
  public function testGetVersionStatsWithNoActiveVersion(): void {
    $this->storageService->method('getActiveInstructions')->willReturn(NULL);
    $this->storageService->method('getVersionCount')->willReturn(0);

    $result = $this->createManager()->getVersionStats();

    $this->assertSame([
      'total_versions' => 0,
      'active_version' => 0,
      'active_created' => 0,
    ], $result);
  }

  /**
   * Tests getStorageService() returns the injected storage service.
   *
   * @covers ::getStorageService
   */
  public function testGetStorageServiceReturnsInjectedInstance(): void {
    $this->assertSame($this->storageService, $this->createManager()->getStorageService());
  }

}
