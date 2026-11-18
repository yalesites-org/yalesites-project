<?php

namespace Drupal\Tests\ys_views_wizard\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\ys_views_wizard\Form\ViewsWizardForm;
use Drupal\ys_views_wizard\ViewsWizardOptions;

/**
 * Tests that the wizard only hands off a pair the region offers.
 *
 * The display mode radios carry #validated, so core never checks their value
 * against the option list, and #ajax callbacks run even when validation
 * failed. A stale pair (Continue clicked during the content type swap) or a
 * crafted one must come back as a form error, never reach the add-block
 * route as a NULL plugin ID, and never bypass the region's placement rules.
 *
 * @coversDefaultClass \Drupal\ys_views_wizard\Form\ViewsWizardForm
 *
 * @group yalesites
 */
class ViewsWizardPairValidationTest extends UnitTestCase {

  /**
   * The wizard options mock.
   *
   * @var \Drupal\ys_views_wizard\ViewsWizardOptions|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $options;

  /**
   * The form builder mock.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $formBuilder;

  /**
   * The form under test.
   *
   * @var \Drupal\ys_views_wizard\Form\ViewsWizardForm
   */
  protected $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // This region offers posts as card or list, and profiles as card. The
    // post portrait grid bundle exists but the region does not allow it.
    $this->options = $this->createMock(ViewsWizardOptions::class);
    $this->options->method('getContentTypeOptions')->willReturn([
      'post' => 'Posts',
      'profile' => 'Profiles',
    ]);
    $this->options->method('getViewModeOptions')->willReturnCallback(fn(string $type) => match ($type) {
      'post' => ['card' => 'Card', 'list_item' => 'List'],
      'profile' => ['card' => 'Card'],
      default => [],
    });
    $this->options->method('resolvePluginId')->willReturnCallback(
      fn(string $type, string $mode) => in_array($mode, ['card', 'list_item', 'portrait_grid'], TRUE) ? "inline_block:{$type}_{$mode}" : NULL
    );

    $this->formBuilder = $this->createMock(FormBuilderInterface::class);

    $container = new ContainerBuilder();
    $container->set('ys_views_wizard.options', $this->options);
    $container->set('form_builder', $this->formBuilder);
    $container->set('string_translation', $this->getStringTranslationStub());
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('renderRoot')->willReturn('');
    $renderer->method('renderInIsolation')->willReturn('');
    $container->set('renderer', $renderer);
    \Drupal::setContainer($container);

    $this->form = ViewsWizardForm::create($container);
  }

  /**
   * Returns a form state for the Continue button with the given answers.
   */
  protected function formState(mixed $type, mixed $mode, string $trigger = 'op'): FormState {
    $section_storage = $this->createMock(SectionStorageInterface::class);
    $section_storage->method('getStorageType')->willReturn('overrides');
    $section_storage->method('getStorageId')->willReturn('node.54');

    $form_state = new FormState();
    $form_state->set('section_storage', $section_storage);
    $form_state->set('delta', 0);
    $form_state->set('region', 'content');
    $form_state->setValues(['entity_types' => $type, 'view_mode' => $mode]);
    $form_state->setTriggeringElement(['#name' => $trigger]);
    return $form_state;
  }

  /**
   * An offered pair passes validation.
   *
   * @covers ::validateForm
   */
  public function testOfferedPairIsValid(): void {
    $form = [];
    $form_state = $this->formState('post', 'list_item');
    $this->form->validateForm($form, $form_state);
    $this->assertSame([], $form_state->getErrors());
  }

  /**
   * Pairs the region does not offer are refused.
   *
   * @covers ::validateForm
   *
   * @dataProvider refusedPairProvider
   */
  public function testRefusedPairs(mixed $type, mixed $mode): void {
    $form = [];
    $form_state = $this->formState($type, $mode);
    $this->form->validateForm($form, $form_state);
    $this->assertArrayHasKey('view_mode', $form_state->getErrors());
  }

  /**
   * Data provider for ::testRefusedPairs().
   */
  public static function refusedPairProvider(): array {
    return [
      'display mode of another type' => ['profile', 'list_item'],
      'bundle the region restricts' => ['post', 'portrait_grid'],
      'unknown type' => ['event', 'card'],
      'missing display mode' => ['post', NULL],
      'missing type' => [NULL, 'card'],
      'array value' => [['post'], 'card'],
    ];
  }

  /**
   * Changing the content type is not validated as a pair.
   *
   * The display mode radios still hold the previous type's choice when the
   * content type radios submit on change.
   *
   * @covers ::validateForm
   */
  public function testContentTypeChangeIsNotValidatedAsPair(): void {
    $form = [];
    $form_state = $this->formState('profile', 'list_item', 'entity_types');
    $this->form->validateForm($form, $form_state);
    $this->assertSame([], $form_state->getErrors());
  }

  /**
   * With validation errors the callback re-renders the form instead.
   *
   * @covers ::openConfigureForm
   */
  public function testCallbackWithErrorsRerendersTheForm(): void {
    $this->formBuilder->expects($this->never())->method('getForm');
    // The empty #attached stands in for what the real renderer collects.
    $form = [
      '#attributes' => ['data-drupal-selector' => 'ys-views-wizard-choose'],
      '#attached' => [],
    ];
    $form_state = $this->formState('profile', 'list_item');
    $this->form->validateForm($form, $form_state);

    $response = $this->form->openConfigureForm($form, $form_state);

    $commands = $response->getCommands();
    $this->assertCount(1, $commands);
    $this->assertSame('insert', $commands[0]['command']);
    $this->assertSame('replaceWith', $commands[0]['method']);
    $this->assertSame('[data-drupal-selector="ys-views-wizard-choose"]', $commands[0]['selector']);
  }

  /**
   * A NULL answer never reaches resolvePluginId() as a TypeError.
   *
   * @covers ::openConfigureForm
   */
  public function testCallbackWithMissingAnswerDoesNotThrow(): void {
    $this->formBuilder->expects($this->never())->method('getForm');
    $form = ['#attached' => []];
    $response = $this->form->openConfigureForm($form, $this->formState('post', NULL));
    $this->assertSame('[data-drupal-selector="ys-views-wizard-choose"]', $response->getCommands()[0]['selector']);
  }

}
