<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Template\Attribute;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\FormErrorDescription;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests that an inline form error is tied to its field for screen readers.
 *
 * A failed validation marked the field aria-invalid but never pointed its
 * aria-describedby at the error text, so a screen reader announced "invalid"
 * with no reason. See yalesites-org/YaleSites-Internal#1670.
 *
 * @coversDefaultClass \Drupal\ys_core\FormErrorDescription
 *
 * @group ys_core
 * @group yalesites
 */
class FormErrorDescriptionTest extends UnitTestCase {

  /**
   * A text field that failed validation, as the renderer hands it over.
   */
  private function invalidTextfield(array $overrides = []): array {
    return $overrides + [
      '#id' => 'edit-site-mail',
      '#errors' => 'Site email must end in @yale.edu.',
      '#theme_wrappers' => ['form_element'],
    ];
  }

  /**
   * Inline Form Errors stays on for admin forms and off for front-end forms.
   *
   * @covers ::alterForm
   * @testWith [true, false]
   *           [false, true]
   */
  public function testInlineErrorsAdminOnly(bool $is_admin_route, bool $disabled): void {
    $admin_context = $this->createMock(AdminContext::class);
    $admin_context->method('isAdminRoute')->willReturn($is_admin_route);
    $container = new ContainerBuilder();
    $container->set('router.admin_context', $admin_context);
    \Drupal::setContainer($container);

    $form = [];
    FormErrorDescription::alterForm($form);

    $this->assertSame($disabled, !empty($form['#disable_inline_form_errors']));
  }

  /**
   * Elements whose error has no inline message to point at.
   */
  public static function noInlineMessageProvider(): array {
    return [
      'no error' => [['#errors' => NULL]],
      'message suppressed' => [['#error_no_message' => TRUE]],
      'no id to derive from' => [['#id' => NULL]],
      'not wrapped in an error-rendering template' => [['#theme_wrappers' => ['container']]],
      // Claro dedupes a details error by identity; an id would break that.
      'details' => [['#theme_wrappers' => ['details']]],
    ];
  }

  /**
   * The error id derives from the field id when an inline message renders.
   *
   * @covers ::id
   */
  public function testIdForInvalidField(): void {
    $this->assertSame('edit-site-mail--error-message', FormErrorDescription::id($this->invalidTextfield()));
  }

  /**
   * Formdazzle renames the wrapper to a suggestion before render; still found.
   *
   * @covers ::id
   */
  public function testIdForSuggestedWrapper(): void {
    $element = $this->invalidTextfield([
      '#theme_wrappers' => ['form_element__ys_admin_settings__site_mail'],
    ]);
    $this->assertSame('edit-site-mail--error-message', FormErrorDescription::id($element));
  }

  /**
   * Wrappers and themes that render an inline error, beyond form_element.
   */
  public static function groupWrapperProvider(): array {
    return [
      'radios or checkboxes' => [['#theme_wrappers' => ['radios', 'fieldset']]],
      'datetime' => [['#theme_wrappers' => ['datetime_wrapper']]],
      'media library widget' => [['#theme_wrappers' => ['fieldset__media_library_widget']]],
      'media library form element' => [['#theme' => 'media_library_element', '#theme_wrappers' => []]],
    ];
  }

  /**
   * Grouped controls get an error id too, so the group can point at it.
   *
   * @covers ::id
   * @dataProvider groupWrapperProvider
   */
  public function testIdForGroupWrapper(array $overrides): void {
    $this->assertSame('edit-site-mail--error-message', FormErrorDescription::id($this->invalidTextfield($overrides)));
  }

  /**
   * No id when nothing inline would carry it, so no dangling reference.
   *
   * @covers ::id
   * @dataProvider noInlineMessageProvider
   */
  public function testNoIdWithoutInlineMessage(array $overrides): void {
    $this->assertNull(FormErrorDescription::id($this->invalidTextfield($overrides)));
  }

  /**
   * The rendered inline error carries the id, and focus handling is attached.
   *
   * @covers ::preprocessFormElement
   */
  public function testFormElementErrorGetsId(): void {
    $element = $this->invalidTextfield();
    $variables = ['element' => $element, 'errors' => $element['#errors']];

    FormErrorDescription::preprocessFormElement($variables);

    $this->assertSame('html_tag', $variables['errors']['#type']);
    $this->assertSame('edit-site-mail--error-message', $variables['errors']['#attributes']['id']);
    $this->assertSame($element['#errors'], $variables['errors']['#value']);
    $this->assertContains('ys_core/form_error_focus', $variables['#attached']['library']);
  }

