<?php

namespace Drupal\Tests\ys_captcha\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the reCAPTCHA disclosure added when the badge is hidden.
 *
 * Google's reCAPTCHA terms require the "protected by reCAPTCHA" disclosure to
 * be shown in the user flow when the badge is hidden. ys_captcha adds it to
 * forms that carry a captcha element.
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
   * Disclosure is added when the badge is hidden and a captcha is present.
   */
  public function testDisclosureAddedWhenBadgeHiddenAndCaptchaPresent(): void {
    $this->setHideBadge(TRUE);
    $form = $this->alter($this->formWithCaptcha());

    $this->assertArrayHasKey(self::DISCLOSURE_KEY, $form);
    $markup = (string) $form[self::DISCLOSURE_KEY]['text']['#markup'];
    $this->assertStringContainsString('reCAPTCHA', $markup);
    $this->assertStringContainsString('https://policies.google.com/privacy', $markup);
    $this->assertStringContainsString('https://policies.google.com/terms', $markup);
  }

  /**
   * Disclosure is added when the captcha element is nested (webform case).
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
