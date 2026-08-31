<?php

namespace Drupal\Tests\ys_themes\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ys_themes\Form\ThemesSettingsForm;
use Drupal\ys_themes\ThemeSettingsManager;

/**
 * Kernel tests for the ThemesSettingsForm config form.
 *
 * @coversDefaultClass \Drupal\ys_themes\Form\ThemesSettingsForm
 * @group ys_themes
 * @group yalesites
 */
class ThemesSettingsFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'formdazzle',
    'ys_themes',
  ];

  /**
   * {@inheritdoc}
   *
   * Ys_themes.theme_settings ships without a schema file, so strict schema
   * checking is disabled here; logged as a GAP.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * The form under test.
   *
   * @var \Drupal\ys_themes\Form\ThemesSettingsForm
   */
  protected $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['ys_themes']);
    $this->form = ThemesSettingsForm::create($this->container);
  }

  /**
   * GetFormId() returns the expected form ID.
   *
   * @covers ::getFormId
   */
  public function testGetFormId(): void {
    $this->assertSame('ys_themes_settings_form', $this->form->getFormId());
  }

  /**
   * GetEditableConfigNames() declares the single settings config object.
   *
   * @covers ::getEditableConfigNames
   */
  public function testGetEditableConfigNames(): void {
    $reflection = new \ReflectionMethod($this->form, 'getEditableConfigNames');
    $reflection->setAccessible(TRUE);
    $this->assertSame(['ys_themes.theme_settings'], $reflection->invoke($this->form));
  }

  /**
   * Tests build form populates radios from settings and config.
   *
   * BuildForm() builds one radios element per theme setting, with options
   * and attributes taken from ThemeSettingsManager::THEME_SETTINGS, and a
   * default value taken from saved config (not just each setting's own
   * hardcoded default -- proven here by saving a non-default value first).
   *
   * @covers ::buildForm
   */
  public function testBuildFormPopulatesRadiosFromSettingsAndConfig(): void {
    $this->config('ys_themes.theme_settings')->set('global_theme', 'three')->save();

    $form_state = new FormState();
    $form = $this->form->buildForm([], $form_state);

    $global_theme = $form['global_settings']['global_theme'];
    $this->assertSame('radios', $global_theme['#type']);
    $this->assertSame([
      'one' => 'Old Blues',
      'two' => 'New Haven Green',
      'three' => 'Shoreline Summer',
      'four' => 'Onha',
      'five' => 'It’s Your Yale',
      'six' => 'AI',
      'seven' => 'Whitney Humanities Center',
    ], $global_theme['#options']);
    $this->assertSame('three', $global_theme['#default_value']);
    $this->assertSame('element', $global_theme['#attributes']['data-prop-type']);
    $this->assertSame('[data-global-theme]', $global_theme['#attributes']['data-selector']);
    $this->assertContains('ys-themes--setting', $global_theme['#attributes']['class']);

    // Every setting from THEME_SETTINGS gets a fieldset entry, alongside the
    // fieldset's own '#type'/'#attributes' properties.
    $setting_keys = array_values(array_filter(
      array_keys($form['global_settings']),
      fn(string $key) => $key[0] !== '#'
    ));
    $this->assertSame(array_keys(ThemeSettingsManager::THEME_SETTINGS), $setting_keys);

    // The generic "Save configuration" submit action is present.
    $this->assertArrayHasKey('actions', $form);
  }

  /**
   * Tests build form falls back to setting default when config value missing.
   *
   * BuildForm() falls back to each setting's own declared default (not
   * config's NULL) when config has no saved value for it.
   *
   * @covers ::buildForm
   */
  public function testBuildFormFallsBackToSettingDefaultWhenConfigValueMissing(): void {
    $this->config('ys_themes.theme_settings')->clear('global_theme')->save();

    $form_state = new FormState();
    $form = $this->form->buildForm([], $form_state);

    $this->assertSame('one', $form['global_settings']['global_theme']['#default_value']);
  }

  /**
   * Tests submit form saves all settings and redirects to current.
   *
   * SubmitForm() saves every submitted setting to config and redirects to
   * the current page.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormSavesAllSettingsAndRedirectsToCurrent(): void {
    $this->form->setMessenger($this->createMock(MessengerInterface::class));

    $form_state = new FormState();
    foreach (array_keys(ThemeSettingsManager::THEME_SETTINGS) as $setting_name) {
      $form_state->setValue($setting_name, 'two');
    }

    $form = [];
    $this->form->submitForm($form, $form_state);

    $config = $this->config('ys_themes.theme_settings');
    foreach (array_keys(ThemeSettingsManager::THEME_SETTINGS) as $setting_name) {
      $this->assertSame('two', $config->get($setting_name));
    }

    $redirect = $form_state->getRedirect();
    $this->assertInstanceOf(Url::class, $redirect);
    $this->assertSame('<current>', $redirect->getRouteName());
  }

}
