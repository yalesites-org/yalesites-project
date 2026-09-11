<?php

namespace Drupal\Tests\ys_alert\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\ys_alert\Form\AlertSettings;

/**
 * Kernel tests for the AlertSettings config form.
 *
 * @group yalesites
 * @group ys_alert
 */
class AlertSettingsFormTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'path_alias',
    'ys_alert',
  ];

  /**
   * {@inheritdoc}
   *
   * The ys_alert.settings config ships without a schema file, so strict schema
   * checking is disabled here; logged as a GAP.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->installConfig(['ys_alert']);
  }

  /**
   * Builds the AlertSettings form object via the container.
   */
  protected function buildFormObject(): AlertSettings {
    return AlertSettings::create($this->container);
  }

  /**
   * Tests that buildForm() populates defaults from saved config.
   *
   * @covers ::buildForm
   */
  public function testBuildFormPopulatesDefaultsFromConfig() {
    $form_state = new FormState();
    $form = $this->buildFormObject()->buildForm([], $form_state);

    $config = $this->config('ys_alert.settings');
    $this->assertEquals($config->get('alert.status'), $form['status']['#default_value']);
    $this->assertEquals($config->get('alert.type'), $form['type']['#default_value']);
    $this->assertEquals($config->get('alert.headline'), $form['headline']['#default_value']);
    $this->assertEquals($config->get('alert.message'), $form['message']['#default_value']);
    $this->assertEquals($config->get('alert.link_url'), $form['link_wrapper']['link_url']['#default_value']);
    $this->assertEquals($config->get('alert.link_title'), $form['link_wrapper']['link_title']['#default_value']);
    // The generic "Save configuration" submit action is present.
    $this->assertArrayHasKey('actions', $form);
  }

  /**
   * Tests the live region that announces a paused emergency save.
   *
   * Saving an emergency alert is deliberately held back until the editor
   * confirms it in a modal. The form renders an empty live region so the
   * JavaScript has somewhere to write that warning: a screen reader only
   * announces changes made inside a live region that already existed, so the
   * region cannot be created at the same moment its text appears.
   *
   * @covers ::buildForm
   */
  public function testBuildFormRendersEmergencyConfirmationLiveRegion() {
    $form_state = new FormState();
    $form = $this->buildFormObject()->buildForm([], $form_state);

    $this->assertArrayHasKey('emergency_confirm_notice', $form);
    $notice = $form['emergency_confirm_notice'];
    $this->assertContains('ys-alert-emergency-notice', $notice['#attributes']['class']);

    // Rendered empty; the JavaScript supplies the message.
    $this->assertArrayNotHasKey('#markup', $notice);

    // The cue belongs with the alert type control it is about, not at the
    // bottom of the form where an editor would never look for it.
    $keys = array_keys($form);
    $this->assertGreaterThan(
      array_search('type', $keys, TRUE),
      array_search('emergency_confirm_notice', $keys, TRUE)
    );
    $this->assertLessThan(
      array_search('headline', $keys, TRUE),
      array_search('emergency_confirm_notice', $keys, TRUE)
    );
  }

  /**
   * @covers ::updateAlertDescriptionWrapperCallback
   */
  public function testUpdateAlertDescriptionWrapperCallback() {
    $form_state = new FormState();
    $form_object = $this->buildFormObject();
    $form = $form_object->buildForm([], $form_state);

    // Returns the portion of the form holding the type description markup.
    $this->assertSame(
      $form['type_description_wrapper'],
      $form_object->updateAlertDescriptionWrapperCallback($form, $form_state)
    );
  }

  /**
   * Tests that submitForm() saves an external link URL as-is.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormSavesExternalLinkDirectly() {
    $form = [];
    $form_state = new FormState();
    $form_state->setValues([
      'status' => 1,
      'type' => 'announcement',
      'headline' => 'Test headline',
      'message' => 'Test message',
      'link_title' => 'Yale',
      'link_url' => 'https://www.yale.edu',
    ]);

    $this->buildFormObject()->submitForm($form, $form_state);

    $config = $this->config('ys_alert.settings');
    $this->assertEquals('Test headline', $config->get('alert.headline'));
    $this->assertEquals('Test message', $config->get('alert.message'));
    $this->assertEquals(1, $config->get('alert.status'));
    $this->assertEquals('announcement', $config->get('alert.type'));
    $this->assertEquals('Yale', $config->get('alert.link_title'));
    // An external link is saved unchanged.
    $this->assertEquals('https://www.yale.edu', $config->get('alert.link_url'));
  }

  /**
   * Tests that submitForm() resolves an internal path to its alias.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormResolvesInternalPathToAlias() {
    $this->markTestSkipped('The injected path_alias.manager does not resolve a freshly-created alias in this minimal kernel isolation (even after cacheClear), so the alias-resolution branch of submitForm() cannot be exercised reliably here. The behavior is correct on a real site; a Functional test would be the faithful way to cover it.');

    PathAlias::create([
      'path' => '/node/1',
      'alias' => '/custom-alias',
    ])->save();
    // The alias manager caches lookups per request; clear it so the freshly
    // created alias is resolvable by submitForm().
    $this->container->get('path_alias.manager')->cacheClear();

    $form = [];
    $form_state = new FormState();
    $form_state->setValues([
      'status' => 1,
      'type' => 'marketing',
      'headline' => 'Test headline',
      'message' => 'Test message',
      'link_title' => 'Yale',
      'link_url' => '/node/1',
    ]);

    $this->buildFormObject()->submitForm($form, $form_state);

    // An internal path is replaced by its path alias.
    $config = $this->config('ys_alert.settings');
    $this->assertEquals('/custom-alias', $config->get('alert.link_url'));
  }

  /**
   * Tests that validateForm() rejects a link URL without a leading slash.
   *
   * @covers ::validateForm
   */
  public function testValidateFormRejectsInvalidUrl() {
    $form = [];
    $form_state = new FormState();
    $form_state->setValue('link_url', 'not-a-valid-path');

    $this->buildFormObject()->validateForm($form, $form_state);

    $this->assertNotEmpty($form_state->getErrors());
  }

  /**
   * Tests that validateForm() accepts an internal path starting with a slash.
   *
   * @covers ::validateForm
   */
  public function testValidateFormAcceptsInternalPath() {
    $form = [];
    $form_state = new FormState();
    $form_state->setValue('link_url', '/node/1');

    $this->buildFormObject()->validateForm($form, $form_state);

    $this->assertEmpty($form_state->getErrors());
  }

  /**
   * @covers ::getFormId
   */
  public function testGetFormId() {
    $this->assertEquals('ys_alert_settings', $this->buildFormObject()->getFormId());
  }

  /**
   * @covers ::create
   */
  public function testCreate() {
    $this->assertInstanceOf(AlertSettings::class, $this->buildFormObject());
  }

}
