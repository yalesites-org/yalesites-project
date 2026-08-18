<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\DashboardAnnouncements;
use Drupal\ys_core\Plugin\PlatformAdminSetting\AnnouncementsSourcePlatformAdminSetting;

/**
 * Tests the announcements source platform admin setting plugin.
 *
 * These fields moved off Dashboard settings, where an #access gate hid them
 * from site admins and a matching conditional in submitForm() kept their save
 * from clobbering the values (yalesites-org/YaleSites-Internal#1560). Here the
 * route permission gates the whole page, so the plugin writes unconditionally -
 * except for the no-change short-circuit every section on this page needs,
 * since one Save button submits all of them.
 *
 * @group ys_core
 * @coversDefaultClass \Drupal\ys_core\Plugin\PlatformAdminSetting\AnnouncementsSourcePlatformAdminSetting
 */
class AnnouncementsSourcePlatformAdminSettingTest extends UnitTestCase {

  /**
   * The plugin id, which also namespaces the section's form values.
   */
  private const PLUGIN_ID = 'announcements_source';

  /**
   * Both fields render, with the config values as defaults.
   *
   * @covers ::buildSettings
   */
  public function testBuildRendersBothFieldsFromConfig(): void {
    $plugin = $this->plugin($this->readOnlyConfigFactory([
      'announcements_source_enabled' => TRUE,
      'announcements_source_term' => 'Platform News',
    ]));

    $form = $plugin->buildSettings([], new FormState());

    $this->assertSame('checkbox', $form['announcements_source_enabled']['#type']);
    $this->assertTrue($form['announcements_source_enabled']['#default_value']);
    $this->assertSame('textfield', $form['announcements_source_term']['#type']);
    $this->assertSame('Platform News', $form['announcements_source_term']['#default_value']);
  }

  /**
   * An unset tag falls back to the platform default.
   *
   * @covers ::buildSettings
   */
  public function testBuildFallsBackToTheDefaultTag(): void {
    $plugin = $this->plugin($this->readOnlyConfigFactory([]));

    $form = $plugin->buildSettings([], new FormState());

    $this->assertSame(
      AnnouncementsSourcePlatformAdminSetting::DEFAULT_TERM,
      $form['announcements_source_term']['#default_value'],
    );
  }

  /**
   * The tag field's #states selector matches its #tree-namespaced input name.
   *
   * The page renders every section with #tree set, so the selector has to name
   * the checkbox as announcements_source[announcements_source_enabled] - the
   * un-namespaced selector it carried on Dashboard settings would silently
   * never match and the field would always show.
   *
   * @covers ::buildSettings
   */
  public function testTagFieldIsHiddenUntilPublishingIsEnabled(): void {
    $plugin = $this->plugin($this->readOnlyConfigFactory([]));

    $form = $plugin->buildSettings([], new FormState());

    $this->assertSame(
      [':input[name="announcements_source[announcements_source_enabled]"]' => ['checked' => TRUE]],
      $form['announcements_source_term']['#states']['visible'],
    );
  }

  /**
   * A changed section writes both keys and drops the cached feed.
   *
   * @covers ::submitSettings
   */
  public function testSubmitWritesBothKeysAndClearsTheCache(): void {
    $written = [];
    $announcements = $this->createMock(DashboardAnnouncements::class);
    $announcements->expects($this->once())->method('clearCache');

    $this->submit(
      $written,
      ['announcements_source_enabled' => 0, 'announcements_source_term' => 'Old'],
      ['announcements_source_enabled' => 1, 'announcements_source_term' => '  Platform News  '],
      $announcements,
    );

    $this->assertSame(
      ['announcements_source_enabled' => TRUE, 'announcements_source_term' => 'Platform News'],
      $written,
    );
  }

  /**
   * Renaming the tag alone is a change, and is written.
   *
   * The likeliest real edit on this section - publishing stays on, only the
   * tag name changes - and the one an enabled-only short-circuit would
   * silently discard.
   *
   * @covers ::submitSettings
   */
  public function testSubmitWritesTagRenameWithThePublishToggleUnchanged(): void {
    $written = [];
    $announcements = $this->createMock(DashboardAnnouncements::class);
    $announcements->expects($this->once())->method('clearCache');

    $this->submit(
      $written,
      ['announcements_source_enabled' => TRUE, 'announcements_source_term' => 'Platform News'],
      ['announcements_source_enabled' => 1, 'announcements_source_term' => 'Platform Bulletins'],
      $announcements,
    );

    $this->assertSame(
      ['announcements_source_enabled' => TRUE, 'announcements_source_term' => 'Platform Bulletins'],
      $written,
    );
  }

