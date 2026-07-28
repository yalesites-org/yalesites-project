<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Tracker\TrackerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Form\YsBeaconSettings;
use Drupal\ys_beacon\Plugin\PlatformAdminSetting\BeaconPlatformAdminSetting;
use Drupal\ys_beacon\Service\BeaconIndexManager;
use Drupal\ys_beacon\Service\LegacyAiEngine;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tests the Beacon platform admin setting plugin.
 *
 * The Beacon (AI Chat) section on the Platform Admin Settings page: the
 * authorization flag, the Enable chat widget toggle, the assisted ai_engine
 * cutover control, and the Re-index / Index now buttons. The indexing buttons
 * reuse the site settings form's handlers verbatim through the class resolver,
 * so this test asserts the delegation and the render state (read-only guard,
 * empty-queue disable) rather than re-testing the shared tracker-rebuild /
 * batch paths (covered by IndexNowFormTest).
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Plugin\PlatformAdminSetting\BeaconPlatformAdminSetting
 */
class BeaconPlatformAdminSettingTest extends UnitTestCase {

  /**
   * Builds the plugin with the given collaborators.
   */
  private function plugin(
    ConfigFactoryInterface $config_factory,
    ?EntityTypeManagerInterface $entity_type_manager = NULL,
    ?BeaconIndexManager $index_manager = NULL,
    ?LegacyAiEngine $legacy_ai_engine = NULL,
    ?MessengerInterface $messenger = NULL,
    ?LoggerInterface $logger = NULL,
  ): BeaconPlatformAdminSetting {
    $plugin = new BeaconPlatformAdminSetting(
      [],
      'ys_beacon',
      [],
      $config_factory,
      $this->createMock(AccountInterface::class),
      $entity_type_manager ?? $this->createMock(EntityTypeManagerInterface::class),
      $index_manager ?? $this->createMock(BeaconIndexManager::class),
      // A bare mock reports the legacy stack as retired (bool return defaults
      // to FALSE), which is the state most of these tests care about.
      $legacy_ai_engine ?? $this->createMock(LegacyAiEngine::class),
      $messenger ?? $this->createMock(MessengerInterface::class),
      $logger ?? $this->createMock(LoggerInterface::class),
    );
    $plugin->setStringTranslation($this->getStringTranslationStub());
    return $plugin;
  }

  /**
   * A legacy ai_engine service double with the given active state.
   *
   * @param bool $active
   *   Whether any part of the legacy stack is still switched on.
   * @param bool $chat_active
   *   Whether the legacy chat widget specifically is rendering.
   *
   * @return \Drupal\ys_beacon\Service\LegacyAiEngine
   *   The service double.
   */
  private function legacyAiEngine(bool $active, bool $chat_active = FALSE): LegacyAiEngine {
    $legacy = $this->createMock(LegacyAiEngine::class);
    $legacy->method('isActive')->willReturn($active);
    $legacy->method('chatActive')->willReturn($chat_active);
    return $legacy;
  }

