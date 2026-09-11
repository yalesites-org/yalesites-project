<?php

namespace Drupal\Tests\ys_ai\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai\BeaconSupersession;

/**
 * Tests the Integration Settings form alter that hides the legacy checkboxes.
 *
 * The form builds one checkbox per integration plugin definition and its
 * submit handler writes every one of them back to config. Removing the two
 * legacy checkboxes outright would therefore write NULL over the stored
 * values, so they are converted to value elements instead: nothing is
 * offered in the UI and the stored settings survive a save.
 *
 * @group ys_ai
 * @group yalesites
 */
class IntegrationSettingsFormAlterTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/ys_ai.module';
  }

  /**
   * Registers a supersession service in a given state on the container.
   */
  protected function setUpSupersession(bool $superseded): void {
    $supersession = $this->createMock(BeaconSupersession::class);
    $supersession->method('isSuperseded')->willReturn($superseded);
    $supersession->method('getCacheTags')->willReturn(['config:ys_beacon.settings']);

    $container = new ContainerBuilder();
    $container->set('ys_ai.beacon_supersession', $supersession);
    \Drupal::setContainer($container);
  }

  /**
   * Builds the integrations form as YsIntegrationSettingsForm would.
   */
  protected function buildForm(): array {
    return [
      'integrations' => [
        'ys_ai' => ['#type' => 'checkbox', '#title' => 'AI', '#default_value' => TRUE],
        'ys_ai_system_instructions' => [
          '#type' => 'checkbox',
          '#title' => 'AI System Instructions',
          '#default_value' => TRUE,
        ],
        'ys_beacon' => ['#type' => 'checkbox', '#title' => 'Beacon (AI Chat)', '#default_value' => TRUE],
      ],
    ];
  }

  /**
   * Runs the alter hook over a freshly built form.
   */
  protected function alterForm(array $form): array {
    $form_state = $this->createMock(FormStateInterface::class);
    ys_ai_form_ys_integrations_integration_settings_form_alter($form, $form_state, 'ys_integrations_integration_settings_form');
    return $form;
  }

  /**
   * The two legacy checkboxes are no longer offered once superseded.
   */
  public function testLegacyCheckboxesAreNotOfferedWhenSuperseded(): void {
    $this->setUpSupersession(TRUE);

    $form = $this->alterForm($this->buildForm());

    $this->assertSame('value', $form['integrations']['ys_ai']['#type']);
    $this->assertSame('value', $form['integrations']['ys_ai_system_instructions']['#type']);
  }

  /**
   * Saving the form keeps the stored value of each hidden integration.
   */
  public function testHiddenCheckboxesKeepTheirStoredValue(): void {
    $this->setUpSupersession(TRUE);

    $form = $this->alterForm($this->buildForm());

    $this->assertTrue($form['integrations']['ys_ai']['#value']);
    $this->assertTrue($form['integrations']['ys_ai_system_instructions']['#value']);
  }

  /**
   * A stored value of NULL is not what gets written back.
   *
   * The form sets #default_value straight from config, so an integration with
   * no stored key at all defaults to NULL. Passing that through would write
   * NULL on save — the very thing converting to a value element avoids.
   */
  public function testHiddenCheckboxWithNoStoredValueSavesFalse(): void {
    $this->setUpSupersession(TRUE);
    $form = $this->buildForm();
    $form['integrations']['ys_ai']['#default_value'] = NULL;

    $form = $this->alterForm($form);

    $this->assertFalse($form['integrations']['ys_ai']['#value']);
  }

  /**
   * Other integrations keep their checkbox.
   */
  public function testOtherIntegrationsAreUntouchedWhenSuperseded(): void {
    $this->setUpSupersession(TRUE);

    $form = $this->alterForm($this->buildForm());

    $this->assertSame('checkbox', $form['integrations']['ys_beacon']['#type']);
  }

  /**
   * The form varies on the Beacon settings in both states.
   *
   * Including the not-superseded state: a response rendered then must still
   * be invalidated when authorization is switched on.
   */
  public function testFormAlwaysAddsBeaconSettingsCacheTag(): void {
    foreach ([TRUE, FALSE] as $superseded) {
      $this->setUpSupersession($superseded);

      $form = $this->alterForm($this->buildForm());

      $this->assertContains('config:ys_beacon.settings', $form['#cache']['tags']);
    }
  }

  /**
   * Every checkbox is still offered on a site Beacon has not superseded.
   */
  public function testCheckboxesAreUntouchedWhenNotSuperseded(): void {
    $this->setUpSupersession(FALSE);

    $form = $this->alterForm($this->buildForm());

    $this->assertSame($this->buildForm()['integrations'], $form['integrations']);
  }

}
