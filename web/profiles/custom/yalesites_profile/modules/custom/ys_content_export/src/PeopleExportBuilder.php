<?php

namespace Drupal\ys_content_export;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\user\UserInterface;

/**
 * Builds the columns and rows for a People CSV export.
 *
 * The companion to ContentExportBuilder for the one admin list that is not
 * content, and kept free of injected services for the same reason: the column
 * map and the row builder can then be unit tested directly. Cell sanitisation
 * is reused from ContentExportBuilder rather than copied, so there is one
 * definition of what makes a CSV cell safe.
 */
class PeopleExportBuilder {

  /**
   * The ordered export columns.
   *
   * Keyed by an internal column key, with the header label as the value. Every
   * header matches the People screen's own column label so an admin can line
   * the spreadsheet up against the page it came from.
   */
  const COLUMNS = [
    'username' => 'Username',
    'full_name' => 'Name',
    'status' => 'Status',
    'roles' => 'Roles',
    'created' => 'Member for',
    'access' => 'Last access',
  ];

  /**
   * The user fields making up the Name column, in display order.
   *
   * The People view renders this column from the same two fields.
   */
  const NAME_FIELDS = ['field_first_name', 'field_last_name'];

  /**
   * The date pattern used by the two timestamp columns.
   *
   * Sortable in a spreadsheet, which the on-screen relative text ("3 years 2
   * months") is not — that is the whole point of the export carrying dates.
   */
  const DATE_FORMAT = 'Y-m-d';

  /**
   * Returns the ordered export columns.
   *
   * @return array
   *   Ordered map of column key to header label.
   */
  public static function getColumns(): array {
    return self::COLUMNS;
  }

  /**
   * Builds one sanitised CSV row for a user account.
   *
   * @param \Drupal\user\UserInterface $user
   *   The account to export.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter, used for the two timestamp columns. Passed in rather
   *   than injected so this class stays service-free and directly unit
   *   testable.
   *
   * @return array
   *   The row values, in the same order as getColumns(), each passed through
   *   ContentExportBuilder::sanitizeCell().
   */
  public static function getRow(UserInterface $user, DateFormatterInterface $date_formatter): array {
    $row = [];
    foreach (array_keys(self::COLUMNS) as $key) {
      $row[] = ContentExportBuilder::sanitizeCell(self::cellValue($user, $key, $date_formatter));
    }
    return $row;
  }

  /**
   * Resolves the raw value for a single column of an account.
   */
  protected static function cellValue(UserInterface $user, string $key, DateFormatterInterface $date_formatter): string {
    switch ($key) {
      case 'username':
        // Accounts are provisioned from CAS, so this username is the person's
        // NetID. An account saved without one (the anonymous user, or a local
        // account created before a name was set) falls back to its display
        // name, which Drupal guarantees to be human readable, rather than
        // exporting a row whose identifying column is blank.
        return (string) ($user->getAccountName() ?: $user->getDisplayName());

      case 'full_name':
        return self::fullName($user);

      case 'status':
        // Matches the Status column's own wording on the People screen.
        return $user->isActive() ? 'Active' : 'Blocked';

      case 'roles':
        return self::roles($user);

      case 'created':
        return self::date((int) $user->getCreatedTime(), $date_formatter);

      case 'access':
        return self::date((int) $user->getLastAccessedTime(), $date_formatter);

      default:
        return '';
    }
  }

  /**
   * Joins the account's first and last name.
   *
   * Either part may be unset, so the parts are collected before joining to
   * avoid a stray separating space on a half-filled name.
   *
   * @param \Drupal\user\UserInterface $user
   *   The account.
   *
   * @return string
   *   The person's name, or an empty string when neither field is filled in.
   */
  protected static function fullName(UserInterface $user): string {
    $parts = [];
    foreach (self::NAME_FIELDS as $field) {
      if (!$user->hasField($field)) {
        continue;
      }
      $value = trim((string) $user->get($field)->value);
      if ($value !== '') {
        $parts[] = $value;
      }
    }
    return implode(' ', $parts);
  }

  /**
   * Lists every role granted to the account.
   *
   * Reads the roles field rather than UserInterface::getRoles() so the cell
   * carries role labels, matching the on-screen column, and so the implicit
   * "authenticated" role — which the People screen does not list either — stays
   * out of the export.
   *
   * @param \Drupal\user\UserInterface $user
   *   The account.
   *
   * @return string
   *   The role labels, comma separated, or an empty string for an account with
   *   no roles beyond authenticated.
   */
  protected static function roles(UserInterface $user): string {
    if (!$user->hasField('roles')) {
      return '';
    }
    $labels = [];
    foreach ($user->get('roles')->referencedEntities() as $role) {
      $labels[] = (string) $role->label();
    }
    return implode(', ', $labels);
  }

  /**
   * Renders a timestamp as a sortable date in the site's timezone.
   *
   * The 'custom' format type lets the formatter use the pattern directly; a
   * named type would make it load a date format config entity on every call.
   *
   * @param int $timestamp
   *   The UNIX timestamp.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   *
   * @return string
   *   The formatted date, or an empty string when the timestamp is unset. A
   *   user who has never logged in stores 0 for last access; exporting that as
   *   1970-01-01 would read as a real date, so the cell is left empty instead.
   */
  protected static function date(int $timestamp, DateFormatterInterface $date_formatter): string {
    return $timestamp ? $date_formatter->format($timestamp, 'custom', self::DATE_FORMAT) : '';
  }

}
