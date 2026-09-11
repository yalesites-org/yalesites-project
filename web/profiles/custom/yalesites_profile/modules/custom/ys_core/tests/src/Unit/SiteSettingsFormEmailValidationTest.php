<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Form\FormState;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\ys_core\Traits\ProtectedPropertyTrait;
use Drupal\ys_core\Form\SiteSettingsForm;

/**
 * Tests the Site email domain validation on the site settings form.
 *
 * YaleSites sends through Mailchimp Transactional, which is authorized to send
 * from exactly two domains. The previous check was
 * `strpos($value, 'yale.edu') === FALSE` - a substring test anywhere in the
 * string - so an address on any other Yale subdomain saved without complaint
 * and then silently bounced, taking every Pre-Built Form submission with it.
 * See yalesites-org/YaleSites-Internal#1669.
 *
 * @group ys_core
 * @group yalesites
 * @coversDefaultClass \Drupal\ys_core\Form\SiteSettingsForm
 */
class SiteSettingsFormEmailValidationTest extends UnitTestCase {

  use ProtectedPropertyTrait;

  /**
   * Addresses on an authorized sending domain.
   */
  public static function acceptedProvider(): array {
    return [
      'plain yale.edu' => ['user@yale.edu'],
      'noreply subdomain' => ['noreply@noreply.yale.edu'],
      // The domain is case-insensitive, and strpos() got this wrong too.
      'mixed case' => ['Someone@YALE.EDU'],
    ];
  }

  /**
   * An authorized address raises no error.
   *
   * @dataProvider acceptedProvider
   * @covers ::validateEmail
   */
  public function testAuthorizedDomainIsAccepted(string $email): void {
    $this->assertSame([], $this->errorsFor($email));
  }

  /**
   * Addresses the platform cannot send from.
   */
  public static function rejectedProvider(): array {
    return [
      // The reported case: gsa.yale.edu's saved address.
      'yale subdomain' => ['yalegsa@elilists.yale.edu'],
      // Passed the old substring check in the local part.
      'allowed domain as local part' => ['yale.edu@gmail.com'],
      // Passed the old substring check as a domain prefix.
      'allowed domain as a prefix' => ['user@yale.edu.example.com'],
      // Not a Yale address at all. Without this fixture every assertion below
      // would be satisfied by the echoed address alone, since the other three
      // contain the literal "yale.edu".
      'not a yale address at all' => ['forms@example.com'],
    ];
  }

  /**
   * An unauthorized domain is rejected with a message naming both domains.
   *
   * @dataProvider rejectedProvider
   * @covers ::validateEmail
   */
  public function testUnauthorizedDomainIsRejected(string $email): void {
    $errors = $this->errorsFor($email);

    $this->assertCount(1, $errors, 'One error, on the field being validated.');
    $error = reset($errors);
    // The message has to teach, not just reject: an editor with no YaleSites
    // training needs to be told what to enter instead, and shown which value
    // was refused. Asserting the whole guidance phrase rather than the domains
    // separately is deliberate - the domains appear in most of the rejected
    // addresses too, so a looser assertion would pass on the echo alone.
    $this->assertStringContainsString(
      'must end in @yale.edu or @noreply.yale.edu',
      $error
    );
    $this->assertStringContainsString($email, $error);
    // The reason given must hold for a non-Yale address as well, so it cannot
    // be narrowed to "other Yale subdomains".
    $this->assertStringNotContainsString('Yale subdomain', $error);
  }

  /**
   * A malformed address is reported as malformed, not as a domain problem.
   *
   * This pins behaviour rather than guarding a regression: the acceptance
   * criterion asked for one error instead of a stacked format-plus-domain
   * pair, but that pair could never reach the user anyway, because
   * FormState::setErrorByName() keeps only the first error per element name
   * and both calls used the same one. What is worth holding is that a value
   * with no usable domain is never described in terms of its domain.
   *
   * @covers ::validateEmail
   */
  public function testMalformedAddressProducesOnlyTheFormatError(): void {
    $errors = $this->errorsFor('not-an-email');

    $this->assertCount(1, $errors);
    $error = reset($errors);
    $this->assertStringContainsString('not valid', $error);
    $this->assertStringNotContainsString('must end in', $error);
  }

  /**
   * An empty value is left to the element's own #required handling.
   *
   * @covers ::validateEmail
   */
  public function testEmptyValueIsNotValidated(): void {
    $this->assertSame([], $this->errorsFor(''));
  }

  /**
   * The format error reports the field under validation, not `site_mail`.
   *
   * The message used to hard-code `$form_state->getValue('site_mail')`, so the
   * method reported the wrong value the moment it was reused for another
   * field - and reported nothing at all when site_mail was empty.
   *
   * @covers ::validateEmail
   */
  public function testFormatErrorReportsTheValidatedFieldsOwnValue(): void {
    $form_state = new FormState();
    $form_state->setValues([
      'site_mail' => 'someone@yale.edu',
      'other_mail' => 'malformed',
    ]);
    $this->validate($form_state, 'other_mail');

    $error = (string) ($form_state->getErrors()['other_mail'] ?? '');
    $this->assertStringContainsString('malformed', $error);
    $this->assertStringNotContainsString('someone@yale.edu', $error);
  }

  /**
   * Returns the rendered validation errors for a submitted Site email.
   *
   * @param string $email
   *   The submitted value.
   *
   * @return string[]
   *   Rendered error messages, keyed by element name.
   */
  private function errorsFor(string $email): array {
    $form_state = new FormState();
    $form_state->setValues(['site_mail' => $email]);
    $this->validate($form_state, 'site_mail');

    return array_map(
      static fn ($error): string => (string) $error,
      $form_state->getErrors()
    );
  }

  /**
   * Runs the form's protected email validator against a form state.
   */
  private function validate(FormState $form_state, string $field_id): void {
    $form_object = (new \ReflectionClass(SiteSettingsForm::class))->newInstanceWithoutConstructor();
    $this->setProtectedProperty($form_object, 'stringTranslation', $this->getStringTranslationStub());

    $validate = new \ReflectionMethod($form_object, 'validateEmail');
    $validate->setAccessible(TRUE);
    $validate->invokeArgs($form_object, [&$form_state, $field_id]);
  }

}
