<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\AiTesterRetry;

/**
 * Tests the pacing field the tester adds to the Beacon administration form.
 *
 * The field is contributed from this submodule rather than declared in the
 * Beacon form so the dependency runs the same direction the module dependency
 * does, and so the field disappears with the submodule rather than needing a
 * module-exists guard on the Beacon side.
 *
 * @group ys_beacon
 */
class AiTesterPacingFormAlterTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/ys_ai_tester.module';
  }

  /**
   * Puts a config factory returning one stored delay value on the container.
   *
   * @param mixed $stored
   *   The value ys_beacon.settings:ai_tester_question_delay_ms holds.
   */
  protected function setUpConfig(mixed $stored): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('ai_tester_question_delay_ms')
      ->willReturn($stored);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('ys_beacon.settings')->willReturn($config);

    $container = new ContainerBuilder();
    $container->set('config.factory', $factory);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Runs the alter against a minimal stand-in for the Beacon settings form.
   *
   * The '::submitForm' entry is what FormBuilder::prepareForm() has already put
   * in '#submit' by the time alter hooks run, so the fixture carries it.
   */
  protected function alterForm(): array {
    $form = ['#submit' => ['::submitForm']];
    $form_state = $this->createMock(FormStateInterface::class);
    ys_ai_tester_form_ys_beacon_admin_settings_alter($form, $form_state);

    return $form;
  }

  /**
   * The field is added, bounded, and cannot be saved outside its clamp.
   */
  public function testAlterAddsBoundedPacingField(): void {
    $this->setUpConfig(750);
    $form = $this->alterForm();

    $field = $form['tester']['ai_tester_question_delay_ms'];
    $this->assertSame('number', $field['#type']);
    $this->assertSame(750, $field['#default_value']);
    $this->assertSame(0, $field['#min']);
    $this->assertSame(AiTesterRetry::MAX_QUESTION_DELAY_MS, $field['#max']);
    // An unlabelled or undescribed number field would be a WCAG 2.1 AA failure
    // (1.3.1 / 3.3.2) on a form only reachable by a platform operator, but a
    // failure all the same.
    $this->assertNotEmpty((string) $field['#title']);
    $this->assertNotEmpty((string) $field['#description']);
    // Required so an emptied field is rejected rather than silently reverting
    // to the default while appearing to have saved.
    $this->assertTrue($field['#required']);
  }

  /**
   * A site predating the setting shows the default, not an empty field.
   */
  public function testAlterFallsBackToTheDefaultWhenUnset(): void {
    // ys_beacon.settings is not exported to the profile's config/sync, so an
    // existing site genuinely will not have this key until it is saved once.
    $this->setUpConfig(NULL);

    $this->assertSame(
      AiTesterRetry::DEFAULT_QUESTION_DELAY_MS,
      $this->alterForm()['tester']['ai_tester_question_delay_ms']['#default_value']
    );
  }

  /**
   * The tester's submit handler is appended, not substituted for the form's.
   */
  public function testAlterAppendsItsSubmitHandler(): void {
    $this->setUpConfig(500);
    $submit = $this->alterForm()['#submit'];

    // Replacing '::submitForm' rather than appending would stop every other
    // Beacon setting on the form from saving at all.
    $this->assertSame(['::submitForm', 'ys_ai_tester_admin_settings_submit'], $submit);
  }

}
