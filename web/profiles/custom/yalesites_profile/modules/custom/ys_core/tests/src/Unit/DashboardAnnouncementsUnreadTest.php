<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserDataInterface;
use Drupal\ys_core\DashboardAnnouncements;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the per-user unread state derived for each dashboard announcement.
 *
 * The toolbar badge count and the per-item "new" marker on the dashboard have
 * to agree, because they are two views of the same question: is this item
 * newer than the user's `announcements_last_seen` high-water mark. These tests
 * pin them to a single derivation so a future change cannot drift one without
 * the other.
 *
 * @group ys_core
 * @group yalesites
 * @coversDefaultClass \Drupal\ys_core\DashboardAnnouncements
 */
class DashboardAnnouncementsUnreadTest extends UnitTestCase {

  /**
   * A cached feed of three items, newest first, two days apart.
   *
   * Mirrors the shape getAnnouncements() writes to the keyvalue store.
   */
  private const NEWEST = 3000;
  private const MIDDLE = 2000;
  private const OLDEST = 1000;

  /**
   * Builds the service with a pre-populated feed cache and last-seen value.
   *
   * Seeding the keyvalue store is how getAnnouncements() short-circuits, so
   * this exercises the real unread logic without any HTTP.
   *
   * @param array $items
   *   The cached announcement items.
   * @param int|null $last_seen
   *   The stored `announcements_last_seen` value, or NULL when never set.
   * @param array $written
   *   Populated with any user.data write the service performs, by reference.
   */
  private function service(array $items, ?int $last_seen, array &$written = []): DashboardAnnouncements {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn (string $key) => match ($key) {
      'announcements_enabled' => TRUE,
      'announcements_feed_url' => 'https://example.com/feed',
      default => NULL,
    });
    $config_factory = $this->createMock('Drupal\Core\Config\ConfigFactoryInterface');
    $config_factory->method('get')->willReturn($config);

    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('get')->willReturn($items);
    $key_value = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $key_value->method('get')->willReturn($store);

    $user_data = $this->createMock(UserDataInterface::class);
    $user_data->method('get')->willReturn($last_seen === NULL ? NULL : (string) $last_seen);
    $user_data->method('set')->willReturnCallback(function ($module, $uid, $key, $value) use (&$written) {
      $written[] = [$module, $uid, $key, $value];
    });

    return new DashboardAnnouncements(
      $this->createMock(ClientInterface::class),
      $config_factory,
      $key_value,
      $this->createMock(DateFormatterInterface::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $user_data,
      $this->createMock(RequestStack::class),
      $this->createMock(ClassResolverInterface::class),
    );
  }

  /**
   * Three cached items with descending timestamps.
   */
  private function threeItems(): array {
    return [
      ['title' => 'Newest', 'url' => '', 'summary' => '', 'timestamp' => self::NEWEST, 'date' => ''],
      ['title' => 'Middle', 'url' => '', 'summary' => '', 'timestamp' => self::MIDDLE, 'date' => ''],
      ['title' => 'Oldest', 'url' => '', 'summary' => '', 'timestamp' => self::OLDEST, 'date' => ''],
    ];
  }