  /**
   * An entity type manager whose search_api_index storage loads $index.
   */
  private function entityTypeManagerWithIndex(?IndexInterface $index): EntityTypeManagerInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('ys_beacon')->willReturn($index);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('search_api_index')->willReturn($storage);
    return $entity_type_manager;
  }

  /**
   * An entity type manager stubbing both search_api_index load paths.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The entity load() returns, and loadOverrideFree() too unless
   *   $override_free is given.
   * @param \Drupal\search_api\IndexInterface|null $override_free
   *   The entity loadOverrideFree() returns, when it must differ. Models the
   *   real divergence: the override-resolved load() serves a cached
   *   status-disabled index while the stored config says otherwise.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   */
  private function entityTypeManagerWithWritableIndex(IndexInterface $index, ?IndexInterface $override_free = NULL): EntityTypeManagerInterface {
    $storage = $this->createMock(ConfigEntityStorageInterface::class);
    $storage->method('load')->with('ys_beacon')->willReturn($index);
    $storage->method('loadOverrideFree')->with('ys_beacon')->willReturn($override_free ?? $index);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('search_api_index')->willReturn($storage);
    return $entity_type_manager;
  }

  /**
   * Builds an index mock with the given read-only, status and item counts.
   */
  private function indexMock(bool $read_only, bool $enabled, int $remaining, int $indexed = 0, int $total = 0): IndexInterface {
    $index = $this->createMock(IndexInterface::class);
    $index->method('isReadOnly')->willReturn($read_only);
    $index->method('status')->willReturn($enabled);
    $tracker = $this->createMock(TrackerInterface::class);
    $tracker->method('getRemainingItemsCount')->willReturn($remaining);
    $tracker->method('getIndexedItemsCount')->willReturn($indexed);
    $tracker->method('getTotalItemsCount')->willReturn($total);
    $index->method('getTrackerInstance')->willReturn($tracker);
    return $index;
  }

  /**
   * The toggles reflect the stored authorization and chat-enable flags.
   *
   * @covers ::buildSettings
   */
  public function testTogglesReflectStoredValues(): void {
    $factory = $this->getConfigFactoryStub([
      'ys_beacon.settings' => [
        'platform_authorized' => TRUE,
        'enable_chat' => TRUE,
        'search_index_id' => 'ys_beacon',
      ],
    ]);
    $plugin = $this->plugin($factory, $this->entityTypeManagerWithIndex($this->indexMock(FALSE, TRUE, 3)));

    $form = $plugin->buildSettings([], new FormState());

    $this->assertSame('checkbox', $form['platform_authorized']['#type']);
    $this->assertTrue((bool) $form['platform_authorized']['#default_value']);
    $this->assertSame('checkbox', $form['enable_chat']['#type']);
    $this->assertTrue((bool) $form['enable_chat']['#default_value']);
  }

  /**
   * The off state reflects unset flags.
   *
   * @covers ::buildSettings
   */
  public function testTogglesReflectUnsetValues(): void {
    $factory = $this->getConfigFactoryStub(['ys_beacon.settings' => ['search_index_id' => 'ys_beacon']]);
    $plugin = $this->plugin($factory, $this->entityTypeManagerWithIndex($this->indexMock(FALSE, TRUE, 0)));

    $form = $plugin->buildSettings([], new FormState());

    $this->assertFalse((bool) $form['platform_authorized']['#default_value']);
    $this->assertFalse((bool) $form['enable_chat']['#default_value']);
  }

  /**
   * A writable index renders both indexing buttons wired to shared handlers.
   *
   * @covers ::buildSettings
   */
  public function testIndexingButtonsRenderedWhenWritable(): void {
    $factory = $this->getConfigFactoryStub(['ys_beacon.settings' => ['search_index_id' => 'ys_beacon']]);
    $plugin = $this->plugin($factory, $this->entityTypeManagerWithIndex($this->indexMock(FALSE, TRUE, 5)));

    $form = $plugin->buildSettings([], new FormState());

    $this->assertArrayNotHasKey('read_only_notice', $form['indexing']);
    $this->assertSame('submit', $form['indexing']['reindex']['#type']);
    $this->assertSame(
      [[BeaconPlatformAdminSetting::class, 'reindexAllSubmit']],
      $form['indexing']['reindex']['#submit']
    );
    $this->assertSame([], $form['indexing']['reindex']['#limit_validation_errors']);
    $this->assertSame('submit', $form['indexing']['index_now']['#type']);
    $this->assertSame(
      [[BeaconPlatformAdminSetting::class, 'indexNowSubmit']],
      $form['indexing']['index_now']['#submit']
    );
    // Items are queued, so "Index now" is enabled.
    $this->assertFalse($form['indexing']['index_now']['#disabled']);
  }

  /**
   * The "Index now" button is disabled when nothing is queued.
   *
   * @covers ::buildSettings
   * @covers ::indexRemainingItems
   */
  public function testIndexNowDisabledWhenQueueEmpty(): void {
    $factory = $this->getConfigFactoryStub(['ys_beacon.settings' => ['search_index_id' => 'ys_beacon']]);
    $plugin = $this->plugin($factory, $this->entityTypeManagerWithIndex($this->indexMock(FALSE, TRUE, 0)));

    $form = $plugin->buildSettings([], new FormState());

    $this->assertTrue($form['indexing']['index_now']['#disabled']);
  }

  /**
   * A read-only index hides the indexing buttons and shows the borrow note.
   *
   * @covers ::buildSettings
   */
  public function testIndexingButtonsHiddenWhenReadOnly(): void {
    $factory = $this->getConfigFactoryStub(['ys_beacon.settings' => ['search_index_id' => 'ys_beacon']]);
    $plugin = $this->plugin($factory, $this->entityTypeManagerWithIndex($this->indexMock(TRUE, TRUE, 5)));

    $form = $plugin->buildSettings([], new FormState());

    $this->assertArrayHasKey('read_only_notice', $form['indexing']);
    $this->assertArrayNotHasKey('reindex', $form['indexing']);
    $this->assertArrayNotHasKey('index_now', $form['indexing']);
    // The count status belongs with the controls, not the read-only note.
    $this->assertArrayNotHasKey('status', $form['indexing']);
  }

  /**
   * An enabled index shows the "X of Y items indexed" count with the controls.
   *
   * Mirrors the read-only status site admins see on the Beacon settings form
   * so platform admins can tell whether indexing succeeded, next to the
   * Re-index / Index now buttons.
   *
   * @covers ::buildSettings
   * @covers ::indexStatusSummary
   */
  public function testIndexStatusShowsCountWhenWritable(): void {
    $factory = $this->getConfigFactoryStub(['ys_beacon.settings' => ['search_index_id' => 'ys_beacon']]);
    $plugin = $this->plugin($factory, $this->entityTypeManagerWithIndex($this->indexMock(FALSE, TRUE, 48, 3, 51)));

    $form = $plugin->buildSettings([], new FormState());

    $this->assertArrayHasKey('status', $form['indexing']);
    $this->assertStringContainsString('3 of 51 items indexed.', (string) $form['indexing']['status']['#markup']);
  }

  /**
   * A disabled index shows the "index disabled" note instead of a count.
   *
   * @covers ::buildSettings
   * @covers ::indexStatusSummary
   */
  public function testIndexStatusShowsDisabledMessageWhenIndexDisabled(): void {
    $factory = $this->getConfigFactoryStub(['ys_beacon.settings' => ['search_index_id' => 'ys_beacon']]);
    $plugin = $this->plugin($factory, $this->entityTypeManagerWithIndex($this->indexMock(FALSE, FALSE, 0)));

    $form = $plugin->buildSettings([], new FormState());

    $this->assertArrayHasKey('status', $form['indexing']);
    $this->assertStringContainsString('currently disabled', (string) $form['indexing']['status']['#markup']);
  }

  /**
   * Submitting saves the authorization flag and the chat toggle together.
   *
   * @covers ::submitSettings
   */
  public function testSubmitSavesFlags(): void {
    $config = $this->createMock(Config::class);
    // No prior chat-enable value, and the submitted toggle stays off, so no
    // index transition side effects run.
    $config->method('get')->willReturnCallback(fn (string $key) => NULL);
    $set = [];
    $config->method('set')->willReturnCallback(function (string $key, $value) use (&$set, $config) {
      $set[$key] = $value;
      return $config;
    });
    $config->expects($this->once())->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($config);

    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->never())->method('provision');

    $plugin = $this->plugin($factory, NULL, $index_manager);

    $form_state = new FormState();
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 0);
    $form = [];
    $plugin->submitSettings($form, $form_state);

    $this->assertTrue($set['platform_authorized']);
    $this->assertFalse($set['enable_chat']);
  }

  /**
   * Enabling the chat widget forces the AI metadata fields on.
   *
   * The site settings form used to guarantee "chat on implies AI metadata
   * fields on"; with enabling now only on this page, it must preserve that
   * invariant so editors keep the AI Description/Tags fields the live chatbot
   * relies on, even if the fields were previously turned off.
   *
   * @covers ::submitSettings
   */
  public function testEnableForcesMetadataFieldsOn(): void {
    $settings = [
      'enable_chat' => FALSE,
      'enable_metadata_fields' => FALSE,
      'read_only' => FALSE,
      'azure_index_name' => 'my-index',
      'search_index_id' => 'ys_beacon',
    ];
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);
    $set = [];
    $config->method('set')->willReturnCallback(function (string $key, $value) use (&$set, $config) {
      $set[$key] = $value;
      return $config;
    });
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($config);
    $factory->method('get')->with('ys_beacon.settings')->willReturn($config);

    $index = $this->createMock(IndexInterface::class);
    $index->method('setStatus')->willReturnSelf();
    $entity_type_manager = $this->entityTypeManagerWithWritableIndex($index);

    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->method('indexExists')->with('my-index')->willReturn(TRUE);

    $plugin = $this->plugin($factory, $entity_type_manager, $index_manager);

    $form_state = new FormState();
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 1);
    $form = [];
    $plugin->submitSettings($form, $form_state);

    $this->assertTrue($set['enable_metadata_fields']);
    $this->assertTrue($set['enable_chat']);
  }

  /**
   * Enabling decides from stored config, not the runtime-overridden view.
   *
   * The override forces the index status off while Beacon is unauthorized or
   * chat is off, and that resolved value stays cached from the form build - so
   * it still reports disabled after the save. Deciding from it would make the
   * outcome depend on a stale cache entry, so the write path reads the
   * override-free index and never acts on the overridden one
   * (yalesites-org/YaleSites-Internal#1459).
   *
   * @covers ::submitSettings
   * @covers ::enableIndex
   * @covers ::loadBeaconIndexOverrideFree
   */
  public function testEnableDecidesFromStoredConfigNotOverriddenView(): void {
    $settings = [
      'enable_chat' => FALSE,
      'read_only' => FALSE,
      'azure_index_name' => 'my-index',
      'search_index_id' => 'ys_beacon',
    ];
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);
    $config->method('set')->willReturnSelf();
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($config);
    $factory->method('get')->with('ys_beacon.settings')->willReturn($config);

    // The override-resolved load reports the index disabled. Nothing on the
    // write path may act on it.
    $stale = $this->indexMock(FALSE, FALSE, 0);
    $stale->expects($this->never())->method('setStatus');
    $stale->expects($this->never())->method('rebuildTracker');

    // Stored config: disabled and tracking nothing, so it is enabled and
    // seeded.
    $stored = $this->indexMock(FALSE, FALSE, 0);
    $stored->expects($this->once())->method('setStatus')->with(TRUE)->willReturnSelf();
    $stored->expects($this->once())->method('save');
    $stored->expects($this->once())->method('rebuildTracker');

    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->method('indexExists')->with('my-index')->willReturn(TRUE);

    $plugin = $this->plugin(
      $factory,
      $this->entityTypeManagerWithWritableIndex($stale, $stored),
      $index_manager
    );

    $form_state = new FormState();
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 1);
    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * An already-enabled index that tracks nothing is still seeded.
   *
   * Search_api.index.ys_beacon ships status: true, so on a site that has
   * never indexed anything the status flag already reads "enabled" and cannot
   * stand in for "already running". Gating the seed on it left such a site
   * with an enabled index tracking zero items and "Index now" permanently
   * disabled (yalesites-org/YaleSites-Internal#1459).
   *
   * @covers ::submitSettings
   * @covers ::enableIndex
   * @covers ::trackedItemCount
   */
  public function testEnabledButUntrackedIndexIsStillSeeded(): void {
    $settings = [
      'enable_chat' => FALSE,
      'read_only' => FALSE,
      'azure_index_name' => 'my-index',
      'search_index_id' => 'ys_beacon',
    ];
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);
    $config->method('set')->willReturnSelf();
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($config);
    $factory->method('get')->with('ys_beacon.settings')->willReturn($config);

    // Already enabled (the shipped default) but nothing tracked yet.
    $index = $this->indexMock(FALSE, TRUE, 0);
    $index->expects($this->never())->method('setStatus');
    $index->expects($this->once())->method('rebuildTracker');

    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->method('indexExists')->with('my-index')->willReturn(TRUE);

    $plugin = $this->plugin(
      $factory,
      $this->entityTypeManagerWithWritableIndex($index),
      $index_manager
    );

    $form_state = new FormState();
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 1);
    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * A first enable provisions the missing index and queues content.
   *
   * @covers ::submitSettings
   * @covers ::enableIndex
   * @covers ::setIndexStatus
   */
  public function testEnableTransitionProvisionsIndex(): void {
    $settings = [
      'enable_chat' => FALSE,
      'read_only' => FALSE,
      'azure_index_name' => 'my-index',
      'search_index_id' => 'ys_beacon',
    ];
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);
    $config->method('set')->willReturnSelf();
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($config);
    $factory->method('get')->with('ys_beacon.settings')->willReturn($config);

    $index = $this->createMock(IndexInterface::class);
    $index->expects($this->once())->method('setStatus')->with(TRUE)->willReturnSelf();
    $index->expects($this->once())->method('save');
    $index->expects($this->once())->method('rebuildTracker');
    $entity_type_manager = $this->entityTypeManagerWithWritableIndex($index);

    $index_manager = $this->createMock(BeaconIndexManager::class);
    // The configured index does not exist yet, so it is provisioned.
    $index_manager->expects($this->once())->method('indexExists')->with('my-index')->willReturn(FALSE);
    $index_manager->expects($this->once())->method('provision')->with('my-index')->willReturn('my-index');

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addStatus');
    $messenger->expects($this->never())->method('addWarning');

    $plugin = $this->plugin($factory, $entity_type_manager, $index_manager, messenger: $messenger);

    $form_state = new FormState();
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 1);
    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * Re-enabling with an existing index enables it without re-provisioning.
   *
   * It is never re-provisioned, but the resolved endpoint is still pinned so an
   * adopted index survives a later Pantheon-secret change
   * (yalesites-org/YaleSites-Internal#1440).
   *
   * @covers ::submitSettings
   * @covers ::enableIndex
   */
  public function testEnableWithExistingIndexSkipsProvision(): void {
    $settings = [
      'enable_chat' => FALSE,
      'read_only' => FALSE,
      'azure_index_name' => 'my-index',
      'search_index_id' => 'ys_beacon',
    ];
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);
    $config->method('set')->willReturnSelf();
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($config);
    $factory->method('get')->with('ys_beacon.settings')->willReturn($config);

    $index = $this->createMock(IndexInterface::class);
    $index->expects($this->once())->method('setStatus')->with(TRUE)->willReturnSelf();
    $index->expects($this->once())->method('save');
    $index->expects($this->once())->method('rebuildTracker');
    $entity_type_manager = $this->entityTypeManagerWithWritableIndex($index);

    $index_manager = $this->createMock(BeaconIndexManager::class);
    // The index already exists, so it must never be re-provisioned - but the
    // resolved endpoint is still pinned so the adopted index is not moved by a
    // later Pantheon-secret change.
    $index_manager->expects($this->once())->method('indexExists')->with('my-index')->willReturn(TRUE);
    $index_manager->expects($this->never())->method('provision');
    $index_manager->expects($this->once())->method('pinSearchUrl')->willReturn(TRUE);

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->never())->method('addStatus');
    $messenger->expects($this->never())->method('addWarning');

    $plugin = $this->plugin($factory, $entity_type_manager, $index_manager, messenger: $messenger);

    $form_state = new FormState();
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 1);
    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * An already-enabled site stays enabled when the index can't be verified.
   *
   * On a re-save while chat is already on, an indexExists() error (Azure
   * unreachable or an auth failure) is inconclusive, so it must not disable a
   * working index over a transient blip: the prior enabled state is kept, the
   * operator is warned to try again, and the reason is logged
   * (yalesites-org/YaleSites-Internal#1448).
   *
   * @covers ::submitSettings
   * @covers ::enableIndex
   */
  public function testAlreadyEnabledStaysEnabledWhenUnverifiable(): void {
    $settings = [
      'enable_chat' => TRUE,
      'read_only' => FALSE,
      'azure_index_name' => 'my-index',
      'search_index_id' => 'ys_beacon',
    ];
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);
    $set = [];
    $config->method('set')->willReturnCallback(function (string $key, $value) use (&$set, $config) {
      $set[$key] = $value;
      return $config;
    });
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($config);
    $factory->method('get')->with('ys_beacon.settings')->willReturn($config);

    // An inconclusive check changes no index state, so storage is untouched.
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->never())->method('getStorage');

    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->method('indexExists')->with('my-index')
      ->willThrowException(new \RuntimeException('unreachable'));
    $index_manager->expects($this->never())->method('provision');

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addWarning');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $plugin = $this->plugin($factory, $entity_type_manager, $index_manager, messenger: $messenger, logger: $logger);

    $form_state = new FormState();
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 1);
    $form = [];
    $plugin->submitSettings($form, $form_state);

    // The widget stays enabled - the inconclusive check did not turn it off.
    $this->assertTrue($set['enable_chat']);
  }

  /**
   * A first-time enable that can't verify the index is turned back off.
   *
   * Turning the widget on for the first time when the index cannot even be
   * checked most likely means it could not be created, so the widget must not
   * report itself enabled: it reverts to off, warns the operator, and logs the
   * reason (yalesites-org/YaleSites-Internal#1448).
   *
   * @covers ::submitSettings
   * @covers ::enableIndex
   */
  public function testFreshEnableRevertsToOffWhenUnverifiable(): void {
    $settings = [
      'enable_chat' => FALSE,
      'read_only' => FALSE,
      'azure_index_name' => 'my-index',
      'search_index_id' => 'ys_beacon',
    ];
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);
    $set = [];
    $config->method('set')->willReturnCallback(function (string $key, $value) use (&$set, $config) {
      $set[$key] = $value;
      return $config;
    });
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($config);
    $factory->method('get')->with('ys_beacon.settings')->willReturn($config);

    // Nothing is enabled, so the index status is never touched.
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->never())->method('getStorage');

    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->method('indexExists')->with('my-index')
      ->willThrowException(new \RuntimeException('unreachable'));
    $index_manager->expects($this->never())->method('provision');

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addWarning');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $plugin = $this->plugin($factory, $entity_type_manager, $index_manager, messenger: $messenger, logger: $logger);

    $form_state = new FormState();
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 1);
    $form = [];
    $plugin->submitSettings($form, $form_state);

    // The widget reverts to off - it never reports enabled while unconfirmed.
    $this->assertFalse($set['enable_chat']);
  }

  /**
   * A re-save while enabled re-checks and recreates an index removed in Azure.
   *
   * Provisioning runs on every save while chat is on, not just the off->on
   * transition, so deleting the index in Azure and re-saving the form recreates
   * it without toggling the widget off and on
   * (yalesites-org/YaleSites-Internal#1448).
   *
   * @covers ::submitSettings
   * @covers ::enableIndex
   */
  public function testAlreadyEnabledRechecksAndRecreatesMissingIndex(): void {
    $settings = [
      'enable_chat' => TRUE,
      'read_only' => FALSE,
      'azure_index_name' => 'my-index',
      'search_index_id' => 'ys_beacon',
    ];
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);
    $config->method('set')->willReturnSelf();
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($config);
    $factory->method('get')->with('ys_beacon.settings')->willReturn($config);

    $index = $this->createMock(IndexInterface::class);
    $index->method('setStatus')->willReturnSelf();
    $entity_type_manager = $this->entityTypeManagerWithWritableIndex($index);

    $index_manager = $this->createMock(BeaconIndexManager::class);
    // The index was removed in Azure: the re-save re-checks and recreates it,
    // proving the check is not gated on an off->on transition.
    $index_manager->expects($this->once())->method('indexExists')->with('my-index')->willReturn(FALSE);
    $index_manager->expects($this->once())->method('provision')->with('my-index')->willReturn('my-index');

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addStatus');
    $messenger->expects($this->never())->method('addWarning');

    $plugin = $this->plugin($factory, $entity_type_manager, $index_manager, messenger: $messenger);

    $form_state = new FormState();
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 1);
    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * A routine re-save with a healthy index re-checks but does not re-queue.
   *
   * While chat is on, every save re-verifies the index, but when it already
   * exists and is enabled the tracker is left alone so a routine save does not
   * re-queue already-indexed content (yalesites-org/YaleSites-Internal#1448).
   *
   * @covers ::submitSettings
   * @covers ::enableIndex
   */
  public function testAlreadyEnabledExistingIndexSkipsRequeue(): void {
    $settings = [
      'enable_chat' => TRUE,
      'read_only' => FALSE,
      'azure_index_name' => 'my-index',
      'search_index_id' => 'ys_beacon',
    ];
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);
    $config->method('set')->willReturnSelf();
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($config);
    $factory->method('get')->with('ys_beacon.settings')->willReturn($config);

    // Already enabled AND already tracking content: never re-status and never
    // re-queue, so a routine re-save cannot re-enumerate an indexed site.
    $index = $this->indexMock(FALSE, TRUE, 0, 51, 51);
    $index->expects($this->never())->method('setStatus');
    $index->expects($this->never())->method('rebuildTracker');
    $entity_type_manager = $this->entityTypeManagerWithWritableIndex($index);

    $index_manager = $this->createMock(BeaconIndexManager::class);
    // The index is re-checked every save (proves it is not transition-gated).
    $index_manager->expects($this->once())->method('indexExists')->with('my-index')->willReturn(TRUE);
    $index_manager->expects($this->never())->method('provision');

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->never())->method('addStatus');
    $messenger->expects($this->never())->method('addWarning');

    $plugin = $this->plugin($factory, $entity_type_manager, $index_manager, messenger: $messenger);

    $form_state = new FormState();
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 1);
    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * Disabling the chat toggle disables the index without provisioning.
   *
   * @covers ::submitSettings
   * @covers ::setIndexStatus
   */
  public function testDisableTransitionDisablesIndex(): void {
    $settings = ['enable_chat' => TRUE, 'search_index_id' => 'ys_beacon'];
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);
    $config->method('set')->willReturnSelf();
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($config);
    $factory->method('get')->with('ys_beacon.settings')->willReturn($config);

    $index = $this->createMock(IndexInterface::class);
    $index->expects($this->once())->method('setStatus')->with(FALSE)->willReturnSelf();
    $index->expects($this->once())->method('save');
    $storage = $this->createMock(ConfigEntityStorageInterface::class);
    $storage->method('loadOverrideFree')->with('ys_beacon')->willReturn($index);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('search_api_index')->willReturn($storage);

    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->never())->method('provision');

    $plugin = $this->plugin($factory, $entity_type_manager, $index_manager);

    $form_state = new FormState();
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 0);
    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * No on/off change leaves the index untouched.
   *
   * @covers ::submitSettings
   */
  public function testNoTransitionLeavesIndexUntouched(): void {
    $settings = ['enable_chat' => FALSE, 'search_index_id' => 'ys_beacon'];
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);
    $config->method('set')->willReturnSelf();
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($config);
    $factory->method('get')->with('ys_beacon.settings')->willReturn($config);

    // The storage must never be touched when there is no transition.
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->never())->method('getStorage');

    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->never())->method('provision');

    $plugin = $this->plugin($factory, $entity_type_manager, $index_manager);

    $form_state = new FormState();
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 0);
    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * A read-only borrow enables the index but never provisions or writes it.
   *
   * A borrower does not own the collection it reads, so it must never pin the
   * endpoint either (yalesites-org/YaleSites-Internal#1440).
   *
   * @covers ::submitSettings
   * @covers ::enableIndex
   */
  public function testReadOnlyEnableSkipsProvision(): void {
    $settings = ['enable_chat' => FALSE, 'read_only' => TRUE, 'search_index_id' => 'ys_beacon'];
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);
    $config->method('set')->willReturnSelf();
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($config);
    $factory->method('get')->with('ys_beacon.settings')->willReturn($config);

    $index = $this->createMock(IndexInterface::class);
    $index->expects($this->once())->method('setStatus')->with(TRUE)->willReturnSelf();
    $storage = $this->createMock(ConfigEntityStorageInterface::class);
    $storage->method('loadOverrideFree')->with('ys_beacon')->willReturn($index);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('search_api_index')->willReturn($storage);

    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->never())->method('provision');
    $index_manager->expects($this->never())->method('pinSearchUrl');

    $plugin = $this->plugin($factory, $entity_type_manager, $index_manager);

    $form_state = new FormState();
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 1);
    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * A failed first-time provision leaves the index off, warns, and reverts.
   *
   * With no index name yet, a provision failure persists no name, so the config
   * override keeps the index disabled and the status is never set on; the chat
   * toggle is reverted to off so it never reports enabled while broken, and the
   * reason is logged (yalesites-org/YaleSites-Internal#1448).
   *
   * @covers ::submitSettings
   * @covers ::enableIndex
   */
  public function testProvisionFailureLeavesIndexOffAndWarns(): void {
    $settings = [
      'enable_chat' => FALSE,
      'read_only' => FALSE,
      'azure_index_name' => '',
      'search_index_id' => 'ys_beacon',
    ];
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);
    $set = [];
    $config->method('set')->willReturnCallback(function (string $key, $value) use (&$set, $config) {
      $set[$key] = $value;
      return $config;
    });
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($config);
    $factory->method('get')->with('ys_beacon.settings')->willReturn($config);

    // The index is never touched: provisioning fails before any status change.
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->never())->method('getStorage');

    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->method('provision')->willThrowException(new \RuntimeException('unreachable'));

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addWarning');
    $messenger->expects($this->never())->method('addStatus');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $plugin = $this->plugin($factory, $entity_type_manager, $index_manager, messenger: $messenger, logger: $logger);

    $form_state = new FormState();
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 1);
    $form = [];
    // Must not throw: the handler catches the provisioning failure.
    $plugin->submitSettings($form, $form_state);

    // The widget reverts to off after a failed provision.
    $this->assertFalse($set['enable_chat']);
  }

  /**
   * De-authorizing a site never verifies the index and disables it.
   *
   * Beacon is inactive without platform authorization regardless of the chat
   * toggle, so saving a de-authorized site must not verify or provision the
   * index (no Azure round-trip, no re-queue); it only disables the local index
   * (yalesites-org/YaleSites-Internal#1448).
   *
   * @covers ::submitSettings
   */
  public function testDeauthorizedSiteSkipsVerifyAndDisablesIndex(): void {
    $settings = [
      'enable_chat' => TRUE,
      'read_only' => FALSE,
      'azure_index_name' => 'my-index',
      'search_index_id' => 'ys_beacon',
    ];
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);
    $config->method('set')->willReturnSelf();
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($config);
    $factory->method('get')->with('ys_beacon.settings')->willReturn($config);

    // Only the disable path runs: the index is set off, never verified.
    $index = $this->createMock(IndexInterface::class);
    $index->expects($this->once())->method('setStatus')->with(FALSE)->willReturnSelf();
    $storage = $this->createMock(ConfigEntityStorageInterface::class);
    $storage->method('loadOverrideFree')->with('ys_beacon')->willReturn($index);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('search_api_index')->willReturn($storage);

    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->never())->method('indexExists');
    $index_manager->expects($this->never())->method('provision');

    $plugin = $this->plugin($factory, $entity_type_manager, $index_manager);

    $form_state = new FormState();
    // De-authorized, but the chat toggle is left checked.
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 0);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 1);
    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * Enabling Beacon chat is never blocked, even with the legacy widget live.
   *
   * Bringing Beacon up first is the safe order: only one widget can render (the
   * render guards stand Beacon down while the legacy chat is live), so enabling
   * it here provisions the index and queues content while visitors keep the
   * legacy chatbot. Blocking it forced the reverse, unsafe order
   * (yalesites-org/YaleSites-Internal#1459).
   *
   * @covers ::validateSettings
   */
  public function testValidateNeverBlocksEnable(): void {
    $factory = $this->getConfigFactoryStub(['ys_beacon.settings' => []]);
    $plugin = $this->plugin($factory, legacy_ai_engine: $this->legacyAiEngine(TRUE, TRUE));

    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->expects($this->never())->method('setErrorByName');

    $form = [];
    $plugin->validateSettings($form, $form_state);
  }

  /**
   * The assisted-cutover control is hidden once ai_engine is fully retired.
   *
   * Nothing left to turn off means no button, so the section does not carry a
   * dead control on the vast majority of sites.
   *
   * @covers ::buildSettings
   */
  public function testLegacyCutoverControlHiddenWhenRetired(): void {
    $factory = $this->getConfigFactoryStub(['ys_beacon.settings' => ['search_index_id' => 'ys_beacon']]);
    $plugin = $this->plugin(
      $factory,
      $this->entityTypeManagerWithIndex($this->indexMock(FALSE, TRUE, 0)),
      NULL,
      $this->legacyAiEngine(FALSE)
    );

    $form = $plugin->buildSettings([], new FormState());

    $this->assertArrayNotHasKey('legacy', $form);
  }

  /**
   * The cutover button appears once Beacon is ready to take over.
   *
   * @covers ::buildSettings
   * @covers ::beaconReadyToTakeOver
   */
  public function testCutoverButtonShownWhenBeaconReady(): void {
    $factory = $this->getConfigFactoryStub([
      'ys_beacon.settings' => [
        'platform_authorized' => TRUE,
        'enable_chat' => TRUE,
        'azure_index_name' => 'my-index',
        'search_index_id' => 'ys_beacon',
      ],
    ]);
    $plugin = $this->plugin(
      $factory,
      $this->entityTypeManagerWithIndex($this->indexMock(FALSE, TRUE, 0)),
      legacy_ai_engine: $this->legacyAiEngine(TRUE, TRUE)
    );

    $form = $plugin->buildSettings([], new FormState());

    $this->assertSame('submit', $form['legacy']['retire']['#type']);
    $this->assertSame(
      [[BeaconPlatformAdminSetting::class, 'retireLegacySubmit']],
      $form['legacy']['retire']['#submit']
    );
    // Retiring must not depend on the rest of the page validating.
    $this->assertSame([], $form['legacy']['retire']['#limit_validation_errors']);
  }

  /**
   * The cutover button is withheld until Beacon can actually serve.
   *
   * This is the safety property of the reordered flow: retiring the legacy
   * chatbot before Beacon is authorized, enabled, and pointed at an index would
   * leave the site with no assistant at all if provisioning then failed
   * (yalesites-org/YaleSites-Internal#1459). The notice itself still shows.
   *
   * @dataProvider providerNotReadyToTakeOver
   *
   * @covers ::buildSettings
   * @covers ::beaconReadyToTakeOver
   */
  public function testCutoverButtonWithheldUntilBeaconReady(array $settings): void {
    $factory = $this->getConfigFactoryStub([
      'ys_beacon.settings' => $settings + ['search_index_id' => 'ys_beacon'],
    ]);
    $plugin = $this->plugin(
      $factory,
      $this->entityTypeManagerWithIndex($this->indexMock(FALSE, TRUE, 0)),
      legacy_ai_engine: $this->legacyAiEngine(TRUE, TRUE)
    );

    $form = $plugin->buildSettings([], new FormState());

    $this->assertArrayNotHasKey('retire', $form['legacy']);
    $this->assertArrayHasKey('notice', $form['legacy']);
  }

  /**
   * Data provider: each way Beacon can fall short of ready to take over.
   */
  public static function providerNotReadyToTakeOver(): array {
    $ready = [
      'platform_authorized' => TRUE,
      'enable_chat' => TRUE,
      'azure_index_name' => 'my-index',
    ];
    return [
      'unauthorized' => [['platform_authorized' => FALSE] + $ready],
      'chat off' => [['enable_chat' => FALSE] + $ready],
      'no index configured' => [['azure_index_name' => ''] + $ready],
      'nothing configured' => [[]],
    ];
  }

  /**
   * The cutover button turns the whole legacy stack off.
   *
   * @covers ::retireLegacySubmit
   */
  public function testRetireLegacySubmitDisablesLegacyStack(): void {
    $legacy = $this->createMock(LegacyAiEngine::class);
    $legacy->expects($this->once())->method('disable');

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addStatus');

    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->willReturnCallback(fn ($id) => match ($id) {
      'ys_beacon.legacy_ai_engine' => $legacy,
      'messenger' => $messenger,
      'string_translation' => $this->getStringTranslationStub(),
    });
    \Drupal::setContainer($container);

    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    BeaconPlatformAdminSetting::retireLegacySubmit($form, $form_state);
  }

  /**
   * The Index now button delegates to the site form's indexNow() handler.
   *
   * @covers ::indexNowSubmit
   */
  public function testIndexNowSubmitDelegates(): void {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);

    $settings_form = $this->createMock(YsBeaconSettings::class);
    $settings_form->expects($this->once())->method('indexNow');
    $settings_form->expects($this->never())->method('reindexAll');

    $this->setContainerWithResolver($settings_form);

    BeaconPlatformAdminSetting::indexNowSubmit($form, $form_state);
  }

  /**
   * Installs a container whose class resolver returns the given settings form.
   */
  private function setContainerWithResolver(YsBeaconSettings $settings_form): void {
    $resolver = $this->createMock(ClassResolverInterface::class);
    $resolver->method('getInstanceFromDefinition')
      ->with(YsBeaconSettings::class)
      ->willReturn($settings_form);

    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->with('class_resolver')->willReturn($resolver);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    parent::tearDown();
    // Reset the global container set by the delegation tests.
    \Drupal::unsetContainer();
  }

}