  /**
   * A cleared tag field is stored as the default, never as an empty string.
   *
   * An empty tag would leave AnnouncementsFeedController falling back to the
   * default name for a term nothing guarantees exists - the endpoint would
   * answer 200 with zero items and no warning.
   *
   * @covers ::submitSettings
   */
  public function testSubmitNormalizesClearedTagToTheDefault(): void {
    $written = [];
    $this->submit(
      $written,
      ['announcements_source_enabled' => TRUE, 'announcements_source_term' => 'Platform News'],
      ['announcements_source_enabled' => 1, 'announcements_source_term' => '   '],
    );

    $this->assertSame(
      AnnouncementsSourcePlatformAdminSetting::DEFAULT_TERM,
      $written['announcements_source_term'],
    );
  }

  /**
   * A tag deleted out from under the config is recreated on the next save.
   *
   * The short-circuit skips the config write when nothing changed, but the tag
   * check has to run anyway: it is the one thing here that something else can
   * remove. Without this the endpoint would answer 200 with zero items and
   * re-saving the page would not fix it.
   *
   * @covers ::submitSettings
   * @covers ::ensureAnnouncementTerm
   */
  public function testSubmitRecreatesDeletedTagEvenWhenNothingChanged(): void {
    $term_storage = $this->createMock(EntityStorageInterface::class);
    $term_storage->method('loadByProperties')->willReturn([]);
    $term_storage->expects($this->once())->method('create')->with([
      'vid' => 'tags',
      'name' => 'Platform News',
    ])->willReturn($this->createMock('Drupal\taxonomy\TermInterface'));

    $written = [];
    $this->submit(
      $written,
      ['announcements_source_enabled' => TRUE, 'announcements_source_term' => 'Platform News'],
      ['announcements_source_enabled' => 1, 'announcements_source_term' => 'Platform News'],
      NULL,
      $this->entityTypeManager($term_storage),
    );

    // Still no config write - only the tag was missing.
    $this->assertSame([], $written);
  }

