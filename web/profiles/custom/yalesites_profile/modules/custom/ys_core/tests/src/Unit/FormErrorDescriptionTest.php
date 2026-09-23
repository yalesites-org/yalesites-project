<?php

namespace Drupal\Tests\ys_core\Unit;

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
      'not wrapped in form_element' => [['#theme_wrappers' => ['fieldset']]],
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

}
