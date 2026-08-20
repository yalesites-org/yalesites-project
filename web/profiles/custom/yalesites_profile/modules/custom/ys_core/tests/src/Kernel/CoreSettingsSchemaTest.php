<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\SchemaCheckTestTrait;

/**
 * Tests that ys_core's sitewide settings objects have a config schema.
 *
 * Config schema is what validates that a settings value is the shape it is
 * supposed to be. Without it these four objects are unvalidated in production,
 * so a bad value can be written and nothing catches it until something
 * downstream breaks.
 *
 * The values asserted here are deliberately the ones the settings forms
 * actually write, not the tidier shapes the install YAML used to declare: the
 * schema has to describe reality or it would reject real sites' stored config.
 * That is why `custom_favicon` and `site_name_image` are arrays of file IDs,
 * the `search` flags are integers, and the footer's logo/link collections are
 * sequences of mappings with potentially sparse keys.
 *
 * @group ys_core
 * @group yalesites
 */
class CoreSettingsSchemaTest extends KernelTestBase {

  use SchemaCheckTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'ys_core'];

  /**
   * The settings objects this module owns, beyond the dashboard settings.
   */
  private const SETTINGS = [
    'ys_core.site',
    'ys_core.header_settings',
    'ys_core.footer_settings',
    'ys_core.social_links',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Saving each settings object individually rather than installConfig(),
    // which would also install this module's grand_hero field config and so
    // drag block_content, field and options into a test about config schema.
    // Strict schema checking is on by default in kernel tests, so the save
    // itself is the gate the missing schema used to fail with
    // SchemaIncompleteException.
    foreach (self::SETTINGS as $name) {
      $this->config($name)->setData($this->installDefaults($name))->save();
    }
  }

  /**
   * Every settings object this module ships is covered by a schema.
   */
  public function testEverySettingsObjectHasSchema(): void {
    $typed = $this->container->get('config.typed');
    foreach (self::SETTINGS as $name) {
      $this->assertTrue(
        $typed->hasConfigSchema($name),
        "$name has no config schema, so its values are never validated."
      );
    }
  }

  /**
   * The shipped install defaults validate against the schema.
   */
  public function testInstallDefaultsValidate(): void {
    $typed = $this->container->get('config.typed');
    foreach (self::SETTINGS as $name) {
      $this->assertConfigSchema($typed, $name, $this->installDefaults($name));
    }
  }

  /**
   * Reads a settings object's shipped defaults from config/install.
   */
  private function installDefaults(string $name): array {
    $path = \Drupal::root() . '/'
      . \Drupal::service('extension.list.module')->getPath('ys_core')
      . '/config/install/' . $name . '.yml';

    return Yaml::decode(file_get_contents($path));
  }

  /**
   * Site settings validate in the shape SiteSettingsForm writes them.
   */
  public function testSiteSettingsAsSavedByTheFormValidate(): void {
    $this->assertSavedConfigValid('ys_core.site', [
      'page.posts' => '12',
      'page.events' => '13',
      'search.enable_search_form' => 1,
      'seo.google_site_verification' => 'abc123',
      'seo.google_analytics_id' => '',
      'image_fallback.teaser' => '7',
      'taxonomy.custom_vocab_name' => 'Custom Vocab',
      // A managed_file with #multiple => FALSE writes an array of file IDs.
      'custom_favicon' => [9],
      'font_pairing' => 'yalenew',
      'cas_app_name' => 'yalesites',
      'environment_indicator.show' => TRUE,
    ]);
  }

  /**
   * Header settings validate in the shape HeaderSettingsForm writes them.
   */
  public function testHeaderSettingsAsSavedByTheFormValidate(): void {
    $this->assertSavedConfigValid('ys_core.header_settings', [
      'header_variation' => 'focus',
      'nav_position' => 'center',
      'focus_header_image' => '42',
      'site_name_image' => [9],
      'site_wide_branding_name' => 'Yale University',
      'site_wide_branding_link' => 'https://www.yale.edu',
      'cta_content' => 'Apply now',
      'cta_url' => '/admissions',
      'dropdown_button_title' => 'More',
      'search.enable_search_form' => 1,
      'search.enable_cas_search' => 0,
      'search.enable_all_yale_search' => 0,
    ]);
  }

  /**
   * Footer settings validate in the shape FooterSettingsForm writes them.
   *
   * The logo and link collections come from a multivalue form element that
   * preserves its element keys, so a saved value can be sparsely indexed.
   */
  public function testFooterSettingsAsSavedByTheFormValidate(): void {
    $this->assertSavedConfigValid('ys_core.footer_settings', [
      'footer_variation' => 'mega',
      'content.logos' => [
        ['logo_url' => 'https://www.yale.edu', 'logo' => '3'],
        2 => ['logo_url' => NULL, 'logo' => '4'],
      ],
      'content.school_logo' => '5',
      'content.school_logo_url' => 'https://www.yale.edu',
      'content.text' => ['value' => '<p>Footer text</p>', 'format' => 'restricted_html'],
      'links.links_col_1_heading' => 'Resources',
      'links.links_col_2_heading' => '',
      'links.links_col_1' => [
        ['link_url' => '/about', 'link_title' => 'About'],
        3 => ['link_url' => 'https://www.yale.edu', 'link_title' => 'Yale'],
      ],
      'links.links_col_2' => [],
    ]);
  }

  /**
   * Social links validate for every network the platform offers.
   */
  public function testSocialLinksAsSavedByTheFormValidate(): void {
    $this->assertSavedConfigValid('ys_core.social_links', [
      'facebook' => 'https://facebook.com/yale',
      'instagram' => 'https://instagram.com/yale',
      // The stored key is hyphenated, unlike every other network id.
      'x-twitter' => 'https://x.com/yale',
      'youtube' => 'https://youtube.com/yale',
      'weibo' => '',
      'linkedin' => 'https://linkedin.com/school/yale-university',
      // Offered by SocialLinksManager::SITES but absent from the install
      // defaults, so it only ever appears once an editor saves the form.
      'bluesky' => 'https://bsky.app/profile/yale.edu',
    ]);
  }

  /**
   * A real site can hold NULL where the install default declares a string.
   *
   * Both of these are NULL on a pulled production database: the teaser fallback
   * when no media was ever chosen, and the focus header image once
   * _ys_core_clear_focus_header_image_config() clears a deleted reference.
   */
  public function testNullValuesFromRealSitesValidate(): void {
    $this->assertSavedConfigValid('ys_core.site', [
      'image_fallback.teaser' => NULL,
    ]);
    $this->assertSavedConfigValid('ys_core.header_settings', [
      'focus_header_image' => NULL,
    ]);
  }

  /**
   * The declared types are what comes back out, not merely what is accepted.
   *
   * Every other test here asserts the schema does not reject a form-shaped
   * value, which a wrong-but-permissive declaration would also satisfy. These
   * read-backs are the other half: Config::save() casts to the declared type,
   * so a form's string '1' returning as int 1 is what proves the integer,
   * boolean and sequence declarations are the types actually being stored.
   */
  public function testDeclaredTypesAreWhatIsStored(): void {
    $this->config('ys_core.site')
      // The shapes a Form API element hands over: a checkbox's string value,
      // an integer standing in for a boolean, and file IDs as strings.
      ->set('search.enable_search_form', '1')
      ->set('environment_indicator.show', 1)
      ->set('custom_favicon', ['9'])
      ->save();

    $site = $this->config('ys_core.site');
    $this->assertSame(1, $site->get('search.enable_search_form'));
    $this->assertSame(TRUE, $site->get('environment_indicator.show'));
    $this->assertSame([9], $site->get('custom_favicon'));
  }

  /**
   * Saves the given keys onto a config object and asserts the result validates.
   *
   * The save itself is half the assertion: with strict schema checking on, an
   * invalid value throws SchemaIncompleteException before it is ever stored.
   */
  private function assertSavedConfigValid(string $name, array $values): void {
    $config = $this->config($name);
    foreach ($values as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();

    $this->assertConfigSchemaByName($name);
  }

}
