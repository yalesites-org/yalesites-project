<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\ys_core\DashboardAnnouncements;

/**
 * Tests the unread-announcements badge on the Dashboard admin menu link.
 *
 * The badge is only correct if its placeholder stays user-agnostic and resolves
 * the viewer at replacement time; the attach site explains why at length.
 *
 * @see _ys_core_attach_dashboard_badge_walk()
 *
 * @group ys_core
 * @group yalesites
 */
class DashboardToolbarBadgeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'ys_core'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Timestamps for the seeded feed, newest first.
   */
  private const NEWEST = 3000;
  private const MIDDLE = 2000;
  private const OLDEST = 1000;

  /**
   * The editor who warms the shared toolbar cache entry first.
   */
  protected User $first;

  /**
   * A second editor sharing that entry, with different read state.
   */
  protected User $second;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);

    // Uid 1 short-circuits permission checks, so neither account under test is
    // the superuser.
    User::create(['name' => 'superuser'])->save();
    $this->first = User::create(['name' => 'first_editor']);
    $this->first->save();
    $this->second = User::create(['name' => 'second_editor']);
    $this->second->save();

    $this->seedFeed();
  }

  /**
   * Seeds the parsed-feed cache so no HTTP request is made.
   */
  protected function seedFeed(): void {
    $items = [];
    foreach ([self::NEWEST, self::MIDDLE, self::OLDEST] as $timestamp) {
      $items[] = [
        'title' => 'Item ' . $timestamp,
        'url' => 'https://example.com/' . $timestamp,
        'summary' => '',
        'timestamp' => $timestamp,
        'date' => 'January 1, 2026',
      ];
    }
    \Drupal::service('keyvalue.expirable')
      ->get(DashboardAnnouncements::STORE_COLLECTION)
      ->setWithExpire(DashboardAnnouncements::STORE_KEY, $items, 3600);
  }

  /**
   * Sets an account's stored last-seen timestamp.
   */
  protected function setLastSeen(User $account, int $timestamp): void {
    \Drupal::service('user.data')->set(
      DashboardAnnouncements::USER_DATA_MODULE,
      (int) $account->id(),
      DashboardAnnouncements::USER_DATA_LAST_SEEN,
      $timestamp
    );
  }

  /**
   * Runs the menu preprocess as the given account and returns the badge.
   *
   * Mirrors the real admin menu shape: the Dashboard link is nested under the
   * `system.admin` root wrapper rather than sitting at depth 0.
   *
   * Calls the badge attach helper rather than ys_core_preprocess_menu(), which
   * only dispatches to it on the `admin` menu. The wrapper also rewrites node
   * menu links from field_external_source, which would drag the whole node
   * entity system into a test about the toolbar badge.
   *
   * @return array
   *   The badge element ys_core_preprocess_menu() attached to the link title.
   */
  protected function buildBadgePlaceholder(User $account): array {
    \Drupal::currentUser()->setAccount($account);

    $variables = [
      'menu_name' => 'admin',
      'items' => [
        'system.admin' => [
          'title' => 'Administration',
          'url' => Url::fromRoute('system.admin'),
          'below' => [
            'ys_core.admin_dashboard' => [
              'title' => 'Dashboard',
              'url' => Url::fromRoute('ys_core.admin_dashboard'),
              'below' => [],
            ],
          ],
        ],
      ],
    ];
    _ys_core_attach_dashboard_badge($variables);

    return $variables['items']['system.admin']['below']['ys_core.admin_dashboard']['title']['#context']['badge'];
  }

  /**
   * Resolves a placeholder's #lazy_builder as the render pipeline would.
   *
   * Goes through core's callable resolver, the same service Renderer uses, so
   * the test does not encode its own assumptions about callback syntax.
   *
   * @return array
   *   The render array the lazy builder produced.
   */
  protected function replayLazyBuilder(array $placeholder): array {
    [$callback, $arguments] = $placeholder['#lazy_builder'];
    $callable = \Drupal::service('callable_resolver')->getCallableFromDefinition($callback);
    return call_user_func_array($callable, $arguments);
  }

  /**
   * Reads the rendered count out of a badge build, or NULL when absent.
   */
  protected static function badgeCount(array $build): ?int {
    return isset($build['badge']['#value']) ? (int) $build['badge']['#value'] : NULL;
  }

  /**
   * The placeholder must not differ between users sharing a cache entry.
   *
   * Asserts on the generated token, not just the arguments: the token is a hash
   * of the whole placeholder array, so this also catches user-varying cache
   * metadata being added to the element. Anything user-specific in there makes
   * one editor's token the token everybody else's shared toolbar entry is
   * stored with.
   */
  public function testPlaceholderIsIdenticalForEveryUser(): void {
    $this->setLastSeen($this->first, self::OLDEST);
    $this->setLastSeen($this->second, self::MIDDLE);

    $generator = \Drupal::service('render_placeholder_generator');
    $for_first = $generator->createPlaceholder($this->buildBadgePlaceholder($this->first));
    $for_second = $generator->createPlaceholder($this->buildBadgePlaceholder($this->second));

    $this->assertSame(
      (string) $for_first['#markup'],
      (string) $for_second['#markup'],
      'The badge placeholder carries nothing user-specific, so a shared toolbar cache entry is safe to reuse.'
    );
  }

  /**
   * The badge shows the viewer's count, not the count it was cached with.
   *
   * This is the reported bug: an editor pressed "mark all as read", their own
   * markers cleared, and the toolbar kept showing a count belonging to whoever
   * warmed the shared toolbar cache entry first.
   */
  public function testBadgeRendersForTheViewingUser(): void {
    // One unread for the first editor, none for the second.
    $this->setLastSeen($this->first, self::MIDDLE);
    $this->setLastSeen($this->second, self::NEWEST);

    $placeholder = $this->buildBadgePlaceholder($this->first);
    $this->assertSame(1, self::badgeCount($this->replayLazyBuilder($placeholder)));

    // The second editor now hits the entry the first editor warmed.
    \Drupal::currentUser()->setAccount($this->second);

    $this->assertNull(
      self::badgeCount($this->replayLazyBuilder($placeholder)),
      'The second editor has nothing unread, so no count is rendered for them.'
    );
  }

  /**
   * Clearing read state clears the badge on the very next render.
   *
   * Marking all read invalidates this user's badge tag, which only reaches
   * the badge if the badge is rendered for -- and tagged for -- the viewer.
   */
  public function testBadgeClearsAfterMarkingAllRead(): void {
    $this->setLastSeen($this->first, self::OLDEST);

    $placeholder = $this->buildBadgePlaceholder($this->first);
    $this->assertSame(2, self::badgeCount($this->replayLazyBuilder($placeholder)));

    \Drupal::service('ys_core.dashboard_announcements')->markAllRead($this->first);

    $this->assertNull(
      self::badgeCount($this->replayLazyBuilder($placeholder)),
      'The badge is gone once the editor has marked everything read.'
    );
  }

  /**
   * The rendered badge is invalidated by the viewer's own read state.
   */
  public function testBadgeCarriesTheViewingUsersCacheability(): void {
    $this->setLastSeen($this->second, self::OLDEST);

    $placeholder = $this->buildBadgePlaceholder($this->first);
    \Drupal::currentUser()->setAccount($this->second);
    $build = $this->replayLazyBuilder($placeholder);

    $this->assertContains('user', $build['#cache']['contexts']);
    $this->assertContains(
      DashboardAnnouncements::unreadCacheTag((int) $this->second->id()),
      $build['#cache']['tags'],
      'The badge is tagged for the user it was rendered for.'
    );
  }

  /**
   * An anonymous visitor gets no badge.
   */
  public function testAnonymousGetsNoBadge(): void {
    $this->setLastSeen($this->first, self::OLDEST);

    $placeholder = $this->buildBadgePlaceholder($this->first);
    \Drupal::currentUser()->setAccount(new AnonymousUserSession());

    $this->assertNull(self::badgeCount($this->replayLazyBuilder($placeholder)));
  }

}