  /**
   * A plain-string error is escaped, as Twig did before; markup is kept.
   *
   * @covers ::preprocessFormElement
   */
  public function testPlainStringErrorEscaped(): void {
    $element = $this->invalidTextfield(['#errors' => '<b>bad</b> value']);
    $variables = ['element' => $element, 'errors' => $element['#errors']];

    FormErrorDescription::preprocessFormElement($variables);

    $this->assertSame('&lt;b&gt;bad&lt;/b&gt; value', $variables['errors']['#value']);
  }

  /**
   * Nothing changes when Inline Form Errors did not render a message.
   *
   * @covers ::preprocessFormElement
   */
  public function testFormElementWithoutRenderedErrorUntouched(): void {
    $variables = ['element' => $this->invalidTextfield(), 'errors' => NULL];
    $before = $variables;

    FormErrorDescription::preprocessFormElement($variables);

    $this->assertSame($before, $variables);
  }

  /**
   * Control attributes as input/select (array) and textarea (object) have.
   */
  public static function controlAttributesProvider(): array {
    return [
      'array, no description' => [[], 'edit-site-mail--error-message'],
      'array, with description' => [
        ['aria-describedby' => 'edit-site-mail--description'],
        'edit-site-mail--description edit-site-mail--error-message',
      ],
      'Attribute object, with description' => [
        new Attribute(['aria-describedby' => 'edit-site-mail--description']),
        'edit-site-mail--description edit-site-mail--error-message',
      ],
    ];
  }

  /**
   * The control's aria-describedby gains the error id, keeping any description.
   *
   * @covers ::preprocessControl
   * @dataProvider controlAttributesProvider
   */
  public function testControlDescribedByError(array|Attribute $attributes, string $expected): void {
    $variables = ['element' => $this->invalidTextfield(), 'attributes' => $attributes];

    FormErrorDescription::preprocessControl($variables);

    $this->assertSame($expected, (string) $variables['attributes']['aria-describedby']);
  }

  /**
   * A valid control keeps its attributes as they were.
   *
   * @covers ::preprocessControl
   */
  public function testValidControlUntouched(): void {
    $variables = [
      'element' => $this->invalidTextfield(['#errors' => NULL]),
      'attributes' => ['aria-describedby' => 'edit-site-mail--description'],
    ];

    FormErrorDescription::preprocessControl($variables);

    $this->assertSame('edit-site-mail--description', $variables['attributes']['aria-describedby']);
  }

  /**
   * A radios, checkboxes, or media widget fieldset with an error.
   */
  private function invalidFieldset(): array {
    $element = $this->invalidTextfield(['#theme_wrappers' => ['radios', 'fieldset']]);
    return [
      'element' => $element,
      'errors' => $element['#errors'],
      'attributes' => ['aria-describedby' => 'edit-site-mail--wrapper--description'],
    ];
  }

  /**
   * The group's error carries the id, and the fieldset is described by it.
   *
   * Screen readers announce a fieldset's description when focus enters the
   * group, so one link on the fieldset covers every option in it.
   *
   * @covers ::preprocessGroup
   */
  public function testGroupDescribedByError(): void {
    $variables = $this->invalidFieldset();

    FormErrorDescription::preprocessGroup($variables);

    $this->assertSame('edit-site-mail--error-message', $variables['errors']['#attributes']['id']);
    $this->assertSame('edit-site-mail--wrapper--description edit-site-mail--error-message', $variables['attributes']['aria-describedby']);
    $this->assertContains('ys_core/form_error_focus', $variables['#attached']['library']);
  }

  /**
   * A valid group is left as it was.
   *
   * @covers ::preprocessGroup
   */
  public function testValidGroupUntouched(): void {
    $variables = $this->invalidFieldset();
    $variables['element']['#errors'] = NULL;
    $variables['errors'] = NULL;
    $before = $variables;

    FormErrorDescription::preprocessGroup($variables);

    $this->assertSame($before, $variables);
  }

  /**
   * Sets whether the Inline Form Errors module is on.
   */
  private function setInlineFormErrors(bool $enabled): void {
    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->with('inline_form_errors')->willReturn($enabled);
    $container = new ContainerBuilder();
    $container->set('module_handler', $module_handler);
    \Drupal::setContainer($container);
  }

