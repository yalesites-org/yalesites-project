<?php

namespace Drupal\Tests\ys_content_export\Unit;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_content_export\PeopleExportBuilder;
use Drupal\user\UserInterface;

/**
 * Unit tests for PeopleExportBuilder.
 *
 * @coversDefaultClass \Drupal\ys_content_export\PeopleExportBuilder
 * @group ys_content_export
 * @group yalesites
 */
class PeopleExportBuilderTest extends UnitTestCase {

  /**
   * Tests the column map matches the columns the issue asked for.
   *
   * @covers ::getColumns
   */
  public function testGetColumns(): void {
    $columns = PeopleExportBuilder::getColumns();

    $this->assertSame(
      ['username', 'full_name', 'status', 'roles', 'created', 'access'],
      array_keys($columns)
    );
    $this->assertSame(
      ['Username', 'Name', 'Status', 'Roles', 'Member for', 'Last access'],
      array_values($columns)
    );
  }

  /**
   * Tests the Username cell, including an account saved without a name.
   *
   * @param string $account_name
   *   The stored account name.
   * @param string $display_name
   *   The display name Drupal falls back to.
   * @param string $expected
   *   The expected cell output.
   *
   * @dataProvider usernameProvider
   * @covers ::cellValue
   */
  public function testUsernameCell(string $account_name, string $display_name, string $expected): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('getAccountName')->willReturn($account_name);
    $user->method('getDisplayName')->willReturn($display_name);

