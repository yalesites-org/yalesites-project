<?php

namespace Drupal\ys_core;

/**
 * The sending domains YaleSites is authorized to send mail from.
 *
 * YaleSites sends through Mailchimp Transactional, which delivers only from
 * domains that have been verified with it (DKIM/SPF). Mail from anywhere else
 * - including other yale.edu subdomains - bounces with nothing shown to the
 * editor who set the address.
 *
 * This is deliberately a per-domain allowlist and not a *.yale.edu wildcard:
 * accepting every Yale subdomain would re-introduce exactly the silent-bounce
 * behavior this check exists to stop. Adding an entry here is the second half
 * of the job - the domain has to be verified with Mailchimp Transactional
 * first, or the platform will accept an address it still cannot deliver from.
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
  public const ALLOWED_DOMAINS = [
    'yale.edu',
    'noreply.yale.edu',
    // Yale's mailing-list system. Verified for gsa.yale.edu, whose official
    // address is yalegsa@elilists.yale.edu and has no @yale.edu equivalent.
    // @see yalesites-org/YaleSites-Internal#1735
    'elilists.yale.edu',
  ];

  /**
   * Whether the platform can send mail from an address.
   *
   * The domain is everything after the last "@", compared case-insensitively.
   * A substring test was what caused this bug: it matched "yale.edu" anywhere
   * in the string, so "user@lists.yale.edu", "yale.edu@gmail.com" and
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
   * The allowed domains as a readable phrase for user-facing messages.
   *
   * Joining every entry with " or " reads acceptably at two domains and badly
   * at three ("@a or @b or @c"), so anything longer than a pair gets commas
   * with a final "or".
   *
   * @return string
   *   A human-readable list of the authorized sending domains.
   */
  public static function allowedDomainsLabel(): string {
    $domains = array_map(
      static fn (string $domain): string => '@' . $domain,
      self::ALLOWED_DOMAINS
    );
    $last = array_pop($domains);

    return match (count($domains)) {
      0 => $last,
      1 => $domains[0] . ' or ' . $last,
      default => implode(', ', $domains) . ', or ' . $last,
    };
  }

}