  /**
   * The media picker renders its error, which Inline Form Errors skips.
   *
   * Its template prints errors, but its preprocess always blanks them and
   * Inline Form Errors has no hook for it, so its error only ever reached the
   * summary at the top of the page.
   *
   * @covers ::preprocessMediaLibraryElement
   */
  public function testMediaLibraryElementGetsInlineError(): void {
    $this->setInlineFormErrors(TRUE);
    $variables = [
      'element' => $this->invalidTextfield(['#theme' => 'media_library_element', '#theme_wrappers' => []]),
      'errors' => NULL,
      'attributes' => [],
    ];

    FormErrorDescription::preprocessMediaLibraryElement($variables);

    $this->assertSame('edit-site-mail--error-message', $variables['errors']['#attributes']['id']);
    $this->assertSame('Site email must end in @yale.edu.', $variables['errors']['#value']);
    $this->assertSame(['fieldset__error-message'], $variables['errors']['#attributes']['class']);
    $this->assertSame('edit-site-mail--error-message', $variables['attributes']['aria-describedby']);
  }

  /**
   * Ways inline errors can be off for the media picker.
   */
  public static function mediaLibraryInlineErrorsOffProvider(): array {
    return [
      'off for this form' => [['#error_no_message' => TRUE], TRUE],
      'module uninstalled' => [[], FALSE],
    ];
  }

  /**
   * No inline error for the media picker when inline errors are off.
   *
   * @covers ::preprocessMediaLibraryElement
   * @dataProvider mediaLibraryInlineErrorsOffProvider
   */
  public function testMediaLibraryElementWithoutInlineMessageUntouched(array $overrides, bool $module_enabled): void {
    $this->setInlineFormErrors($module_enabled);
    $variables = [
      'element' => $this->invalidTextfield($overrides + [
        '#theme' => 'media_library_element',
        '#theme_wrappers' => [],
      ]),
      'errors' => NULL,
      'attributes' => [],
    ];
    $before = $variables;

    FormErrorDescription::preprocessMediaLibraryElement($variables);

    $this->assertSame($before, $variables);
  }

  /**
   * A datetime element as it reaches #pre_render, date and time inside.
   */
  private function invalidDatetime(array $overrides = []): array {
    return $this->invalidTextfield($overrides + ['#theme_wrappers' => ['datetime_wrapper']]) + [
      'date' => ['#type' => 'date', '#attributes' => ['aria-describedby' => 'edit-site-mail--description']],
      'time' => ['#type' => 'date'],
    ];
  }

  /**
   * Each date part is described by the datetime's error.
   *
   * The wrapper around them is a plain div, not a group, so the link has to
   * sit on the inputs a screen reader user actually lands on.
   *
   * @covers ::preRenderDatetime
   */
  public function testDatetimePartsDescribedByError(): void {
    $element = FormErrorDescription::preRenderDatetime($this->invalidDatetime());

    $this->assertSame('edit-site-mail--description edit-site-mail--error-message', $element['date']['#attributes']['aria-describedby']);
    $this->assertSame('edit-site-mail--error-message', $element['time']['#attributes']['aria-describedby']);
  }

  /**
   * A datelist part with its own inline error keeps only its own link.
   *
   * Core's datelist leaves each part's message on, so each part already links
   * to its own error through the control preprocess.
   *
   * @covers ::preRenderDatetime
   */
  public function testDatelistPartWithOwnErrorSkipped(): void {
    $element = $this->invalidDatetime();
    $element['time'] = $this->invalidTextfield(['#id' => 'edit-site-mail-time']);

    $element = FormErrorDescription::preRenderDatetime($element);

    $this->assertArrayNotHasKey('#attributes', $element['time']);
    $this->assertSame('edit-site-mail--description edit-site-mail--error-message', $element['date']['#attributes']['aria-describedby']);
  }

  /**
   * A datetime rendered twice still names its error once.
   *
   * @covers ::preRenderDatetime
   */
  public function testDatetimeDescribedByErrorOnce(): void {
    $element = FormErrorDescription::preRenderDatetime(FormErrorDescription::preRenderDatetime($this->invalidDatetime()));

    $this->assertSame('edit-site-mail--error-message', $element['time']['#attributes']['aria-describedby']);
  }

  /**
   * A valid datetime is left as it was.
   *
   * @covers ::preRenderDatetime
   */
  public function testValidDatetimeUntouched(): void {
    $element = $this->invalidDatetime(['#errors' => NULL]);

    $this->assertSame($element, FormErrorDescription::preRenderDatetime($element));
  }

  /**
   * The #pre_render callback is trusted, or Drupal refuses to call it.
   *
   * @covers ::trustedCallbacks
   */
  public function testPreRenderTrusted(): void {
    $this->assertContains('preRenderDatetime', FormErrorDescription::trustedCallbacks());
  }

}
