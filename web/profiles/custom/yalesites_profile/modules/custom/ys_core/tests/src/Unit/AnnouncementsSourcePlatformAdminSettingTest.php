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
   * The categories field renders as a textarea, one stored value per line.
   *
   * @covers ::buildSettings
   */
  public function testBuildRendersCategoriesTextareaFromConfig(): void {
    $plugin = $this->plugin($this->readOnlyConfigFactory([
      'announcements_source_categories' => ['Alpha', 'Beta'],
    ]));

    $form = $plugin->buildSettings([], new FormState());

    $this->assertSame('textarea', $form['announcements_source_categories']['#type']);
    $this->assertSame("Alpha\nBeta", $form['announcements_source_categories']['#default_value']);
  }

  /**
   * A never-saved categories key falls back to the platform defaults.
   *
   * This is the normal state on every existing site: config_ignore keeps
   * `ys_core.dashboard_settings` from being created/merged by config:import,
   * so the key reads NULL until a platform admin saves this section once.
   *
   * @covers ::buildSettings
   */
  public function testBuildFallsBackToDefaultCategoriesWhenNeverSaved(): void {
    $plugin = $this->plugin($this->readOnlyConfigFactory([]));

    $form = $plugin->buildSettings([], new FormState());

    $this->assertSame(
      implode("\n", AnnouncementsSourcePlatformAdminSetting::DEFAULT_CATEGORIES),
      $form['announcements_source_categories']['#default_value'],
    );
  }

  /**
   * An explicitly-stored empty list displays as a blank textarea, not defaults.
   *
   * A platform admin who deliberately cleared the whitelist to turn off
   * category pills entirely must see that choice reflected back, not the
   * three examples - otherwise the form would look like their edit never
   * took effect.
   *
   * @covers ::buildSettings
   */
  public function testBuildShowsBlankTextareaWhenExplicitlyEmptyCategoriesStored(): void {
    $plugin = $this->plugin($this->readOnlyConfigFactory([
      'announcements_source_categories' => [],
    ]));

    $form = $plugin->buildSettings([], new FormState());

    $this->assertSame('', $form['announcements_source_categories']['#default_value']);
  }

  /**
   * The categories field's #states selector matches the tag field's.
   *
   * @covers ::buildSettings
   */
  public function testCategoriesFieldIsHiddenUntilPublishingIsEnabled(): void {
    $plugin = $this->plugin($this->readOnlyConfigFactory([]));

    $form = $plugin->buildSettings([], new FormState());

    $this->assertSame(
      [':input[name="announcements_source[announcements_source_enabled]"]' => ['checked' => TRUE]],
      $form['announcements_source_categories']['#states']['visible'],
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
      [
        'announcements_source_enabled' => TRUE,
        'announcements_source_term' => 'Platform News',
        'announcements_source_categories' => AnnouncementsSourcePlatformAdminSetting::DEFAULT_CATEGORIES,
      ],
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
      [
        'announcements_source_enabled' => TRUE,
        'announcements_source_term' => 'Platform Bulletins',
        'announcements_source_categories' => AnnouncementsSourcePlatformAdminSetting::DEFAULT_CATEGORIES,
      ],
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
   * A multi-line categories submission is parsed and written as an array.
   *
   * @covers ::submitSettings
   */
  public function testSubmitWritesCategoriesArrayFromMultilineInput(): void {
    $written = [];
    $this->submit(
      $written,
      [
        'announcements_source_enabled' => TRUE,
        'announcements_source_term' => 'Platform News',
        'announcements_source_categories' => ['Alpha'],
      ],
      [
        'announcements_source_enabled' => 1,
        'announcements_source_term' => 'Platform News',
        'announcements_source_categories' => "Alpha\nBeta\n\n",
      ],
    );

    $this->assertSame(['Alpha', 'Beta'], $written['announcements_source_categories']);
  }

  /**
   * Clearing the categories field to blank writes an explicit empty list.
   *
   * This is the case a naive copy of the tag field's "empty falls back to
   * the default" normalization would get wrong: for categories, an
   * intentional empty list means "show no pills," not "use the defaults,"
   * so it must persist as `[]` rather than comparing equal to the shown
   * default and silently failing to save.
   *
   * @covers ::submitSettings
   */
  public function testSubmitWritesExplicitEmptyCategoriesWhenCleared(): void {
    $written = [];
    $this->submit(
      $written,
      [
        'announcements_source_enabled' => TRUE,
        'announcements_source_term' => 'Platform News',
        // Categories key absent from stored config: never saved, so
        // buildSettings() would have shown the platform defaults.
      ],
      [
        'announcements_source_enabled' => 1,
        'announcements_source_term' => 'Platform News',
        'announcements_source_categories' => '   ',
      ],
    );

    $this->assertSame([], $written['announcements_source_categories']);
  }

  /**
   * Resubmitting the shown default categories unchanged skips the write.
   *
   * Mirrors testSubmitSkipsTheWriteWhenUnsetValuesAreResubmittedAsShown for
   * the tag field: a site that never saved this section displays the
   * platform defaults, and saving some other section on the same page must
   * not turn that display into an explicit config value.
   *
   * @covers ::submitSettings
   */
  public function testSubmitSkipsTheWriteWhenUnsetCategoriesAreResubmittedAsShown(): void {
    $written = [];
    $announcements = $this->createMock(DashboardAnnouncements::class);
    $announcements->expects($this->never())->method('clearCache');

    $this->submit(
      $written,
      [
        'announcements_source_enabled' => TRUE,
        'announcements_source_term' => 'Platform News',
      ],
      [
        'announcements_source_enabled' => 1,
        'announcements_source_term' => 'Platform News',
        'announcements_source_categories' => implode("\n", AnnouncementsSourcePlatformAdminSetting::DEFAULT_CATEGORIES),
      ],
      $announcements,
    );

    $this->assertSame([], $written);
  }

  /**
   * A category with no matching Post Categories term is flagged.
   *
   * Nothing else surfaces this: the post still publishes, the feed still
   * returns 200, and the category simply never appears as a pill - identical
   * to "correctly configured, no post uses that category." The warning is
   * the only place this distinction becomes visible.
   *
   * @covers ::submitSettings
   * @covers ::warnAboutUnmatchedCategories
   */
  public function testSubmitWarnsWhenCategoryMatchesNoExistingTerm(): void {
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addWarning');

    $written = [];
    $this->submit(
      $written,
      [
        'announcements_source_enabled' => TRUE,
        'announcements_source_term' => 'Platform News',
        'announcements_source_categories' => ['Alumni'],
      ],
      [
        'announcements_source_enabled' => 1,
        'announcements_source_term' => 'Platform News',
        'announcements_source_categories' => "Alumni\nOff List",
      ],
      NULL,
      $this->entityTypeManagerWithCategoryVocabulary($this->termStorageWithCategories(['Alumni'])),
      $messenger,
    );
  }

  /**
   * Categories that all match existing terms are not flagged.
   *
   * @covers ::submitSettings
   * @covers ::warnAboutUnmatchedCategories
   */
  public function testSubmitDoesNotWarnWhenAllCategoriesMatchExistingTerms(): void {
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->never())->method('addWarning');

    $written = [];
    $this->submit(
      $written,
      [
        'announcements_source_enabled' => TRUE,
        'announcements_source_term' => 'Platform News',
        'announcements_source_categories' => ['Alumni'],
      ],
      [
        'announcements_source_enabled' => 1,
        'announcements_source_term' => 'Platform News',
        // Case-insensitive match against the existing "Alumni" term.
        'announcements_source_categories' => 'alumni',
      ],
      NULL,
      $this->entityTypeManagerWithCategoryVocabulary($this->termStorageWithCategories(['Alumni'])),
      $messenger,
    );
  }

  /**
   * An unrelated save never re-checks an already-mismatched whitelist.
   *
   * Same reasoning as the tag's unpublished/missing-vocabulary warnings:
   * gated on the categories themselves having changed, so an admin editing
   * only the tag or the enabled toggle is not renagged every save about a
   * pre-existing mismatch there is nothing new for them to act on.
   *
   * @covers ::submitSettings
   * @covers ::warnAboutUnmatchedCategories
   */
  public function testSubmitDoesNotWarnAboutCategoriesWhenNothingChanged(): void {
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->never())->method('addWarning');

    $written = [];
    $this->submit(
      $written,
      [
        'announcements_source_enabled' => TRUE,
        'announcements_source_term' => 'Platform News',
        'announcements_source_categories' => ['Off List'],
      ],
      [
        'announcements_source_enabled' => 1,
        // Only the term changed; categories are resubmitted unchanged.
        'announcements_source_term' => 'Platform Bulletins',
        'announcements_source_categories' => 'Off List',
      ],
      NULL,
      // "Alumni" is the only real term, so "Off List" would warn if the
      // gate did not hold.
      $this->entityTypeManagerWithCategoryVocabulary($this->termStorageWithCategories(['Alumni'])),
      $messenger,
    );
  }

  /**
   * A missing Post Categories vocabulary is reported, not silently ignored.
   *
   * @covers ::warnAboutUnmatchedCategories
   */
  public function testSubmitWarnsWhenThePostCategoryVocabularyIsMissing(): void {
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addWarning');

    $written = [];
    $this->submit(
      $written,
      ['announcements_source_enabled' => TRUE, 'announcements_source_term' => 'Platform News'],
      [
        'announcements_source_enabled' => 1,
        'announcements_source_term' => 'Platform News',
        'announcements_source_categories' => 'Feature release',
      ],
      NULL,
      $this->entityTypeManagerWithCategoryVocabulary($this->termStorageWithCategories([]), post_category_vocabulary_exists: FALSE),
      $messenger,
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
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addWarning');

    // A real edit to this section - renaming the tag - so the warning is
    // reporting on something the admin just did.
    $this->submitWithUnpublishedTag($messenger, 'Platform News', 'Campus News');
  }

  /**
   * The unpublished-tag warning does not re-fire on an unrelated save.
   *
   * The self-heal in ensureAnnouncementTerm() deliberately runs ahead of the
   * no-change short-circuit so a deleted tag is restored on every save. That
   * has to keep happening, but it must not turn into a Tags warning on every
   * save of the page: a platform admin editing only the Beacon section would
   * otherwise be told about this section's tag each time, with nothing on
   * screen to act on.
   *
   * @covers ::ensureAnnouncementTerm
   */
  public function testSubmitDoesNotRepeatTheUnpublishedTagWarningOnAnUnrelatedSave(): void {
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->never())->method('addWarning');

    // Stored and submitted match, so this save left the section alone.
    $this->submitWithUnpublishedTag($messenger, 'Platform News', 'Platform News');
  }

  /**
   * A missing Tags vocabulary is reported when the section is edited.
   *
   * @covers ::ensureAnnouncementTerm
   */
  public function testSubmitWarnsWhenTheTagsVocabularyIsMissing(): void {
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addWarning');

    $this->submitWithoutTagsVocabulary($messenger, 'Platform News', 'Campus News');
  }

  /**
   * The missing-vocabulary warning does not re-fire on an unrelated save.
   *
   * Same reasoning as the unpublished-tag warning: without the vocabulary there
   * is nothing this section can self-heal, so repeating the message on saves
   * that never touched it is pure noise.
   *
   * @covers ::ensureAnnouncementTerm
   */
  public function testSubmitDoesNotRepeatTheMissingVocabularyWarningOnAnUnrelatedSave(): void {
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->never())->method('addWarning');

    $this->submitWithoutTagsVocabulary($messenger, 'Platform News', 'Platform News');
  }

  /**
   * A tag deleted out from under an untouched section is still announced.
   *
   * The creation message reports an action that actually happened, so unlike
   * the warnings it is not gated on the section having changed - this is the
   * only signal that the self-heal fired.
   *
   * @covers ::ensureAnnouncementTerm
   */
  public function testSubmitAnnouncesTheRecreatedTagOnAnUnrelatedSave(): void {
    $term_storage = $this->createMock(EntityStorageInterface::class);
    $term_storage->method('loadByProperties')->willReturn([]);
    $term_storage->expects($this->once())->method('create')->with([
      'vid' => 'tags',
      'name' => 'Platform News',
    ])->willReturn($this->createMock('Drupal\taxonomy\TermInterface'));

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addStatus');

    $written = [];
    $stored = [
      'announcements_source_enabled' => TRUE,
      'announcements_source_term' => 'Platform News',
    ];
    $plugin = $this->plugin(
      $this->trackingConfigFactory($written, $stored),
      NULL,
      $this->entityTypeManager($term_storage),
      $messenger,
    );

    $form_state = new FormState();
    $form_state->setValues([
      self::PLUGIN_ID => $this->withUnchangedCategoriesDefault($stored, [
        'announcements_source_enabled' => 1,
        'announcements_source_term' => 'Platform News',
      ]),
    ]);

    $form = [];
    $plugin->submitSettings($form, $form_state);

    $this->assertSame([], $written);
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
   * An untouched save writes nothing when the config object does not exist yet.
   *
   * This is the normal state, not an edge case: config_ignore keeps
   * `ys_core.dashboard_settings` from being created by config:import, so on any
   * site that has never saved this section both keys read NULL. buildSettings()
   * then displays the DEFAULT_TERM fallback, and resubmitting exactly what was
   * displayed used to compare `'' === 'Dashboard Announcement'` and count as a
   * change - so a platform admin who edited only another section wrote both
   * keys and dropped the cached feed. Same shape bug that was fixed for the
   * branding and environment indicator keys.
   *
   * @covers ::submitSettings
   */
  public function testSubmitSkipsTheWriteWhenUnsetValuesAreResubmittedAsShown(): void {
    $written = [];
    $announcements = $this->createMock(DashboardAnnouncements::class);
    $announcements->expects($this->never())->method('clearCache');

    $this->submit(
      $written,
      // Neither key exists in config.
      [],
      // What buildSettings() puts on screen for that state.
      [
        'announcements_source_enabled' => 0,
        'announcements_source_term' => AnnouncementsSourcePlatformAdminSetting::DEFAULT_TERM,
      ],
      $announcements,
    );

    $this->assertSame([], $written);
  }

  /**
   * A blank stored tag is treated as the default it is displayed as.
   *
   * Both config/install and config/sync ship the key, but an empty string is
   * reachable - and buildSettings() shows DEFAULT_TERM for it - so the two
   * sides have to agree here too.
   *
   * @covers ::submitSettings
   */
  public function testSubmitSkipsTheWriteWhenBlankStoredTagIsResubmittedAsShown(): void {
    $written = [];
    $announcements = $this->createMock(DashboardAnnouncements::class);
    $announcements->expects($this->never())->method('clearCache');

    $this->submit(
      $written,
      ['announcements_source_enabled' => FALSE, 'announcements_source_term' => ''],
      [
        'announcements_source_enabled' => 0,
        'announcements_source_term' => AnnouncementsSourcePlatformAdminSetting::DEFAULT_TERM,
      ],
      $announcements,
    );

    $this->assertSame([], $written);
  }

  /**
   * Normalizing the stored side must not swallow a real edit.
   *
   * The counterpart to the two tests above: with nothing stored, an admin who
   * actually names a tag and ticks the box has to be written.
   *
   * @covers ::submitSettings
   */
  public function testSubmitWritesWhenUnsetValuesAreGivenRealValues(): void {
    $written = [];
    $this->submit(
      $written,
      [],
      ['announcements_source_enabled' => 1, 'announcements_source_term' => 'Platform News'],
    );

    $this->assertTrue($written['announcements_source_enabled']);
    $this->assertSame('Platform News', $written['announcements_source_term']);
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
   * @param \Drupal\Core\Messenger\MessengerInterface|null $messenger
   *   The messenger double, when the test asserts on it.
   */
  private function submit(
    array &$written,
    array $stored,
    array $submitted,
    ?DashboardAnnouncements $announcements = NULL,
    ?EntityTypeManagerInterface $entity_type_manager = NULL,
    ?MessengerInterface $messenger = NULL,
  ): void {
    $plugin = $this->plugin(
      $this->trackingConfigFactory($written, $stored),
      $announcements,
      $entity_type_manager,
      $messenger,
    );

    $form_state = new FormState();
    $form_state->setValues([self::PLUGIN_ID => $this->withUnchangedCategoriesDefault($stored, $submitted)]);

    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * Fills in a categories submission matching the stored value, if omitted.
   *
   * Every real form submission includes every field on the page, so a test
   * exercising only the enabled/term fields still has to submit *something*
   * for categories - and it must be the value that already displays as
   * unchanged, or the test would start asserting on categories behavior it
   * never meant to cover. Callers that ARE testing the categories field pass
   * it explicitly in $submitted, which this leaves untouched.
   *
   * @param array $stored
   *   The config values in place before the submit.
   * @param array $submitted
   *   The values arriving from the form, un-namespaced.
   */
  private function withUnchangedCategoriesDefault(array $stored, array $submitted): array {
    return $submitted + [
      'announcements_source_categories' => implode(
        "\n",
        AnnouncementsSourcePlatformAdminSetting::resolveCategoryWhitelist($stored['announcements_source_categories'] ?? NULL),
      ),
    ];
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
   * Submits with an existing but unpublished tag in the Tags vocabulary.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger double carrying the test's expectation.
   * @param string $stored_term
   *   The tag name already in config.
   * @param string $submitted_term
   *   The tag name arriving from the form. Equal to $stored_term for a save
   *   that left this section alone.
   */
  private function submitWithUnpublishedTag(MessengerInterface $messenger, string $stored_term, string $submitted_term): void {
    $unpublished = $this->createMock('Drupal\taxonomy\TermInterface');
    $unpublished->method('isPublished')->willReturn(FALSE);

    $term_storage = $this->createMock(EntityStorageInterface::class);
    $term_storage->method('loadByProperties')->willReturn([$unpublished]);
    $term_storage->expects($this->never())->method('create');

    $this->submitTerms($messenger, $this->entityTypeManager($term_storage), $stored_term, $submitted_term);
  }

  /**
   * Submits on a site whose Tags vocabulary does not exist.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger double carrying the test's expectation.
   * @param string $stored_term
   *   The tag name already in config.
   * @param string $submitted_term
   *   The tag name arriving from the form.
   */
  private function submitWithoutTagsVocabulary(MessengerInterface $messenger, string $stored_term, string $submitted_term): void {
    $vocab_storage = $this->createMock(EntityStorageInterface::class);
    $vocab_storage->method('load')->with('tags')->willReturn(NULL);

    $term_storage = $this->createMock(EntityStorageInterface::class);
    $term_storage->expects($this->never())->method('loadByProperties');
    $term_storage->expects($this->never())->method('create');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnMap([
      ['taxonomy_vocabulary', $vocab_storage],
      ['taxonomy_term', $term_storage],
    ]);

    $this->submitTerms($messenger, $manager, $stored_term, $submitted_term);
  }

  /**
   * Submits publishing-enabled values with the given stored and submitted tag.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger double carrying the test's expectation.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager double describing the taxonomy state.
   * @param string $stored_term
   *   The tag name already in config.
   * @param string $submitted_term
   *   The tag name arriving from the form.
   */
  private function submitTerms(MessengerInterface $messenger, EntityTypeManagerInterface $entity_type_manager, string $stored_term, string $submitted_term): void {
    $written = [];
    $stored = [
      'announcements_source_enabled' => TRUE,
      'announcements_source_term' => $stored_term,
    ];
    $plugin = $this->plugin(
      $this->trackingConfigFactory($written, $stored),
      NULL,
      $entity_type_manager,
      $messenger,
    );

    $form_state = new FormState();
    $form_state->setValues([
      self::PLUGIN_ID => $this->withUnchangedCategoriesDefault($stored, [
        'announcements_source_enabled' => 1,
        'announcements_source_term' => $submitted_term,
      ]),
    ]);

    $form = [];
    $plugin->submitSettings($form, $form_state);
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
   *
   * Answers a load('post_category') too - rather than only recognizing
   * 'tags' - because submitSettings() now also runs
   * warnAboutUnmatchedCategories() whenever a test's categories value
   * changes, even in tests that are not exercising that warning themselves.
   * Reporting "no Post Categories vocabulary" (NULL) for those is harmless:
   * none of them assert on the messenger.
   */
  private function entityTypeManager(EntityStorageInterface $term_storage): EntityTypeManagerInterface {
    $vocab_storage = $this->createMock(EntityStorageInterface::class);
    $vocab_storage->method('load')->willReturnCallback(
      fn(string $vid) => $vid === 'tags' ? $this->createMock('Drupal\taxonomy\VocabularyInterface') : NULL,
    );

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnMap([
      ['taxonomy_vocabulary', $vocab_storage],
      ['taxonomy_term', $term_storage],
    ]);

    return $manager;
  }

  /**
   * A term storage double branching by vid: tags vs. Post Categories.
   *
   * EnsureAnnouncementTerm() and warnAboutUnmatchedCategories() both query
   * `taxonomy_term` storage in the same submit, for different vocabularies -
   * this fixture answers both queries distinctly instead of the generic
   * vid-blind existingTermStorage().
   *
   * @param string[] $category_term_names
   *   The Post Categories vocabulary's existing term names.
   */
  private function termStorageWithCategories(array $category_term_names): EntityStorageInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturnCallback(function (array $properties) use ($category_term_names) {
      if (($properties['vid'] ?? NULL) === 'post_category') {
        return array_map(function (string $name) {
          $term = $this->createMock('Drupal\taxonomy\TermInterface');
          $term->method('getName')->willReturn($name);
          return $term;
        }, $category_term_names);
      }
      // The tags lookup (from ensureAnnouncementTerm): report the tag as
      // already existing and published, so it short-circuits without
      // creating anything these tests are not asserting on.
      $tag_term = $this->createMock('Drupal\taxonomy\TermInterface');
      $tag_term->method('isPublished')->willReturn(TRUE);
      return [$tag_term];
    });

    return $storage;
  }

  /**
   * An entity type manager whose Tags and Post Categories vocabularies exist.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $term_storage
   *   The term storage double to return for the `taxonomy_term` entity type.
   * @param bool $post_category_vocabulary_exists
   *   Whether `taxonomy_vocabulary`::load('post_category') resolves.
   */
  private function entityTypeManagerWithCategoryVocabulary(
    EntityStorageInterface $term_storage,
    bool $post_category_vocabulary_exists = TRUE,
  ): EntityTypeManagerInterface {
    $vocab_storage = $this->createMock(EntityStorageInterface::class);
    $vocab_storage->method('load')->willReturnCallback(
      fn(string $vid) => $vid === 'post_category' && !$post_category_vocabulary_exists
        ? NULL
        : $this->createMock('Drupal\taxonomy\VocabularyInterface'),
    );

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
