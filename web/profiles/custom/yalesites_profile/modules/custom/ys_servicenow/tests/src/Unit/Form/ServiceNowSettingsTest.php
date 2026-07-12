<?php

namespace Drupal\Tests\ys_servicenow\Unit\Form;

use Drupal\Core\Config\Config;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxy;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_servicenow\Form\ServiceNowSettings;
use Drupal\ys_servicenow\ServiceNowManager;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Unit tests for the ServiceNow settings form.
 *
 * @coversDefaultClass \Drupal\ys_servicenow\Form\ServiceNowSettings
 *
 * @group yalesites
 * @group ys_servicenow
 */
class ServiceNowSettingsTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // buildForm()/submitForm() call $this->t() and $this->messenger() via
    // FormBase's traits, both of which resolve through the container.
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('messenger', $this->createMock(MessengerInterface::class));
    \Drupal::setContainer($container);
  }

  /**
   * Builds a ServiceNowSettings form with the given config values.
   */
  protected function buildFormObject(array $config_values = []): ServiceNowSettings {
    $config_factory = $this->getConfigFactoryStub([
      'ys_servicenow.settings' => $config_values,
    ]);

    return new ServiceNowSettings(
      $config_factory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(ServiceNowManager::class),
      $this->createMock(AccountProxy::class)
    );
  }

  /**
   * @covers ::getFormId
   */
  public function testGetFormId() {
    $this->assertSame('ys_servicenow_settings', $this->buildFormObject()->getFormId());
  }

  /**
   * @covers ::getEditableConfigNames
   */
  public function testGetEditableConfigNames() {
    $reflection = new \ReflectionMethod(ServiceNowSettings::class, 'getEditableConfigNames');
    $reflection->setAccessible(TRUE);

    $this->assertSame(['ys_servicenow.settings'], $reflection->invoke($this->buildFormObject()));
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormDisablesSyncCheckboxWithoutSecretItemsPermission() {
    $form = $this->buildFormObject()->buildForm([], new FormState());

    // ys_core_allow_secret_items() is not loaded in this unit test, so
    // function_exists() is FALSE and the checkbox is always disabled.
    $this->assertTrue($form['enable_servicenow_sync']['#disabled']);
    $this->assertArrayNotHasKey('sync_now_button', $form);
    $this->assertFalse($form['enable_servicenow_sync']['#default_value']);
    $this->assertSame('', $form['servicenow_endpoint']['#default_value']);
    $this->assertSame('', $form['servicenow_auth_key']['#default_value']);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormShowsSyncButtonAndDefaultsWhenFullyConfigured() {
    $form = $this->buildFormObject([
      'enable_servicenow_sync' => TRUE,
      'servicenow_endpoint' => 'https://example.com/api',
      'servicenow_auth_key' => 'my_key',
    ])->buildForm([], new FormState());

    $this->assertArrayHasKey('sync_now_button', $form);
    $this->assertStringContainsString('/admin/yalesites/servicenow/sync', $form['sync_now_button']['#markup']);
    $this->assertTrue($form['enable_servicenow_sync']['#default_value']);
    $this->assertSame('https://example.com/api', $form['servicenow_endpoint']['#default_value']);
    $this->assertSame('my_key', $form['servicenow_auth_key']['#default_value']);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormAddsErrorsForMissingRequiredFieldsWhenEnabled() {
    $form_object = $this->buildFormObject();
    $form = $form_object->buildForm([], new FormState());

    $form_state = new FormState();
    $form_state->setValues([
      'enable_servicenow_sync' => TRUE,
      'servicenow_auth_key' => '',
      'servicenow_endpoint' => '',
    ]);
    $form_state->setCompleteForm($form);

    $form_object->validateForm($form, $form_state);

    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('servicenow_auth_key', $errors);
    $this->assertArrayHasKey('servicenow_endpoint', $errors);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormAddsNoErrorsWhenSyncDisabled() {
    $form_object = $this->buildFormObject();
    $form = $form_object->buildForm([], new FormState());

    $form_state = new FormState();
    $form_state->setValues(['enable_servicenow_sync' => FALSE]);
    $form_state->setCompleteForm($form);

    $form_object->validateForm($form, $form_state);

    $this->assertSame([], $form_state->getErrors());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormSavesSubmittedValuesToConfig() {
    $set_calls = [];
    $config = $this->createMock(Config::class);
    $config->method('set')->willReturnCallback(function ($key, $value) use (&$set_calls, $config) {
      $set_calls[$key] = $value;
      return $config;
    });
    $config->expects($this->once())->method('save');

    // ConfigFormBase::submitForm() (invoked via parent::submitForm()) also
    // reads a config-target map from form state, but that map is only
    // populated by the full form-processing pipeline (#after_build), which
    // never runs here, so no further config_factory calls occur beyond the
    // getEditable() below.
    $config_factory = $this->createMock('Drupal\Core\Config\ConfigFactoryInterface');
    $config_factory->method('getEditable')->with('ys_servicenow.settings')->willReturn($config);

    $form_object = new ServiceNowSettings(
      $config_factory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(ServiceNowManager::class),
      $this->createMock(AccountProxy::class)
    );

    $form = [];
    $form_state = new FormState();
    $form_state->setValues([
      'enable_servicenow_sync' => TRUE,
      'servicenow_auth_key' => 'my_key',
      'servicenow_endpoint' => 'https://example.com/api',
    ]);

    $form_object->submitForm($form, $form_state);

    $this->assertSame([
      'enable_servicenow_sync' => TRUE,
      'servicenow_auth_key' => 'my_key',
      'servicenow_endpoint' => 'https://example.com/api',
    ], $set_calls);
  }

}
