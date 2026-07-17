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
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tests the Beacon platform admin setting plugin.
 *
 * The Beacon (AI Chat) section on the Platform Admin Settings page: the
 * authorization flag, the Enable chat widget toggle, and the Re-index / Index
 * now buttons. The buttons reuse the site settings form's handlers verbatim
 * through the class resolver, so this test asserts the delegation and the
 * render state (read-only guard, empty-queue disable) rather than re-testing
 * the shared tracker-rebuild / batch paths (covered by IndexNowFormTest).
 *
 * The validateSettings() legacy-chat guard's positive branch calls the
 * procedural ys_beacon_legacy_chat_active() and is verified manually on Lando;
 * the negative branch (nothing to validate when the toggle is off) is covered
 * here.
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
      $messenger ?? $this->createMock(MessengerInterface::class),
      $logger ?? $this->createMock(LoggerInterface::class),
    );
    $plugin->setStringTranslation($this->getStringTranslationStub());
    return $plugin;
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
   * An entity type manager returning $index for both load paths.
   *
   * Stubs both the override-free load (setIndexStatus) and the regular load
   * (tracker rebuild) to return $index.
   */
  private function entityTypeManagerWithWritableIndex(IndexInterface $index): EntityTypeManagerInterface {
    $storage = $this->createMock(ConfigEntityStorageInterface::class);
    $storage->method('load')->with('ys_beacon')->willReturn($index);
    $storage->method('loadOverrideFree')->with('ys_beacon')->willReturn($index);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('search_api_index')->willReturn($storage);
    return $entity_type_manager;
  }

  /**
   * Builds an index mock with the given read-only, status and remaining count.
   */
  private function indexMock(bool $read_only, bool $enabled, int $remaining): IndexInterface {
    $index = $this->createMock(IndexInterface::class);
    $index->method('isReadOnly')->willReturn($read_only);
    $index->method('status')->willReturn($enabled);
    $tracker = $this->createMock(TrackerInterface::class);
    $tracker->method('getRemainingItemsCount')->willReturn($remaining);
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
   * A first enable provisions the missing index and queues content.
   *
   * @covers ::submitSettings
   * @covers ::enableIndex
   * @covers ::configuredIndexMissing
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

    $plugin = $this->plugin($factory, $entity_type_manager, $index_manager, $messenger);

    $form_state = new FormState();
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 1);
    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * Re-enabling with an existing index enables it without re-provisioning.
   *
   * @covers ::submitSettings
   * @covers ::enableIndex
   * @covers ::configuredIndexMissing
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
    // The index already exists, so it must never be re-provisioned.
    $index_manager->expects($this->once())->method('indexExists')->with('my-index')->willReturn(TRUE);
    $index_manager->expects($this->never())->method('provision');

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->never())->method('addStatus');
    $messenger->expects($this->never())->method('addWarning');

    $plugin = $this->plugin($factory, $entity_type_manager, $index_manager, $messenger);

    $form_state = new FormState();
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 1);
    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * A transient Azure outage on re-enable keeps an existing index enabled.
   *
   * The indexExists() check throws when the endpoint is unreachable, so the
   * enable path must treat that as "not missing": it never disables a healthy
   * existing index or shows a misleading "could not be created" warning,
   * matching the site form.
   *
   * @covers ::submitSettings
   * @covers ::enableIndex
   * @covers ::configuredIndexMissing
   */
  public function testEnableDuringTransientOutageKeepsIndexEnabled(): void {
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
    $index_manager->method('indexExists')->with('my-index')
      ->willThrowException(new \RuntimeException('unreachable'));
    // A transient outage must not trigger a doomed re-provision.
    $index_manager->expects($this->never())->method('provision');

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->never())->method('addWarning');

    $plugin = $this->plugin($factory, $entity_type_manager, $index_manager, $messenger);

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

    $plugin = $this->plugin($factory, $entity_type_manager, $index_manager);

    $form_state = new FormState();
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 1);
    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * A failed first-time provision leaves the index off and warns the user.
   *
   * With no index name yet, a provision failure persists no name, so the config
   * override keeps the index disabled; the status is never set on and never
   * needs a rollback.
   *
   * @covers ::submitSettings
   * @covers ::enableIndex
   * @covers ::configuredIndexMissing
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
    $config->method('set')->willReturnSelf();
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

    $plugin = $this->plugin($factory, $entity_type_manager, $index_manager, $messenger, $logger);

    $form_state = new FormState();
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form_state->setValue(['ys_beacon', 'enable_chat'], 1);
    $form = [];
    // Must not throw: the handler catches the provisioning failure.
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * Validation is skipped when the toggle is off.
   *
   * @covers ::validateSettings
   */
  public function testValidateSkipsWhenToggleOff(): void {
    $factory = $this->getConfigFactoryStub(['ys_beacon.settings' => []]);
    $plugin = $this->plugin($factory);

    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->with(['ys_beacon', 'enable_chat'])->willReturn(0);
    $form_state->expects($this->never())->method('setErrorByName');

    $form = [];
    $plugin->validateSettings($form, $form_state);
  }

  /**
   * The Re-index button delegates to the site form's reindexAll() handler.
   *
   * @covers ::reindexAllSubmit
   */
  public function testReindexAllSubmitDelegates(): void {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);

    $settings_form = $this->createMock(YsBeaconSettings::class);
    $settings_form->expects($this->once())->method('reindexAll');
    $settings_form->expects($this->never())->method('indexNow');

    $this->setContainerWithResolver($settings_form);

    BeaconPlatformAdminSetting::reindexAllSubmit($form, $form_state);
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
