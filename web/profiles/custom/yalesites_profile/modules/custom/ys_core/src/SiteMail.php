<?php

namespace Drupal\ys_core;

/**
 * The sending domains YaleSites is authorized to send mail from.
 *
 * YaleSites sends through Mailchimp Transactional, which is authorized for
 * exactly two domains. Mail from anywhere else - including other yale.edu
 * subdomains - bounces with nothing shown to the editor who set the address.
 *
 * The list lives here rather than in either caller because the Site email
 * validator and the Layout Builder warning must agree: a site whose saved
 * address one of them accepts and the other flags would tell an editor their
 * address is wrong while the form refuses to let them change it.
 *
 * @see \Drupal\ys_core\Form\SiteSettingsForm::validateEmail()
 * @see ys_core_form_alter()
 */
final class SiteMail {

  /**
   * Domains the platform can actually deliver mail from, lowercase.
   */
  public const ALLOWED_DOMAINS = ['yale.edu', 'noreply.yale.edu'];

  /**
   * Whether the platform can send mail from an address.
   *
   * The domain is everything after the last "@", compared case-insensitively.
   * A substring test was what caused this bug: it matched "yale.edu" anywhere
   * in the string, so "yalegsa@elilists.yale.edu", "yale.edu@gmail.com" and
   * "user@yale.edu.example.com" all passed.
   *
   * @param string $email
   *   An email address. Format is not validated here.
   *
   * @return bool
   *   TRUE if the address is on an authorized sending domain.
   */
  public static function isAuthorized(string $email): bool {
    $at = strrpos($email, '@');
    if ($at === FALSE) {
      return FALSE;
    }

    return in_array(
      strtolower(substr($email, $at + 1)),
      self::ALLOWED_DOMAINS,
      TRUE
    );
  }

  /**
   * The allowed domains as an "@a or @b" phrase for user-facing messages.
   *
   * @return string
   *   A human-readable list of the authorized sending domains.
   */
  public static function allowedDomainsLabel(): string {
    return '@' . implode(' or @', self::ALLOWED_DOMAINS);
  }

}
