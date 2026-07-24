<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\search_api\IndexInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\BeaconIndexManager;
use Drupal\ys_beacon\Service\BeaconMigration;
use Psr\Log\LoggerInterface;

/**
 * Tests the legacy ai_engine to Beacon cutover service.
 *
 * The branch logic is exercised with array-backed config doubles (so set/get
 * behave like real config) and a mocked index manager, keeping every Azure call
 * out of the test. This covers the decision table - skip, no-op, full cutover,
 * provisioning deferral, index-entity deferral, and self-heal - without the
 * heavy search_api/ai dependency graph; the live provisioning round trip is
 * verified on a multidev with real credentials.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\BeaconMigration
 */
class BeaconMigrationTest extends UnitTestCase {

  /**
   * Builds an array-backed Config double.
   *
   * @param array $data
   *   The backing data, passed by reference so set() mutations are observable.
   * @param bool $is_new
   *   Whether the config object reports itself as new (not yet created).
   *
   * @return \Drupal\Core\Config\Config
   *   The config double.
   */
  private function config(array &$data, bool $is_new = FALSE): Config {
    $config = $this->createMock(Config::class);
    $config->method('isNew')->willReturn($is_new);
    $config->method('get')->willReturnCallback(fn ($key = '') => $data[$key] ?? NULL);
    $config->method('getOriginal')->willReturnCallback(fn ($key = '') => $data[$key] ?? NULL);
    $config->method('set')->willReturnCallback(function ($key, $value) use (&$data, $config) {
      $data[$key] = $value;
      return $config;
    });
    $config->method('save')->willReturn($config);
    return $config;
  }

  /**
   * Builds a config factory returning the given config doubles by name.
   *
   * @param array $configs
   *   Map of config name to Config double. Both get() and getEditable() return
   *   the same double for a name.
   */
  private function factory(array $configs): ConfigFactoryInterface {
    $resolve = function ($name) use ($configs) {
      $this->assertArrayHasKey($name, $configs, "Unexpected config name: $name");
      return $configs[$name];
    };
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnCallback($resolve);
    $factory->method('getEditable')->willReturnCallback($resolve);
    return $factory;
  }

