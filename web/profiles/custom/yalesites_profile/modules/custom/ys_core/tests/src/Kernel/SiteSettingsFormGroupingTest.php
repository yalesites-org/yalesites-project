<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\ys_core\Form\SiteSettingsForm;
use Drupal\ys_core\PlatformAdminCheckerInterface;

/**
 * Tests that the Site Settings form is grouped into task-based vertical tabs.
 *
 * The form used to be a single flat run of fifteen unrelated settings with no
 * headings: site name a few fields above the 404 page, which sat above a Google
 * Search Console key, which sat above the font picker. Nothing told a user
 * which settings belonged together, which is the "users know what to change
 * but not where" discoverability problem the 2026 UX research names as the
 * platform's second most-cited pain point.
 *
 * The restructure is presentation-only, and that is exactly the part worth
 * pinning down. Each setting is nested inside its details group and '#tree' is
 * never set, so nesting rearranges only the render tree while every value still
 * arrives flat in the form state — submitForm() keeps reading 'site_name' and
 * friends unchanged.
 *
 * The first attempt tagged each setting with '#group' instead and left them at
 * the top level, which looked right and passed a test asserting '#group' was
 * set — but rendered the font pairing radios, the favicon, the teaser fallback
 * and the Tag Manager link *outside* the tabs. '#group' is only honoured by
 * element types that wire preRenderGroup (textfield, textarea, checkbox,
 * container, details, fieldset). That is why these assertions check where an
 * element actually lives rather than what it asked for, and why
 * testSettingsAreNestedRatherThanGroupTagged() exists at all.
 *
 * @group ys_core
 */
