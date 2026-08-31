<?php

namespace Drupal\Tests\ys_integrations\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ys_integrations\Form\YsIntegrationSettingsForm;

/**
 * Tests the integrations settings form build and submit.
 *
 * The form renders a checkbox per discovered integration and, on submit,
 * stores each checkbox value under the integration id in
 * ys_integrations.integration_settings config.
 *
 * The module ships no config schema for that config object, so strict schema
 * checking is disabled here (see the log referenced in the GAP note).
 *
 * @coversDefaultClass \Drupal\ys_integrations\Form\YsIntegrationSettingsForm
 *
 * @group ys_integrations
 * @group yalesites
 */
class YsIntegrationSettingsFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'ys_integrations',
    'ys_integrations_test',
  ];

  /**
   * {@inheritdoc}
   *
   * The ys_integrations.integration_settings config has no schema in the
   * module, so strict schema checking is disabled here; logged as a GAP.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * The name of the config object the form edits.
   */
  const SETTINGS = 'ys_integrations.integration_settings';

  /**
   * Builds the form via the container and returns the render array.
   */
  protected function buildSettingsForm(): array {
    $form_object = YsIntegrationSettingsForm::create($this->container);
    return $form_object->buildForm([], new FormState());
  }

  /**
   * The form exposes a fieldset holding a checkbox per integration.
   *
   * @covers ::buildForm
   */
  public function testBuildFormRendersIntegrationCheckbox(): void {
    $form = $this->buildSettingsForm();

    $this->assertSame('fieldset', $form['integrations']['#type']);
    $this->assertArrayHasKey('ys_integrations_test', $form['integrations']);

    $checkbox = $form['integrations']['ys_integrations_test'];
    $this->assertSame('checkbox', $checkbox['#type']);
    $this->assertSame('Test Integration', (string) $checkbox['#title']);
  }

  /**
   * The checkbox default value reflects stored config.
   *
   * @covers ::buildForm
   */
  public function testBuildFormUsesStoredDefaultValue(): void {
    // With no config saved the checkbox defaults to NULL (unchecked).
    $form = $this->buildSettingsForm();
    $this->assertNull($form['integrations']['ys_integrations_test']['#default_value']);

    // Once enabled in config the default value follows.
    $this->config(self::SETTINGS)->set('ys_integrations_test', 1)->save();
    $form = $this->buildSettingsForm();
    $this->assertSame(1, $form['integrations']['ys_integrations_test']['#default_value']);
  }

  /**
   * Submitting the form writes each integration value to config.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormSavesIntegrationValues(): void {
    $form_object = YsIntegrationSettingsForm::create($this->container);

    $form_state = new FormState();
    $form_state->setValue('ys_integrations_test', 1);
    $form = [];
    $form_object->submitForm($form, $form_state);

    $config = $this->config(self::SETTINGS);
    $this->assertSame(1, $config->get('ys_integrations_test'));
  }

  /**
   * The form reports its expected form id.
   *
   * @covers ::getFormId
   */
  public function testGetFormId(): void {
    $form_object = YsIntegrationSettingsForm::create($this->container);
    $this->assertSame('ys_integrations_integration_settings_form', $form_object->getFormId());
  }

}
