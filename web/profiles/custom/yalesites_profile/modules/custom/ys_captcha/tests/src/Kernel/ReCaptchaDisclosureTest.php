<?php

namespace Drupal\Tests\ys_captcha\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests ys_captcha's form alterations for forms carrying a captcha element.
 *
 * The module adds the shared "form-item" class to the captcha fieldset so it
 * lines up with the form's fields, and -- because Google's reCAPTCHA terms
 * require the "protected by reCAPTCHA" disclosure to be shown in the user flow
 * when the badge is hidden -- adds that disclosure inline.
 *
 * @group ys_captcha
 * @group yalesites
 */
class ReCaptchaDisclosureTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'captcha',
    'recaptcha_v3',
    'ys_captcha',
  ];

  /**
   * The render array key the disclosure is added under.
   */
  const DISCLOSURE_KEY = 'ys_captcha_recaptcha_disclosure';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['recaptcha_v3']);
  }

  /**
   * Sets the recaptcha_v3 hide_badge setting.
   */
  protected function setHideBadge(bool $hide): void {
    $this->config('recaptcha_v3.settings')->set('hide_badge', $hide)->save();
  }

  /**
   * Builds a minimal form array carrying a top-level captcha element.
   */
  protected function formWithCaptcha(): array {
    return [
      '#type' => 'form',
      'name' => ['#type' => 'textfield'],
      'captcha' => [
        '#type' => 'captcha',
        '#captcha_type' => 'recaptcha_v3/form_submission',
      ],
      'actions' => ['#type' => 'actions'],
    ];
  }

  /**
   * Invokes the form alter hook against a form, returning the altered form.
   */
  protected function alter(array $form): array {
    $form_state = new FormState();
    ys_captcha_form_alter($form, $form_state, 'some_form');
    return $form;
  }

  /**
   * Disclosure is added, via the inline-message component, when badge hidden.
   */
  public function testDisclosureAddedWhenBadgeHiddenAndCaptchaPresent(): void {
    $this->setHideBadge(TRUE);
    $form = $this->alter($this->formWithCaptcha());

    $this->assertArrayHasKey(self::DISCLOSURE_KEY, $form);
    $this->assertSame('inline_template', $form[self::DISCLOSURE_KEY]['#type']);
    $this->assertStringContainsString('@molecules/inline-message/yds-inline-message.twig', $form[self::DISCLOSURE_KEY]['#template']);

    $content = (string) $form[self::DISCLOSURE_KEY]['#context']['content'];
    $this->assertStringContainsString('reCAPTCHA', $content);
    $this->assertStringContainsString('https://policies.google.com/privacy', $content);
    $this->assertStringContainsString('https://policies.google.com/terms', $content);
  }

  /**
   * The captcha fieldset gets the form-item class regardless of badge state.
   */
  public function testCaptchaFieldsetGetsFormItemClass(): void {
    $this->setHideBadge(FALSE);
    $form = $this->alter($this->formWithCaptcha());

    $this->assertContains('form-item', $form['captcha']['#attributes']['class']);
  }

  /**
   * Nested captcha elements (webform case) get both alterations applied.
   */
  public function testDisclosureAddedForNestedCaptcha(): void {
    $this->setHideBadge(TRUE);
    $form = [
      '#type' => 'form',
      'elements' => [
        'wrapper' => [
          'captcha' => [
            '#type' => 'captcha',
            '#captcha_type' => 'recaptcha_v3/form_submission',
          ],
        ],
      ],
    ];
    $form = $this->alter($form);

    $this->assertArrayHasKey(self::DISCLOSURE_KEY, $form);
    $this->assertContains('form-item', $form['elements']['wrapper']['captcha']['#attributes']['class']);
  }

  /**
   * No disclosure when the form has no captcha element.
   */
  public function testNoDisclosureWhenNoCaptcha(): void {
    $this->setHideBadge(TRUE);
    $form = $this->alter([
      '#type' => 'form',
      'name' => ['#type' => 'textfield'],
      'actions' => ['#type' => 'actions'],
    ]);

    $this->assertArrayNotHasKey(self::DISCLOSURE_KEY, $form);
  }

  /**
   * No disclosure when the badge is visible (hide_badge FALSE).
   */
  public function testNoDisclosureWhenBadgeVisible(): void {
    $this->setHideBadge(FALSE);
    $form = $this->alter($this->formWithCaptcha());

    $this->assertArrayNotHasKey(self::DISCLOSURE_KEY, $form);
  }

}
