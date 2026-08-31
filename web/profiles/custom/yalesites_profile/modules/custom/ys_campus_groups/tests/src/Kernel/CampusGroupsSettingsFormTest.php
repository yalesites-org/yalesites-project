<?php

namespace Drupal\Tests\ys_campus_groups\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ys_campus_groups\Form\CampusGroupsSettings;

/**
 * Kernel tests for the CampusGroupsSettings config form.
 *
 * @coversDefaultClass \Drupal\ys_campus_groups\Form\CampusGroupsSettings
 *
 * @group ys_campus_groups
 * @group yalesites
 */
class CampusGroupsSettingsFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'key',
    'migrate',
    'migrate_plus',
    'migrate_tools',
    'ys_campus_groups',
  ];

  /**
   * {@inheritdoc}
   *
   * The ys_campus_groups.settings config ships without a schema file, so
   * strict schema checking is disabled here; logged as a GAP.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * Builds the CampusGroupsSettings form object via the container.
   */
  protected function buildFormObject(): CampusGroupsSettings {
    return CampusGroupsSettings::create($this->container);
  }

  /**
   * Tests that buildForm() populates defaults from saved config.
   *
   * @covers ::buildForm
   */
  public function testBuildFormPopulatesDefaultsFromConfig(): void {
    $this->config('ys_campus_groups.settings')
      ->set('enable_campus_groups_sync', TRUE)
      ->set('campus_groups_endpoint', 'https://example.edu/rss_events')
      ->set('campus_groups_groupids', '123,456')
      ->set('campus_groups_api_key', 'my_key')
      ->save();

    $form = $this->buildFormObject()->buildForm([], new FormState());

    $this->assertTrue($form['enable_campus_groups_sync']['#default_value']);
    $this->assertSame('https://example.edu/rss_events', $form['campus_groups_endpoint']['#default_value']);
    $this->assertSame('123,456', $form['campus_groups_groupids']['#default_value']);
    $this->assertSame('my_key', $form['campus_groups_api_key']['#default_value']);
  }

  /**
   * Tests that buildForm() falls back to the documented default endpoint.
   *
   * @covers ::buildForm
   */
  public function testBuildFormDefaultsEndpointWhenUnconfigured(): void {
    $form = $this->buildFormObject()->buildForm([], new FormState());

    $this->assertSame('https://yaleconnect.yale.edu/rss_events', $form['campus_groups_endpoint']['#default_value']);
    $this->assertFalse($form['enable_campus_groups_sync']['#default_value']);
  }

  /**
   * The sync now button only appears once syncing is enabled.
   *
   * @covers ::buildForm
   */
  public function testBuildFormShowsSyncNowButtonOnlyWhenEnabled(): void {
    $form = $this->buildFormObject()->buildForm([], new FormState());
    $this->assertArrayNotHasKey('sync_now_button', $form);

    $this->config('ys_campus_groups.settings')->set('enable_campus_groups_sync', TRUE)->save();
    $form = $this->buildFormObject()->buildForm([], new FormState());
    $this->assertArrayHasKey('sync_now_button', $form);
    $this->assertStringContainsString('/admin/yalesites/campus_groups/sync', $form['sync_now_button']['#markup']);
  }

  /**
   * Tests that submitForm() saves the submitted values.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormSavesValues(): void {
    $form = [];
    $form_state = new FormState();
    $form_state->setValues([
      'enable_campus_groups_sync' => 1,
      'campus_groups_endpoint' => 'https://example.edu/rss_events',
      'campus_groups_groupids' => '123,456',
      'campus_groups_api_key' => 'my_key',
    ]);

    $this->buildFormObject()->submitForm($form, $form_state);

    $config = $this->config('ys_campus_groups.settings');
    $this->assertSame(1, $config->get('enable_campus_groups_sync'));
    $this->assertSame('https://example.edu/rss_events', $config->get('campus_groups_endpoint'));
    $this->assertSame('123,456', $config->get('campus_groups_groupids'));
    $this->assertSame('my_key', $config->get('campus_groups_api_key'));
  }

  /**
   * Tests that submitForm() strips a trailing slash from the endpoint.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormTrimsTrailingSlashFromEndpoint(): void {
    $form = [];
    $form_state = new FormState();
    $form_state->setValues([
      'enable_campus_groups_sync' => 0,
      'campus_groups_endpoint' => 'https://example.edu/rss_events/',
      'campus_groups_groupids' => '123',
      'campus_groups_api_key' => '',
    ]);

    $this->buildFormObject()->submitForm($form, $form_state);

    $this->assertSame('https://example.edu/rss_events', $this->config('ys_campus_groups.settings')->get('campus_groups_endpoint'));
  }

  /**
   * @covers ::getFormId
   */
  public function testGetFormId(): void {
    $this->assertSame('ys_campus_groups_settings', $this->buildFormObject()->getFormId());
  }

  /**
   * @covers ::getEditableConfigNames
   */
  public function testGetEditableConfigNames(): void {
    $method = new \ReflectionMethod(CampusGroupsSettings::class, 'getEditableConfigNames');
    $method->setAccessible(TRUE);
    $this->assertSame(['ys_campus_groups.settings'], $method->invoke($this->buildFormObject()));
  }

  /**
   * @covers ::create
   */
  public function testCreate(): void {
    $this->assertInstanceOf(CampusGroupsSettings::class, $this->buildFormObject());
  }

}