  /**
   * Builds an entity type manager whose search_api_index storage loads $index.
   */
  private function entityTypeManager(?IndexInterface $index, bool $expect_load = TRUE): EntityTypeManagerInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($expect_load ? $this->once() : $this->never())
      ->method('load')
      ->with('ys_beacon')
      ->willReturn($index);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('search_api_index')->willReturn($storage);
    return $etm;
  }

  /**
   * Builds a module handler reporting the given modules as installed.
   */
  private function moduleHandler(array $installed = ['ai_engine_chat', 'ai_engine_embedding']): ModuleHandlerInterface {
    $handler = $this->createMock(ModuleHandlerInterface::class);
    $handler->method('moduleExists')->willReturnCallback(fn ($name) => in_array($name, $installed, TRUE));
    return $handler;
  }

  /**
   * Settings object not created yet: returns before touching anything else.
   *
   * @covers ::migrate
   */
  public function testSkipsWhenSettingsNotCreated(): void {
    $beacon = [];
    $factory = $this->factory(['ys_beacon.settings' => $this->config($beacon, TRUE)]);
    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->never())->method('provision');

    $migration = new BeaconMigration(
      $factory,
      $this->moduleHandler(),
      $index_manager,
      $this->entityTypeManager(NULL, FALSE),
      $this->createMock(LoggerInterface::class),
    );
    $migration->migrate();
  }

  /**
   * Legacy chat off and Beacon off: nothing to enable; ai_engine already off.
   *
   * The migration still runs the ai_engine teardown on this path, but every
   * legacy flag is already off, so no config is written.
   *
   * @covers ::migrate
   */
  public function testNoOpWhenLegacyOffAndBeaconOff(): void {
    $beacon = ['enable_chat' => FALSE, 'search_index_id' => 'ys_beacon'];
    $chat = ['enable' => FALSE];
    $embedding = ['enable' => FALSE];
    $factory = $this->factory([
      'ys_beacon.settings' => $this->config($beacon),
      'ai_engine_chat.settings' => $this->config($chat),
      'ai_engine_embedding.settings' => $this->config($embedding),
    ]);
    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->never())->method('provision');

    $migration = new BeaconMigration(
      $factory,
      $this->moduleHandler(),
      $index_manager,
      $this->entityTypeManager(NULL, FALSE),
      $this->createMock(LoggerInterface::class),
    );
    $migration->migrate();

    $this->assertFalse($beacon['enable_chat'], 'Beacon chat stays off.');
    $this->assertFalse($chat['enable'], 'Legacy chat is left untouched.');
    $this->assertFalse($embedding['enable'], 'Legacy embedding is left untouched.');
  }

  /**
   * Cutover already complete: Beacon on, index named, legacy off: no-op.
   *
   * @covers ::migrate
   */
  public function testNoOpWhenAlreadyMigrated(): void {
    $beacon = [
      'enable_chat' => TRUE,
      'azure_index_name' => 'somesite-live',
      'enable_metadata_fields' => TRUE,
      'search_index_id' => 'ys_beacon',
    ];
    $chat = ['enable' => FALSE];
    $embedding = ['enable' => FALSE];
    $factory = $this->factory([
      'ys_beacon.settings' => $this->config($beacon),
      'ai_engine_chat.settings' => $this->config($chat),
      'ai_engine_embedding.settings' => $this->config($embedding),
    ]);
    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->never())->method('provision');

    $migration = new BeaconMigration(
      $factory,
      $this->moduleHandler(),
      $index_manager,
      // The index is never loaded once the cutover is complete.
      $this->entityTypeManager(NULL, FALSE),
      $this->createMock(LoggerInterface::class),
    );
    $migration->migrate();

    $this->assertTrue($beacon['enable_chat'], 'Beacon stays enabled.');
    $this->assertFalse($chat['enable'], 'Legacy chat stays off.');
  }

  /**
   * Legacy chat on with a reachable Azure: full cutover in one run.
   *
   * @covers ::migrate
   */
  public function testFullCutoverWhenLegacyOn(): void {
    $beacon = [
      'enable_chat' => FALSE,
      'enable_metadata_fields' => FALSE,
      'azure_index_name' => '',
      'search_index_id' => 'ys_beacon',
    ];
    $chat = ['enable' => TRUE, 'floating_button' => TRUE];
    $embedding = ['enable' => TRUE];
    $metadata = ['enable' => TRUE];
    $factory = $this->factory([
      'ys_beacon.settings' => $this->config($beacon),
      'ai_engine_chat.settings' => $this->config($chat),
      'ai_engine_embedding.settings' => $this->config($embedding),
      'ai_engine_metadata.settings' => $this->config($metadata),
    ]);

    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->once())->method('provision');

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(FALSE);
    $index->expects($this->once())->method('setStatus')->with(TRUE)->willReturnSelf();
    $index->expects($this->once())->method('save');
    // The tracker is rebuilt (not just re-flagged) so existing content is
    // enumerated into a freshly enabled index (issue #1383).
    $index->expects($this->once())->method('rebuildTracker');
    $index->expects($this->never())->method('reindex');

    $migration = new BeaconMigration(
      $factory,
      $this->moduleHandler(['ai_engine_chat', 'ai_engine_embedding', 'ai_engine_metadata']),
      $index_manager,
      $this->entityTypeManager($index),
      $this->createMock(LoggerInterface::class),
    );
    $migration->migrate();

    $this->assertTrue($beacon['enable_chat'], 'Beacon chat is enabled.');
    $this->assertTrue($beacon['enable_metadata_fields'], 'AI metadata fields are forced on with chat.');
    $this->assertFalse($chat['enable'], 'Legacy chat widget is disabled.');
    $this->assertFalse($chat['floating_button'], 'Legacy floating button is disabled.');
    $this->assertFalse($embedding['enable'], 'Legacy embedding is disabled.');
    $this->assertFalse($metadata['enable'], 'Legacy ai_engine metadata is disabled.');
  }

  /**
   * Provisioning failure defers: intent recorded, legacy left serving.
   *
   * @covers ::migrate
   */
  public function testDefersWhenProvisionFails(): void {
    $beacon = [
      'enable_chat' => FALSE,
      'enable_metadata_fields' => FALSE,
      'azure_index_name' => '',
      'search_index_id' => 'ys_beacon',
    ];
    $chat = ['enable' => TRUE, 'floating_button' => TRUE];
    $factory = $this->factory([
      'ys_beacon.settings' => $this->config($beacon),
      'ai_engine_chat.settings' => $this->config($chat),
    ]);

    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->once())
      ->method('provision')
      ->willThrowException(new \RuntimeException('Azure unreachable.'));

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('notice');

    $migration = new BeaconMigration(
      $factory,
      $this->moduleHandler(),
      $index_manager,
      // The index is never loaded when provisioning is deferred.
      $this->entityTypeManager(NULL, FALSE),
      $logger,
    );
    $migration->migrate();

    $this->assertTrue($beacon['enable_chat'], 'Intent is recorded even when provisioning is deferred.');
    $this->assertTrue($chat['enable'], 'Legacy chat keeps serving until Beacon is ready.');
  }

  /**
   * Index entity not deployed yet: provision happened, teardown deferred.
   *
   * @covers ::migrate
   */
  public function testDefersWhenIndexEntityMissing(): void {
    $beacon = [
      'enable_chat' => TRUE,
      'enable_metadata_fields' => TRUE,
      'azure_index_name' => 'somesite-live',
      'search_index_id' => 'ys_beacon',
    ];
    $chat = ['enable' => TRUE, 'floating_button' => TRUE];
    $factory = $this->factory([
      'ys_beacon.settings' => $this->config($beacon),
      'ai_engine_chat.settings' => $this->config($chat),
    ]);

    $index_manager = $this->createMock(BeaconIndexManager::class);
    // Index name already set, so provisioning is not retried.
    $index_manager->expects($this->never())->method('provision');

    $migration = new BeaconMigration(
      $factory,
      $this->moduleHandler(),
      $index_manager,
      // Storage returns NULL: the index config has not been imported yet.
      $this->entityTypeManager(NULL),
      $this->createMock(LoggerInterface::class),
    );
    $migration->migrate();

    $this->assertTrue($chat['enable'], 'Legacy chat is not torn down until the index exists.');
  }

  /**
   * Beacon enabled via the form but never provisioned: cron self-heals it.
   *
   * @covers ::migrate
   */
  public function testHealsFormEnabledButUnprovisioned(): void {
    $beacon = [
      'enable_chat' => TRUE,
      'enable_metadata_fields' => TRUE,
      'azure_index_name' => '',
      'search_index_id' => 'ys_beacon',
    ];
    // Legacy chat already off (the form requires that before enabling Beacon).
    $chat = ['enable' => FALSE, 'floating_button' => FALSE];
    $embedding = ['enable' => FALSE];
    $factory = $this->factory([
      'ys_beacon.settings' => $this->config($beacon),
      'ai_engine_chat.settings' => $this->config($chat),
      'ai_engine_embedding.settings' => $this->config($embedding),
    ]);

    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->once())->method('provision');

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    // Already enabled, so the status is not rewritten; the tracker is rebuilt.
    $index->expects($this->never())->method('setStatus');
    $index->expects($this->once())->method('rebuildTracker');
    $index->expects($this->never())->method('reindex');

    $migration = new BeaconMigration(
      $factory,
      $this->moduleHandler(),
      $index_manager,
      $this->entityTypeManager($index),
      $this->createMock(LoggerInterface::class),
    );
    $migration->migrate();
  }

}