    $this->assertSame($expected, $this->cellValue($user, 'username'));
  }

  /**
   * Provides account names and their expected Username cell.
   *
   * @return array
   *   Cases: [account name, display name, expected].
   */
  public static function usernameProvider(): array {
    return [
      'username present' => ['abc12', 'abc12', 'abc12'],
      'no name falls back to display name' => ['', 'Anonymous', 'Anonymous'],
    ];
  }

  /**
   * Tests the Name cell joins the first and last name fields.
   *
   * @param array $values
   *   Stored field values keyed by field name; a missing key means the account
   *   has no such field.
   * @param string $expected
   *   The expected cell output.
   *
   * @dataProvider fullNameProvider
   * @covers ::fullName
   */
  public function testFullNameCell(array $values, string $expected): void {
    $has = [];
    $get = [];
    foreach (PeopleExportBuilder::NAME_FIELDS as $field) {
      $present = array_key_exists($field, $values);
      $has[] = [$field, $present];
      if ($present) {
        // UserInterface::get() has no return-type declaration, so a lightweight
        // object exposing ->value is enough to exercise the cell logic.
        $get[] = [$field, (object) ['value' => $values[$field]]];
      }
    }

    $user = $this->createMock(UserInterface::class);
    $user->method('hasField')->willReturnMap($has);
    $user->method('get')->willReturnMap($get);

    $this->assertSame($expected, $this->cellValue($user, 'full_name'));
  }

  /**
   * Provides name field states and their expected Name cell.
   *
   * @return array
   *   Cases: [stored values, expected].
   */
  public static function fullNameProvider(): array {
    return [
      'both names' => [
        ['field_first_name' => 'Ada', 'field_last_name' => 'Lovelace'],
        'Ada Lovelace',
      ],
      'first name only, no trailing space' => [
        ['field_first_name' => 'Ada', 'field_last_name' => NULL],
        'Ada',
      ],
      'last name only, no leading space' => [
        ['field_first_name' => '', 'field_last_name' => 'Lovelace'],
        'Lovelace',
      ],
      'whitespace is trimmed away' => [
        ['field_first_name' => '  Ada  ', 'field_last_name' => '   '],
        'Ada',
      ],
      'neither name set' => [
        ['field_first_name' => NULL, 'field_last_name' => NULL],
        '',
      ],
      'fields absent from the account' => [[], ''],
    ];
  }

  /**
   * Tests the Status cell uses the People screen's own wording.
   *
   * @param bool $active
   *   Whether the account is active.
   * @param string $expected
   *   The expected cell output.
   *
   * @dataProvider statusProvider
   * @covers ::cellValue
   */
  public function testStatusCell(bool $active, string $expected): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('isActive')->willReturn($active);

    $this->assertSame($expected, $this->cellValue($user, 'status'));
  }

  /**
   * Provides account states and their expected Status cell.
   *
   * @return array
   *   Cases: [active, expected].
   */
  public static function statusProvider(): array {
    return [
      'active' => [TRUE, 'Active'],
      'blocked' => [FALSE, 'Blocked'],
    ];
  }

  /**
   * Tests the Roles cell lists every role label, comma separated.
   *
   * The People screen renders the column with a ", " separator, so the CSV
   * uses the same one.
   *
   * @covers ::roles
   */
  public function testRolesCellJoinsLabelsWithComma(): void {
    $user = $this->userWithRoles(['Site Admin', 'Editor']);

    $this->assertSame('Site Admin, Editor', $this->cellValue($user, 'roles'));
  }

  /**
   * Tests an account with no roles beyond authenticated exports an empty cell.
   *
   * @covers ::roles
   */
  public function testRolesCellWithNoRoles(): void {
    $user = $this->userWithRoles([]);

    $this->assertSame('', $this->cellValue($user, 'roles'));
  }

  /**
   * Tests the two timestamp columns export sortable dates.
   *
   * @param int $created
   *   The account creation timestamp.
   * @param int $access
   *   The last access timestamp.
   * @param string $expected_created
   *   The expected Member for cell.
   * @param string $expected_access
   *   The expected Last access cell.
   *
   * @dataProvider dateProvider
   * @covers ::date
   */
  public function testDateCells(int $created, int $access, string $expected_created, string $expected_access): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('getCreatedTime')->willReturn($created);
    $user->method('getLastAccessedTime')->willReturn($access);

    $this->assertSame($expected_created, $this->cellValue($user, 'created'));
    $this->assertSame($expected_access, $this->cellValue($user, 'access'));
  }

  /**
   * Provides timestamps and their expected date cells.
   *
   * The second case is the account that has never logged in: Drupal stores 0,
   * and 1970-01-01 would read as a real sign-in date in a spreadsheet.
   *
   * @return array
   *   Cases: [created, access, expected created, expected access].
   */
  public static function dateProvider(): array {
    return [
      'both timestamps set' => [1707058800, 1759363200, '2024-02-04', '2025-10-01'],
      'never logged in' => [1707058800, 0, '2024-02-04', ''],
      'timestamp just before midnight stays on its own day' => [
        1707022740, 1707022740, '2024-02-03', '2024-02-03',
      ],
    ];
  }

  /**
   * Tests getRow emits the columns in order, all of them sanitised.
   *
   * The first and last name fields are the admin-editable ones, so they are
   * the realistic formula-injection vector: a name beginning with "=" must
   * reach the spreadsheet as text.
   *
   * @covers ::getRow
   */
  public function testGetRowIsOrderedAndSanitised(): void {
    $user = $this->userWithRoles(['Site Admin']);
    $user->method('getAccountName')->willReturn('abc12');
    $user->method('getDisplayName')->willReturn('abc12');
    $user->method('isActive')->willReturn(TRUE);
    $user->method('getCreatedTime')->willReturn(1707058800);
    $user->method('getLastAccessedTime')->willReturn(1759363200);

    $this->assertSame(
      ["abc12", "'=1+1 Lovelace", 'Active', 'Site Admin', '2024-02-04', '2025-10-01'],
      PeopleExportBuilder::getRow($user, $this->dateFormatter())
    );
  }

  /**
   * Builds a user mock whose roles field references the given role labels.
   *
   * The name fields are stubbed alongside the roles field so the same mock can
   * also be handed to a whole-row test.
   *
   * @param string[] $labels
   *   The role labels to expose.
   *
   * @return \PHPUnit\Framework\MockObject\MockObject
   *   The user mock.
   */
  protected function userWithRoles(array $labels) {
    $roles = [];
    foreach ($labels as $label) {
      $role = $this->createMock(EntityInterface::class);
      $role->method('label')->willReturn($label);
      $roles[] = $role;
    }
    $field_list = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $field_list->method('referencedEntities')->willReturn($roles);

    $user = $this->createMock(UserInterface::class);
    $user->method('hasField')->willReturn(TRUE);
    $user->method('get')->willReturnMap([
      ['roles', $field_list],
      ['field_first_name', (object) ['value' => '=1+1']],
      ['field_last_name', (object) ['value' => 'Lovelace']],
    ]);

    return $user;
  }

  /**
   * Invokes the protected cell builder for one column.
   *
   * @param \Drupal\user\UserInterface $user
   *   The account.
   * @param string $key
   *   The column key.
   *
   * @return string
   *   The raw (unsanitised) cell value.
   */
  protected function cellValue(UserInterface $user, string $key): string {
    $method = new \ReflectionMethod(PeopleExportBuilder::class, 'cellValue');
    $method->setAccessible(TRUE);
    return $method->invoke(NULL, $user, $key, $this->dateFormatter());
  }

  /**
   * Builds a date formatter that renders in the site timezone.
   *
   * Mirrors the platform's system.date settings: America/New_York, with
   * per-user timezones off. Unit tests get no container, so the 'custom'
   * pattern is applied directly.
   *
   * @return \Drupal\Core\Datetime\DateFormatterInterface
   *   A date formatter test double.
   */
  protected function dateFormatter(): DateFormatterInterface {
    $formatter = $this->createMock(DateFormatterInterface::class);
    $formatter->method('format')->willReturnCallback(
      function ($timestamp, $type = 'medium', $format = '', $timezone = NULL) {
        $date = new \DateTime('@' . $timestamp);
        $date->setTimezone(new \DateTimeZone($timezone ?? 'America/New_York'));
        return $date->format($format);
      }
    );
    return $formatter;
  }

}