class SiteSettingsFormGroupingTest extends YsKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'path_alias',
    'file',
    'image',
    'taxonomy',
    'google_tag',
    // KernelTestBase does not resolve module dependencies, and the form injects
    // ys_media.media_manager, so ys_media has to be listed explicitly. Its own
    // imagemagick dependency is deliberately left out: the media manager
    // service needs only core services, and imagemagick cannot boot here
    // without sophron.
    'ys_media',
    'ys_core',
  ];

  /**
   * The expected group keys, in the order they should be displayed.
   *
   * Ordered by how often a user is likely to need them, not by the field order
   * the flat form happened to have.
   */
  const EXPECTED_GROUPS = [
    'site_basics',
    'key_pages',
    'look_and_feel',
    'search_and_analytics',
    'content_and_tagging',
    'advanced',
  ];

  /**
   * The expected group for each setting, in expected display order.
   */
  const EXPECTED_MEMBERSHIP = [
    'site_name' => 'site_basics',
    'site_mail' => 'site_basics',
    'site_page_front' => 'key_pages',
    'site_page_posts' => 'key_pages',
    'site_page_events' => 'key_pages',
    'site_page_403' => 'key_pages',
    'site_page_404' => 'key_pages',
    'font_pairing' => 'look_and_feel',
    'font_preview' => 'look_and_feel',
    'favicon' => 'look_and_feel',
    'teaser_image_fallback' => 'look_and_feel',
    'google_site_verification' => 'search_and_analytics',
    'google_analytics_migration' => 'search_and_analytics',
    'custom_vocab_name' => 'content_and_tagging',
    'cas_app_name' => 'advanced',
  ];

  /**
   * {@inheritdoc}
   *
   * Strict schema checking is off because ys_core.site ships no config schema.
   * Writing that schema is explicitly not test-only work and is tracked in
   * yalesites-org/YaleSites-Internal#579: as this module's README records, no
   * schema type validates both the install defaults and the real saved values
   * without first correcting them (custom_favicon is declared '' but holds an
   * array of file ids, and a site that saved environment_indicator.show through
   * this form before it moved holds integer 1 against a declared boolean).
   * Suppressed the same way the six sibling settings-form kernel tests in this
   * profile do, rather than pre-empting that ticket.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('taxonomy_vocabulary');
    $this->installConfig(['system']);

    // ys_core's config/install cannot be imported wholesale here: its Grand
    // Hero list_string field storage still carries pre-D10 allowed_values
    // ("text: {text: Text}" rather than "text: Text"), which core's list field
    // type rejects with "Undefined array key label". That is a real but
    // separate problem. Loading just the settings object this form reads, from
    // the same shipped file, keeps the defaults under test the genuine ones.
    $this->config('ys_core.site')->setData(Yaml::decode(
      file_get_contents(
        DRUPAL_ROOT . '/' . $this->container->get('extension.list.module')->getPath('ys_core')
        . '/config/install/ys_core.site.yml'
      )
    ))->save();
  }

  /**
   * Builds the Site Settings form array as the given user.
   */
  protected function buildFormAs(int $uid): array {
    $this->setUpCurrentUser(['uid' => $uid]);
    return $this->formObject()->buildForm([], new FormState());
  }

  /**
   * Instantiates the form object from the container.
   */
  protected function formObject(): SiteSettingsForm {
    return SiteSettingsForm::create($this->container);
  }

  /**
   * The settings found nested under the groups, in display order.
   */
  protected function nestedSettingKeys(array $form): array {
    $keys = [];
    foreach (self::EXPECTED_GROUPS as $group) {
      foreach (array_keys($form[$group] ?? []) as $key) {
        if (!str_starts_with((string) $key, '#')) {
          $keys[] = $key;
        }
      }
    }
    return $keys;
  }

  /**
   * Top-level keys that are form scaffolding rather than a site setting.
   */
  protected function scaffoldingKeys(): array {
    return array_merge(['vertical_tabs', 'actions'], self::EXPECTED_GROUPS);
  }

  /**
   * Every group is a details element inside the one tab container.
   */
  public function testGroupsRenderAsVerticalTabs(): void {
    $form = $this->buildFormAs(1);

    $this->assertSame('vertical_tabs', $form['vertical_tabs']['#type']);

    foreach (self::EXPECTED_GROUPS as $group) {
      $this->assertArrayHasKey($group, $form, "Group $group is missing.");
      $this->assertSame('details', $form[$group]['#type'], "Group $group is not a details element.");
      $this->assertSame('vertical_tabs', $form[$group]['#group'], "Group $group is not inside the tab container.");
      $this->assertNotEmpty((string) $form[$group]['#title'], "Group $group has no title.");
    }

    $group_keys = array_values(array_intersect(array_keys($form), self::EXPECTED_GROUPS));
    $this->assertSame(self::EXPECTED_GROUPS, $group_keys, 'Groups are not in the intended reading order.');
  }

  /**
   * Nothing was dropped in the move and nothing was left outside the tabs.
   *
   * An ungrouped setting renders orphaned above the first tab, so this doubles
   * as a guard for the next setting somebody appends to this form.
   */
  public function testEverySettingBelongsToItsIntendedGroup(): void {
    $form = $this->buildFormAs(1);

    $this->assertSame(
      array_keys(self::EXPECTED_MEMBERSHIP),
      $this->nestedSettingKeys($form),
      'The set or order of settings on the form changed.'
    );

    foreach (self::EXPECTED_MEMBERSHIP as $key => $group) {
      $this->assertArrayHasKey($key, $form[$group], "Setting $key is not inside $group.");
    }

    // Nothing may be left stranded at the top level, where it would render
    // above the first tab instead of inside one.
    $stranded = array_diff(
      array_filter(array_keys($form), fn($k) => !str_starts_with((string) $k, '#')),
      $this->scaffoldingKeys()
    );
    $this->assertSame([], array_values($stranded), 'Settings were left outside the tabs.');
  }

  /**
   * Nesting must not repoint a single config write.
   *
   * This is the assertion the whole change rests on. Nesting is only safe
   * because '#tree' is never set: with it set, a nested element's '#parents'
   * would become ['group', 'key'] and submitForm()'s flat getValue() calls
   * would every one of them read NULL, silently blanking the site's settings.
   *
   * Asserting the absence of '#tree' would pass vacuously — the code never
   * mentions it — and would keep passing if a child element declared '#tree'
   * itself or a wrapper's getInfo() set it. So this submits the form for real
   * and checks where each value landed.
   */
  public function testSubmittingStillWritesEveryValueToItsOwnConfigKey(): void {
    $form = $this->buildFormAs(1);

    $form_state = new FormState();
    $form_state->setValues([
      'site_name' => 'Grouped Settings Site',
      'site_mail' => 'someone@yale.edu',
      // submitForm() only concatenates this into '/node/<id>'; it never loads
      // the node, so a bare id is enough and no bundle setup is needed.
      'site_page_front' => 42,
      'site_page_posts' => '/news',
      'site_page_events' => '/happenings',
      'site_page_403' => '/no-entry',
      'site_page_404' => '/gone',
      'google_site_verification' => 'verification-key',
      'custom_vocab_name' => 'Custom Vocab',
      'font_pairing' => 'mallory',
      'teaser_image_fallback' => '',
      // handleMediaFilesystem() only dereferences this when it is truthy.
      'favicon' => [],
      'cas_app_name' => 'yalesites',
    ]);
    $this->formObject()->submitForm($form, $form_state);

    $site = $this->config('system.site');
    $this->assertSame('Grouped Settings Site', $site->get('name'));
    $this->assertSame('someone@yale.edu', $site->get('mail'));
    $this->assertSame('/node/42', $site->get('page.front'));
    $this->assertSame('/no-entry', $site->get('page.403'));
    $this->assertSame('/gone', $site->get('page.404'));

    $yale = $this->config('ys_core.site');
    $this->assertSame('/news', $yale->get('page.posts'));
    $this->assertSame('/happenings', $yale->get('page.events'));
    $this->assertSame(
      'verification-key',
      $yale->get('seo.google_site_verification')
    );
    $this->assertSame('Custom Vocab', $yale->get('taxonomy.custom_vocab_name'));
    $this->assertSame('mallory', $yale->get('font_pairing'));
    $this->assertSame('yalesites', $yale->get('cas_app_name'));
  }

  /**
   * Settings must be nested, not merely tagged with '#group'.
   *
   * Tagging is the trap this form already fell into once: '#group' is silently
   * ignored by radios, managed_file, media_library and item, so the font
   * pairing, favicon, teaser fallback and Tag Manager link rendered outside the
   * tabs while every '#group' assertion still passed.
   */
  public function testSettingsAreNestedRatherThanGroupTagged(): void {
    $form = $this->buildFormAs(1);

    foreach (self::EXPECTED_MEMBERSHIP as $key => $group) {
      $this->assertArrayNotHasKey(
        '#group',
        $form[$group][$key],
        "Setting $key relies on #group, which its element type may ignore."
      );
    }
  }

  /**
   * The form id is contract with the admin theme and must not drift.
   *
   * FormBuilder turns it into the form's ys-admin-settings class, which
   * ys_admin_theme's gin-custom.scss targets to constrain input widths.
   */
  public function testFormIdIsUnchanged(): void {
    $this->assertSame('ys_admin_settings', $this->formObject()->getFormId());
  }

  /**
   * Labels drop Drupal's jargon for words an untrained user recognises.
   */
  public function testPlainLanguageLabels(): void {
    $form = $this->buildFormAs(1);

    $this->assertSame('Homepage', (string) $form['key_pages']['site_page_front']['#title']);
    $this->assertSame('Access denied page (403)', (string) $form['key_pages']['site_page_403']['#title']);
    // The description was reworded with the label: it used to say "front page".
    $this->assertStringNotContainsString(
      'front page',
      (string) $form['key_pages']['site_page_front']['#description']
    );
    $this->assertSame('Page not found (404)', (string) $form['key_pages']['site_page_404']['#title']);

    // "Teaser" is confusing but used consistently platform-wide — three node
    // field descriptions in config/sync name it — so renaming it here alone
    // would make things worse. The description carries the explanation instead.
    $this->assertSame('Fallback teaser image', (string) $form['look_and_feel']['teaser_image_fallback']['#title']);
    $this->assertStringContainsString(
      'no teaser image',
      (string) $form['look_and_feel']['teaser_image_fallback']['#description']
    );
  }

  /**
   * An Advanced tab with nothing openable inside is worse than no tab.
   */
  public function testAdvancedGroupIsHiddenWhenItWouldBeEmpty(): void {
    $form = $this->buildFormAs(2);

    $this->assertFalse($form['advanced']['#access']);
    $this->assertFalse($form['advanced']['cas_app_name']['#access']);
  }

  /**
   * A platform admin who is not user 1 must not get an empty Advanced tab.
   *
   * The group used to hold two settings - the CAS application name, user 1
   * only, and the environment indicator, every platform admin - so it was gated
   * at the platform admin level. The environment indicator has since moved to
   * the Platform Admin Settings page (yalesites-org/YaleSites-Internal#1560),
   * leaving only the narrower field, so the old gate would hand a platform
   * admin a tab with nothing openable inside it (#1590).
   *
   * A plain user fails both gates, so a platform admin who is not user 1 is the
   * only account that can tell a user 1 gate from a platform admin one. The
   * account has to be a platform admin by BOTH mechanisms for that to hold:
   * setUpCurrentUser()'s permissions argument invents a role with a random
   * machine name, which no role-based gate would match, so the role is created
   * under its real name and assigned through 'roles'. The hasPermission()
   * assertion is what stops this setup decaying into a duplicate of the
   * plain-user case above.
   *
   * Asserting the emptiness alongside the access is what keeps the rest honest.
   * The group's gate is derived from its visible children, so the pair says
   * "hidden BECAUSE nothing in it is reachable" rather than pinning today's
   * answer - and the day a platform-admin-level setting is added here, this
   * starts failing instead of quietly certifying a tab nobody can open.
   */
  public function testAdvancedGroupIsHiddenFromPlatformAdminsWhoAreNotUser1(): void {
    $this->createRole([PlatformAdminCheckerInterface::PERMISSION], 'platform_admin');
    $account = $this->setUpCurrentUser(['uid' => 3, 'roles' => ['platform_admin']]);
    $this->assertTrue($account->hasPermission(PlatformAdminCheckerInterface::PERMISSION));

    $form = $this->formObject()->buildForm([], new FormState());

    $this->assertSame(
      [],
      Element::getVisibleChildren($form['advanced']),
      'Nothing in Advanced is reachable by a platform admin, so hiding it is the only correct answer.'
    );
    $this->assertFalse(
      $form['advanced']['#access'],
      'A platform admin who is not user 1 was offered an empty Advanced tab.'
    );
    // The field's own gate is what #1560 is about, and this is the only user
    // class it was not already pinned for.
    $this->assertFalse($form['advanced']['cas_app_name']['#access']);
  }

  /**
   * User 1 still sees the Advanced group, and only what is left in it.
   *
   * The other half of the derived rule: a group with something reachable in it
   * must stay reachable.
   *
   * The environment indicator is asserted gone from the form rather than merely
   * hidden, because a hidden element still submits its default - which would
   * overwrite whatever a platform admin set on the Platform Admin Settings
   * page.
   */
  public function testAdvancedGroupIsVisibleToUser1(): void {
    $form = $this->buildFormAs(1);

    $this->assertSame(['cas_app_name'], Element::getVisibleChildren($form['advanced']));
    $this->assertTrue($form['advanced']['#access']);
    $this->assertTrue($form['advanced']['cas_app_name']['#access']);
    $this->assertArrayNotHasKey('environment_indicator_show', $form['advanced']);
  }

  /**
   * A user must never be told there is a problem with no field in sight.
   *
   * Core's vertical-tabs.js opens the tab holding a '.error' field, but only
   * once JS has run. Opening the group server-side means the reveal also holds
   * without JS and before behaviours attach, and it is what makes the behaviour
   * assertable here rather than only in a browser.
   */
  public function testValidationErrorOpensItsOwnGroup(): void {
    $form = $this->buildFormAs(1);

    // Calling buildForm() directly skips form processing, so supply the
    // '#parents' FormBuilder would have added: validateForm() hands
    // $form['site_page_404'] to setValueForElement(), which reads it.
    foreach (self::EXPECTED_MEMBERSHIP as $key => $group) {
      if (isset($form[$group][$key])) {
        $form[$group][$key]['#parents'] = [$key];
      }
    }

    $form_state = new FormState();
    // A path with no leading slash is rejected by validateStartWithSlash().
    $form_state->setValues([
      'site_page_404' => 'not-a-path',
      'site_mail' => 'someone@yale.edu',
    ]);
    $this->formObject()->validateForm($form, $form_state);

    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('site_page_404', $errors);

    // Four fields in this group share the same three path messages, so the
    // message has to say which one it means — revealing the tab is not enough
    // if the text could be about any of them.
    $this->assertStringContainsString(
      'Page not found (404)',
      (string) $errors['site_page_404'],
      'The path error does not name the field it belongs to.'
    );

    $this->assertTrue(
      $form['key_pages']['#open'] ?? FALSE,
      'The group holding the invalid field was left collapsed.'
    );
    $this->assertNotTrue(
      $form['look_and_feel']['#open'] ?? FALSE,
      'An unrelated group was opened.'
    );
  }

}