  /**
   * An existing but unpublished tag is called out rather than passed over.
   *
   * The lookup here sees it, so no duplicate is created, but the feed's
   * access-checked query does not - so the endpoint would return nothing with
   * no indication why.
   *
   * @covers ::ensureAnnouncementTerm
   */
  public function testSubmitWarnsWhenTheExistingTagIsUnpublished(): void {
    $unpublished = $this->createMock('Drupal\taxonomy\TermInterface');
    $unpublished->method('isPublished')->willReturn(FALSE);

    $term_storage = $this->createMock(EntityStorageInterface::class);
    $term_storage->method('loadByProperties')->willReturn([$unpublished]);
    $term_storage->expects($this->never())->method('create');

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addWarning');

    $written = [];
    $plugin = $this->plugin(
      $this->trackingConfigFactory($written, [
        'announcements_source_enabled' => TRUE,
        'announcements_source_term' => 'Platform News',
      ]),
      NULL,
      $this->entityTypeManager($term_storage),
      $messenger,
    );

    $form_state = new FormState();
    $form_state->setValues([
      self::PLUGIN_ID => [
        'announcements_source_enabled' => 1,
        'announcements_source_term' => 'Platform News',
      ],
    ]);

    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * Saving an unrelated section does not rewrite this one.
   *
   * The page has a single Save button, so submitSettings() runs on every save.
   * Rewriting the same values would invalidate the config cache tag - and drop
   * the cached feed, forcing a refetch - for a save that never touched these
   * fields.
   *
   * @covers ::submitSettings
   */
  public function testSubmitSkipsTheWriteWhenNothingChanged(): void {
    $written = [];
    $announcements = $this->createMock(DashboardAnnouncements::class);
    $announcements->expects($this->never())->method('clearCache');

    $this->submit(
      $written,
      ['announcements_source_enabled' => TRUE, 'announcements_source_term' => 'Platform News'],
      ['announcements_source_enabled' => 1, 'announcements_source_term' => 'Platform News'],
      $announcements,
    );

    $this->assertSame([], $written);
  }

  /**
   * Enabling publishing creates the tag when it does not exist yet.
   *
   * Without it the endpoint would answer 200 with zero items until someone
   * added the term by hand.
   *
   * @covers ::submitSettings
   * @covers ::ensureAnnouncementTerm
   */
  public function testSubmitCreatesTheTagWhenPublishingIsEnabled(): void {
    $term_storage = $this->createMock(EntityStorageInterface::class);
    $term_storage->method('loadByProperties')->willReturn([]);
    $term_storage->expects($this->once())->method('create')->with([
      'vid' => 'tags',
      'name' => 'Platform News',
    ])->willReturn($this->createMock('Drupal\taxonomy\TermInterface'));

    $written = [];
    $this->submit(
      $written,
      ['announcements_source_enabled' => FALSE, 'announcements_source_term' => ''],
      ['announcements_source_enabled' => 1, 'announcements_source_term' => 'Platform News'],
      NULL,
      $this->entityTypeManager($term_storage),
    );
  }

  /**
   * Turning publishing off does not create a tag nobody will use.
   *
   * @covers ::submitSettings
   */
  public function testSubmitDoesNotCreateTagWhenPublishingIsOff(): void {
    $term_storage = $this->createMock(EntityStorageInterface::class);
    $term_storage->method('loadByProperties')->willReturn([]);
    $term_storage->expects($this->never())->method('create');

    $written = [];
    $this->submit(
      $written,
      ['announcements_source_enabled' => TRUE, 'announcements_source_term' => 'Platform News'],
      ['announcements_source_enabled' => 0, 'announcements_source_term' => 'Platform News'],
      NULL,
      $this->entityTypeManager($term_storage),
    );

    $this->assertFalse($written['announcements_source_enabled']);
  }

  /**
   * Runs submitSettings() against the given stored and submitted values.
   *
   * @param array $written
   *   Populated with each config key/value the plugin writes, by reference.
   * @param array $stored
   *   The config values in place before the submit.
   * @param array $submitted
   *   The values arriving from the form, un-namespaced.
   * @param \Drupal\ys_core\DashboardAnnouncements|null $announcements
   *   The announcements service double, when the test asserts on it.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface|null $entity_type_manager
   *   The entity type manager double, when the test asserts on it.
   */
  private function submit(
    array &$written,
    array $stored,
    array $submitted,
    ?DashboardAnnouncements $announcements = NULL,
    ?EntityTypeManagerInterface $entity_type_manager = NULL,
  ): void {
    $plugin = $this->plugin(
      $this->trackingConfigFactory($written, $stored),
      $announcements,
      $entity_type_manager,
    );

    $form_state = new FormState();
    $form_state->setValues([self::PLUGIN_ID => $submitted]);

    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * Builds the plugin with the given collaborators.
   */
  private function plugin(
    ConfigFactoryInterface $config_factory,
    ?DashboardAnnouncements $announcements = NULL,
    ?EntityTypeManagerInterface $entity_type_manager = NULL,
    ?MessengerInterface $messenger = NULL,
  ): AnnouncementsSourcePlatformAdminSetting {
    $plugin = new AnnouncementsSourcePlatformAdminSetting(
      [],
      self::PLUGIN_ID,
      [],
      $config_factory,
      $this->createMock(AccountInterface::class),
      $announcements ?? $this->createMock(DashboardAnnouncements::class),
      $entity_type_manager ?? $this->entityTypeManager($this->existingTermStorage()),
      $messenger ?? $this->createMock(MessengerInterface::class),
    );
    $plugin->setStringTranslation($this->getStringTranslationStub());

    return $plugin;
  }

  /**
   * A term storage double reporting the tag already exists.
   *
   * The default for tests that are not asserting on tag creation, so
   * ensureAnnouncementTerm() short-circuits instead of reaching create().
   */
  private function existingTermStorage(): EntityStorageInterface {
    $term = $this->createMock('Drupal\taxonomy\TermInterface');
    $term->method('isPublished')->willReturn(TRUE);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([$term]);

    return $storage;
  }

  /**
   * An entity type manager whose Tags vocabulary exists.
   */
  private function entityTypeManager(EntityStorageInterface $term_storage): EntityTypeManagerInterface {
    $vocab_storage = $this->createMock(EntityStorageInterface::class);
    $vocab_storage->method('load')->with('tags')->willReturn($this->createMock('Drupal\taxonomy\VocabularyInterface'));

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnMap([
      ['taxonomy_vocabulary', $vocab_storage],
      ['taxonomy_term', $term_storage],
    ]);

    return $manager;
  }

  /**
   * A config factory reporting the given stored values, read only.
   */
  private function readOnlyConfigFactory(array $stored): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn(string $key) => $stored[$key] ?? NULL);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with(AnnouncementsSourcePlatformAdminSetting::CONFIG_NAME)
      ->willReturn($config);

    return $factory;
  }

  /**
   * A config factory whose editable config records every set() call.
   *
   * @param array $written
   *   Populated with each set() key/value pair, by reference.
   * @param array $stored
   *   The values get() should report, simulating the pre-submit config state.
   */
  private function trackingConfigFactory(array &$written, array $stored): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn(string $key) => $stored[$key] ?? NULL);
    $config->method('set')->willReturnCallback(function (string $key, $value) use (&$written, $config) {
      $written[$key] = $value;
      return $config;
    });
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')
      ->with(AnnouncementsSourcePlatformAdminSetting::CONFIG_NAME)
      ->willReturn($config);

    return $factory;
  }

}