  /**
   * An authenticated account stub.
   */
  private function account(bool $anonymous = FALSE): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAnonymous')->willReturn($anonymous);
    $account->method('id')->willReturn(7);
    return $account;
  }

  /**
   * Only items newer than last-seen are flagged new, and order is preserved.
   *
   * Feed order is the platform's editorial order, so flagging must not
   * reorder or filter the list.
   *
   * @covers ::getAnnouncementsForUser
   */
  public function testFlagsOnlyItemsNewerThanLastSeen(): void {
    $service = $this->service($this->threeItems(), self::MIDDLE);

    $items = $service->getAnnouncementsForUser($this->account());

    $this->assertSame(['Newest', 'Middle', 'Oldest'], array_column($items, 'title'), 'Feed order is unchanged.');
    $this->assertSame([TRUE, FALSE, FALSE], array_column($items, 'is_new'), 'Only the item newer than last-seen is new.');
  }

  /**
   * A user who has never visited sees every dated item as new.
   *
   * @covers ::getAnnouncementsForUser
   */
  public function testEverythingIsNewWithNoLastSeen(): void {
    $service = $this->service($this->threeItems(), NULL);

    $items = $service->getAnnouncementsForUser($this->account());

    $this->assertSame([TRUE, TRUE, TRUE], array_column($items, 'is_new'));
  }

  /**
   * An item with no parseable date is never flagged new.
   *
   * A missing `date_published` leaves `timestamp` NULL, and there is no basis
   * for calling such an item unread -- it would otherwise stay marked new
   * forever, since marking read only ever advances the timestamp.
   *
   * @covers ::getAnnouncementsForUser
   */
  public function testUndatedItemIsNeverNew(): void {
    $service = $this->service([
      ['title' => 'Undated', 'url' => '', 'summary' => '', 'timestamp' => NULL, 'date' => ''],
    ], NULL);

    $items = $service->getAnnouncementsForUser($this->account());

    $this->assertSame([FALSE], array_column($items, 'is_new'));
  }

  /**
   * An anonymous account resolves no unread state and no items.
   *
   * There is nowhere to store read state for a session with no account, so
   * there is nothing to decorate -- and short-circuiting before the feed is
   * touched keeps a stray anonymous call from triggering an outbound fetch.
   *
   * @covers ::getAnnouncementsForUser
   * @covers ::getUnreadCount
   */
  public function testAnonymousResolvesNoUnreadState(): void {
    $service = $this->service($this->threeItems(), NULL);
    $anonymous = $this->account(TRUE);

    $this->assertSame([], $service->getAnnouncementsForUser($anonymous));
    $this->assertSame(0, $service->getUnreadCount($anonymous));
  }

  /**
   * The badge count is the number of items the marker flags, for real values.
   *
   * This is the "no second source of truth" guarantee: the markers the editor
   * sees on the dashboard and the number in the toolbar are the same fact.
   * Asserted against absolute expected counts rather than by re-deriving the
   * count from getAnnouncementsForUser() -- getUnreadCount() *is* that
   * derivation, so comparing the two to each other would hold even if the
   * comparison were inverted.
   *
   * @covers ::getUnreadCount
   * @covers ::getAnnouncementsForUser
   * @covers ::countUnread
   */
  public function testUnreadCountMatchesFlaggedItems(): void {
    $cases = [
      // [stored last-seen, expected unread out of the three seeded items].
      [NULL, 3],
      [self::OLDEST - 1, 3],
      [self::OLDEST, 2],
      [self::MIDDLE, 1],
      [self::NEWEST, 0],
      [self::NEWEST + 1, 0],
    ];

    foreach ($cases as [$last_seen, $expected]) {
      $service = $this->service($this->threeItems(), $last_seen);
      $account = $this->account();
      $context = sprintf('last-seen %s', var_export($last_seen, TRUE));

      $this->assertSame(
        $expected,
        $service->getUnreadCount($account),
        sprintf('%s leaves %d unread.', $context, $expected)
      );
      $this->assertSame(
        $expected,
        DashboardAnnouncements::countUnread($service->getAnnouncementsForUser($account)),
        sprintf('%s flags %d items new, matching the badge count.', $context, $expected)
      );
    }
  }

  /**
   * Reading the per-item flags never writes read state.
   *
   * Rendering the dashboard must be a pure read: clearing is an explicit
   * action, so merely looking at the announcements cannot advance the
   * high-water mark.
   *
   * @covers ::getAnnouncementsForUser
   */
  public function testFlaggingDoesNotWriteReadState(): void {
    $written = [];
    $service = $this->service($this->threeItems(), self::OLDEST, $written);

    $service->getAnnouncementsForUser($this->account());
    $service->getUnreadCount($this->account());

    $this->assertSame([], $written, 'No user.data write happened while reading unread state.');
  }

}
