<?php

namespace Drupal\Tests\ys_localist\Unit\Form;

use Drupal\Core\Config\Config;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\ys_core\PlatformAdminCheckerInterface;
use Drupal\ys_localist\Form\LocalistSettings;
use Drupal\ys_localist\LocalistManager;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Unit tests for the Localist settings form.
 *
 * @coversDefaultClass \Drupal\ys_localist\Form\LocalistSettings
 *
 * @group yalesites
 * @group ys_localist
 */
class LocalistSettingsTest extends UnitTestCase {

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The mocked Localist manager.
   *
   * @var \Drupal\ys_localist\LocalistManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $localistManager;

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

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->localistManager = $this->createMock(LocalistManager::class);
  }

  /**
   * Builds a LocalistSettings form with the given config values.
   *
   * @param array $config_values
   *   Values for the 'ys_localist.settings' config object.
   * @param int $groups_imported_count
   *   The value getMigrationStatus('localist_groups') should return.
   * @param bool $is_platform_admin
   *   What the platform admin checker should report for this request.
   */
  protected function buildFormObject(array $config_values = [], int $groups_imported_count = 0, bool $is_platform_admin = FALSE): LocalistSettings {
    $config_factory = $this->getConfigFactoryStub([
      'ys_localist.settings' => $config_values,
    ]);

    $this->localistManager->method('getMigrationStatus')
      ->with('localist_groups')
      ->willReturn($groups_imported_count);

    $checker = $this->createMock(PlatformAdminCheckerInterface::class);
    $checker->method('isPlatformAdmin')->willReturn($is_platform_admin);

    return new LocalistSettings(
      $config_factory,
      $this->entityTypeManager,
      $this->localistManager,
      $checker
    );
  }

  /**
   * @covers ::getFormId
   */
  public function testGetFormId() {
    $this->assertSame('ys_localist_settings', $this->buildFormObject()->getFormId());
  }

  /**
   * @covers ::getEditableConfigNames
   */
  public function testGetEditableConfigNames() {
    $reflection = new \ReflectionMethod(LocalistSettings::class, 'getEditableConfigNames');
    $reflection->setAccessible(TRUE);

    $this->assertSame(['ys_localist.settings'], $reflection->invoke($this->buildFormObject()));
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormDisablesSyncCheckboxAndOmitsGroupFieldsWhenDisabled() {
    $form = $this->buildFormObject()->buildForm([], new FormState());

    // The account has no platform admin permission, so the fields are locked.
    $this->assertTrue($form['enable_localist_sync']['#disabled']);
    $this->assertFalse($form['enable_localist_sync']['#default_value']);
    $this->assertSame('https://events.yale.edu', $form['localist_endpoint']['#default_value']);
    $this->assertArrayNotHasKey('sync_now_button', $form);
    $this->assertArrayNotHasKey('localist_group', $form);
    $this->assertArrayNotHasKey('no_group_sync_message', $form);
  }

  /**
   * A platform admin gets the sync fields unlocked.
   *
   * Gating on the injected platform admin checker rather than the procedural
   * ys_core_allow_secret_items() - which a unit test cannot load, so this
   * branch was previously unreachable in tests - is what makes this
   * assertable (yalesites-org/YaleSites-Internal#1560).
   *
   * @covers ::buildForm
   */
  public function testBuildFormEnablesSyncFieldsForPlatformAdmin() {
    $form = $this->buildFormObject([], 0, TRUE)->buildForm([], new FormState());

    $this->assertFalse($form['enable_localist_sync']['#disabled']);
    $this->assertFalse($form['localist_endpoint']['#disabled']);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormShowsSyncButtonWhenGroupSelectedAndGroupsImported() {
    $term = $this->createMock(EntityInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(42)->willReturn($term);
    $this->entityTypeManager->method('getStorage')->with('taxonomy_term')->willReturn($storage);

    $form = $this->buildFormObject([
      'enable_localist_sync' => TRUE,
      'localist_endpoint' => 'https://events.yale.edu',
      'localist_group' => 42,
    ], 1)->buildForm([], new FormState());

    $this->assertArrayHasKey('sync_now_button', $form);
    $this->assertStringContainsString('/admin/yalesites/localist/sync', $form['sync_now_button']['#markup']);
    $this->assertArrayHasKey('localist_group', $form);
    $this->assertSame($term, $form['localist_group']['#default_value']);
    $this->assertArrayNotHasKey('no_group_sync_message', $form);

    // submitForm() reads this exact property to decide whether to write the
    // group, so pin it here: without this assertion the '#disabled' flag could
    // be dropped from buildForm() and the guard would silently become a no-op
    // for the population it exists for.
    $this->assertTrue($form['localist_group']['#disabled']);
  }

  /**
   * A platform admin gets an editable group picker.
   *
   * The other half of the contract submitForm()'s guard depends on: an
   * enabled picker is what allows the group to be written at all.
   *
   * @covers ::buildForm
   */
  public function testBuildFormEnablesGroupPickerForPlatformAdmin() {
    $form = $this->buildFormObject([
      'enable_localist_sync' => TRUE,
      'localist_endpoint' => 'https://events.yale.edu',
    ], 1, TRUE)->buildForm([], new FormState());

    $this->assertArrayHasKey('localist_group', $form);
    $this->assertFalse($form['localist_group']['#disabled']);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormShowsGroupPickerWithoutSyncButtonWhenNoGroupSelected() {
    $form = $this->buildFormObject([
      'enable_localist_sync' => TRUE,
      'localist_endpoint' => 'https://events.yale.edu',
    ], 1)->buildForm([], new FormState());

    $this->assertArrayNotHasKey('sync_now_button', $form);
    $this->assertArrayHasKey('localist_group', $form);
    $this->assertNull($form['localist_group']['#default_value']);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormShowsCreateGroupsMessageWhenGroupsNotImported() {
    $form = $this->buildFormObject([
      'enable_localist_sync' => TRUE,
      'localist_endpoint' => 'https://events.yale.edu',
    ], 0)->buildForm([], new FormState());

    $this->assertArrayNotHasKey('sync_now_button', $form);
    $this->assertArrayNotHasKey('localist_group', $form);
    $this->assertArrayHasKey('no_group_sync_message', $form);
    $this->assertStringContainsString('/admin/yalesites/localist/sync-groups', $form['no_group_sync_message']['#markup']);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormAddsErrorForMissingEndpointWhenEnabled() {
    $form_object = $this->buildFormObject();
    $form = $form_object->buildForm([], new FormState());

    $form_state = new FormState();
    $form_state->setValues([
      'enable_localist_sync' => TRUE,
      'localist_endpoint' => '',
    ]);
    $form_state->setCompleteForm($form);

    $form_object->validateForm($form, $form_state);

    $this->assertArrayHasKey('localist_endpoint', $form_state->getErrors());
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormAddsNoErrorsWhenSyncDisabled() {
    $form_object = $this->buildFormObject();
    $form = $form_object->buildForm([], new FormState());

    $form_state = new FormState();
    $form_state->setValues(['enable_localist_sync' => FALSE]);
    $form_state->setCompleteForm($form);

    $form_object->validateForm($form, $form_state);

    $this->assertSame([], $form_state->getErrors());
  }

  /**
   * Submits the form and returns the config keys it wrote, in order.
   *
   * @param array $form
   *   The built form array submitForm() should see. Only the presence and
   *   '#disabled' state of 'localist_group' matter to the code under test.
   * @param array $values
   *   The submitted form values.
   *
   * @return array
   *   The config keys and values written, keyed by config key.
   */
  protected function captureSubmittedConfig(array $form, array $values): array {
    $set_calls = [];
    $config = $this->createMock(Config::class);
    $config->method('set')->willReturnCallback(function ($key, $value) use (&$set_calls, $config) {
      $set_calls[$key] = $value;
      return $config;
    });
    $config->expects($this->once())->method('save');

    $config_factory = $this->createMock('Drupal\Core\Config\ConfigFactoryInterface');
    $config_factory->method('getEditable')->with('ys_localist.settings')->willReturn($config);

    $form_object = new LocalistSettings(
      $config_factory,
      $this->entityTypeManager,
      $this->localistManager,
      $this->createMock(PlatformAdminCheckerInterface::class)
    );

    $form_state = new FormState();
    $form_state->setValues($values);

    $form_object->submitForm($form, $form_state);

    return $set_calls;
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormSavesValuesAndTrimsTrailingSlashFromEndpoint() {
    $set_calls = $this->captureSubmittedConfig(
      ['localist_group' => ['#type' => 'entity_autocomplete']],
      [
        'enable_localist_sync' => TRUE,
        'localist_endpoint' => 'https://events.yale.edu/',
        'localist_group' => 42,
      ]
    );

    $this->assertSame([
      'enable_localist_sync' => TRUE,
      'localist_endpoint' => 'https://events.yale.edu',
      'localist_group' => 42,
    ], $set_calls);
  }

  /**
   * A previously chosen group survives a save made with sync turned off.
   *
   * The group picker is only built when sync is enabled and the groups
   * migration has run, so submitting the form with sync off carries no
   * 'localist_group' value at all. Writing it unconditionally stored an empty
   * value over the site's selected group
   * (yalesites-org/YaleSites-Internal#1610).
   *
   * @covers ::submitForm
   */
  public function testSubmitFormLeavesGroupAloneWhenPickerWasNotOnTheForm() {
    $set_calls = $this->captureSubmittedConfig([], [
      'enable_localist_sync' => FALSE,
      'localist_endpoint' => 'https://events.yale.edu',
    ]);

    $this->assertArrayNotHasKey('localist_group', $set_calls);
    $this->assertSame([
      'enable_localist_sync' => FALSE,
      'localist_endpoint' => 'https://events.yale.edu',
    ], $set_calls);
  }

  /**
   * A disabled group picker does not write back through config.
   *
   * See submitForm() for why - the value of a '#disabled' element comes from
   * '#default_value', not from the user.
   *
   * The submitted value below is the term ID, not the "Group Name (42)" label
   * the ticket assumed: EntityAutocomplete's '#element_validate' runs on
   * disabled elements too and has already converted it by this point. Either
   * way the guard writes nothing.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormLeavesGroupAloneWhenPickerWasDisabled() {
    $set_calls = $this->captureSubmittedConfig(
      ['localist_group' => ['#type' => 'entity_autocomplete', '#disabled' => TRUE]],
      [
        'enable_localist_sync' => TRUE,
        'localist_endpoint' => 'https://events.yale.edu',
        'localist_group' => 42,
      ]
    );

    $this->assertSame([
      'enable_localist_sync' => TRUE,
      'localist_endpoint' => 'https://events.yale.edu',
    ], $set_calls);
  }

}
