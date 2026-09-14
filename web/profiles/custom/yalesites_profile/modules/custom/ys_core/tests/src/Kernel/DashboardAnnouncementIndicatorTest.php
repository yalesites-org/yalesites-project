<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\user\Entity\User;
use Drupal\ys_core\Controller\DashboardController;
use Drupal\ys_core\DashboardAnnouncements;
use Drupal\ys_core\Form\MarkAnnouncementsReadForm;

/**
 * Tests the "new" announcement marker and its explicit clearing control.
 *
 * The toolbar badge tells an editor that something is new but not which item,
 * and the last-seen timestamp used to be stamped forward simply by loading the
 * dashboard -- so an editor passing through on their way to edit a page lost
 * their markers without reading anything. These tests pin the replacement
 * behaviour: a per-item marker, and clearing only ever as an explicit action.
 *
 * The dashboard renders in ys_admin_theme in production, but the markup under
 * test is the module's own template, so stark keeps the assertions about our
 * markup rather than Gin's.
 *
 * @group ys_core
 * @group yalesites
 */
class DashboardAnnouncementIndicatorTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'views', 'twig_tweak', 'ys_core'];

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
   * The editor whose read state is under test.
   */
  protected User $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);

    // ys_core's install config is deliberately not imported here: it carries
    // config objects without schema, and the announcement defaults this test
    // needs (enabled, and a feed URL that is never fetched because the parsed
    // feed is seeded below) are what the service already falls back to. Uid 1
    // is the superuser and short-circuits permission checks, so the read state
    // under test belongs to a regular account.
    $this->editor = User::create(['name' => 'editor']);
    $this->editor->save();

    $this->seedFeed();
  }

  /**
   * Seeds the parsed-feed cache so no HTTP request is made.
   *
   * The service returns the keyvalue entry verbatim when present, which is the
   * same path a real request takes on any page load after the first.
   */
  protected function seedFeed(): void {
    $items = [
      [
        'title' => 'Newest item',
        'url' => 'https://example.com/newest',
        'summary' => '',
        'timestamp' => self::NEWEST,
        'date' => 'March 1, 2026',
      ],
      [
        'title' => 'Middle item',
        'url' => 'https://example.com/middle',
        'summary' => '',
        'timestamp' => self::MIDDLE,
        'date' => 'February 1, 2026',
      ],
      [
        'title' => 'Oldest item',
        'url' => 'https://example.com/oldest',
        'summary' => '',
        'timestamp' => self::OLDEST,
        'date' => 'January 1, 2026',
      ],
    ];
    \Drupal::service('keyvalue.expirable')
      ->get(DashboardAnnouncements::STORE_COLLECTION)
      ->setWithExpire(DashboardAnnouncements::STORE_KEY, $items, 3600);
  }

  /**
   * Sets the editor's stored last-seen timestamp.
   */
  protected function setLastSeen(?int $timestamp): void {
    $user_data = \Drupal::service('user.data');
    if ($timestamp === NULL) {
      $user_data->delete(DashboardAnnouncements::USER_DATA_MODULE, (int) $this->editor->id(), DashboardAnnouncements::USER_DATA_LAST_SEEN);
      return;
    }
    $user_data->set(DashboardAnnouncements::USER_DATA_MODULE, (int) $this->editor->id(), DashboardAnnouncements::USER_DATA_LAST_SEEN, $timestamp);
  }

  /**
   * Reads the editor's stored last-seen timestamp.
   */
  protected function getLastSeen(): ?int {
    $value = \Drupal::service('user.data')
      ->get(DashboardAnnouncements::USER_DATA_MODULE, (int) $this->editor->id(), DashboardAnnouncements::USER_DATA_LAST_SEEN);
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * Builds the dashboard render array as the editor.
   */
  protected function buildDashboard(): array {
    \Drupal::currentUser()->setAccount($this->editor);
    return DashboardController::create($this->container)->content();
  }

  /**
   * Renders a build array and returns an XPath query over its markup.
   */
  protected function renderAndQuery(array $build): \DOMXPath {
    $html = (string) \Drupal::service('renderer')->renderInIsolation($build);

    $dom = new \DOMDocument();
    // The dashboard is a fragment, and Views may emit markup DOMDocument warns
    // about; neither is what these tests assert on.
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

    return new \DOMXPath($dom);
  }

  /**
   * Presses the "mark all as read" control as the editor.
   */
  protected function submitMarkAllRead(): void {
    \Drupal::currentUser()->setAccount($this->editor);
    // submitForm() takes both arguments by reference, so they have to be real
    // variables rather than inline expressions.
    $form_object = MarkAnnouncementsReadForm::create($this->container);
    $form_state = new FormState();
    \Drupal::formBuilder()->submitForm($form_object, $form_state);
  }

  /**
   * XPath predicate matching an element carrying the given class.
   */
  protected static function hasClass(string $class): string {
    return sprintf('contains(concat(" ", normalize-space(@class), " "), " %s ")', $class);
  }

  /**
   * Only unread announcements carry a marker, and the list order is unchanged.
   */
  public function testOnlyUnreadItemsAreMarkedNew(): void {
    $this->setLastSeen(self::MIDDLE);

    $xpath = $this->renderAndQuery($this->buildDashboard());

    $items = $xpath->query(sprintf('//li[%s]', self::hasClass('ys-dashboard__announcement')));
    $this->assertCount(3, $items, 'All three announcements render.');

    $titles = [];
    $marked = [];
    foreach ($items as $item) {
      // The marker lives inside the heading, so read the title from its link
      // rather than from the heading's whole text content.
      $titles[] = trim($xpath->query(sprintf('.//*[%s]//a', self::hasClass('ys-dashboard__announcement-title')), $item)->item(0)->textContent);
      $marked[] = $xpath->query(sprintf('.//*[%s]', self::hasClass('ys-dashboard__announcement-new')), $item)->count() === 1;
    }

    $this->assertSame(['Newest item', 'Middle item', 'Oldest item'], $titles, 'Marking an item new does not reorder the feed.');
    $this->assertSame([TRUE, FALSE, FALSE], $marked, 'Only the announcement newer than last-seen is marked.');
  }

  /**
   * Several announcements can be marked new at once.
   */
  public function testEveryUnreadItemIsMarkedWhenNothingHasBeenSeen(): void {
    $this->setLastSeen(NULL);

    $xpath = $this->renderAndQuery($this->buildDashboard());

    $this->assertSame(3, $xpath->query(sprintf('//*[%s]', self::hasClass('ys-dashboard__announcement-new')))->count());
  }

  /**
   * The marker is not communicated by colour alone.
   *
   * WCAG 1.4.1: the visual pill must be backed by text a screen reader reads,
   * and it must sit inside the announcement's own list item so it is announced
   * next to the title rather than orphaned from it.
   */
  public function testMarkerCarriesTextEquivalentNextToItsTitle(): void {
    $this->setLastSeen(self::MIDDLE);

    $xpath = $this->renderAndQuery($this->buildDashboard());

    $markers = $xpath->query(sprintf('//li[%s]//*[%s]', self::hasClass('ys-dashboard__announcement'), self::hasClass('ys-dashboard__announcement-new')));
    $this->assertCount(1, $markers, 'The marker lives inside the announcement it describes.');

    $text = preg_replace('/\s+/', ' ', trim($markers->item(0)->textContent));
    $this->assertStringContainsStringIgnoringCase('new', $text, 'The marker has a text equivalent, not colour alone.');
    $this->assertStringContainsStringIgnoringCase(
      'announcement',
      $text,
      'The marker names what is new so it stands on its own when read out of context.'
    );
  }

  /**
   * The clearing control is offered only when something is actually unread.
   */
  public function testClearingControlIsHiddenWhenNothingIsUnread(): void {
    $this->setLastSeen(self::NEWEST);

    $build = $this->buildDashboard();
    $xpath = $this->renderAndQuery($build);

    $this->assertSame(0, $xpath->query(sprintf('//*[%s]', self::hasClass('ys-dashboard__announcement-new')))->count(), 'Nothing is marked new.');
    $this->assertArrayNotHasKey('#mark_all_read_form', $build, 'No clearing control is built when there is nothing to clear.');
  }

  /**
   * The clearing control is a real, self-describing, keyboard-operable button.
   */
  public function testClearingControlIsKeyboardOperable(): void {
    $this->setLastSeen(self::MIDDLE);

    $build = $this->buildDashboard();
    $this->assertArrayHasKey('#mark_all_read_form', $build, 'A clearing control is built when something is unread.');

    $xpath = $this->renderAndQuery($build);
    $buttons = $xpath->query('//form//input[@type="submit"] | //form//button[@type="submit"]');
    $this->assertGreaterThan(0, $buttons->count(), 'The control is a real submit button, so it is keyboard operable.');

    $button = $buttons->item(0);
    $name = $button->hasAttribute('value') ? $button->getAttribute('value') : $button->textContent;
    // Names both the action and what it acts on, so it holds up when a screen
    // reader user reads the button out of its surrounding context.
    $this->assertStringContainsStringIgnoringCase('mark all announcements as read', $name);
  }

  /**
   * Viewing the dashboard leaves read state untouched.
   *
   * This is the regression this ticket exists for: the previous behaviour
   * stamped last-seen forward on every page view, so glancing at the dashboard
   * silently burned the editor's unread markers.
   */
  public function testViewingTheDashboardDoesNotClearReadState(): void {
    $this->setLastSeen(self::OLDEST);

    $this->renderAndQuery($this->buildDashboard());
    $this->renderAndQuery($this->buildDashboard());

    $this->assertSame(self::OLDEST, $this->getLastSeen(), 'Rendering the dashboard did not advance last-seen.');
    $this->assertSame(
      2,
      \Drupal::service('ys_core.dashboard_announcements')->getUnreadCount($this->editor),
      'The unread count survives repeat visits.'
    );
  }

  /**
   * A view by a user who has never seen anything still writes nothing.
   */
  public function testViewingTheDashboardWritesNoReadStateAtAll(): void {
    $this->setLastSeen(NULL);

    $this->renderAndQuery($this->buildDashboard());

    $this->assertNull($this->getLastSeen(), 'No last-seen value was created by viewing the dashboard.');
  }

  /**
   * Submitting the control clears every marker and the toolbar badge.
   *
   * The badge is a per-user lazy builder keyed on unreadCacheTag(), so the
   * clearing action has to invalidate that tag for the badge to catch up
   * without a manual cache rebuild.
   */
  public function testSubmittingTheControlClearsMarkersAndBadge(): void {
    $this->setLastSeen(self::OLDEST);
    \Drupal::currentUser()->setAccount($this->editor);

    $tag = DashboardAnnouncements::unreadCacheTag((int) $this->editor->id());
    $checksum = \Drupal::service('cache_tags.invalidator.checksum');
    $before = $checksum->getCurrentChecksum([$tag]);

    $this->submitMarkAllRead();

    $this->assertSame(self::NEWEST, $this->getLastSeen(), 'Read state advanced to the newest announcement.');
    $this->assertSame(
      0,
      \Drupal::service('ys_core.dashboard_announcements')->getUnreadCount($this->editor),
      'Nothing is unread after clearing.'
    );
    $this->assertNotSame($before, $checksum->getCurrentChecksum([$tag]), 'The per-user badge cache tag was invalidated.');

    $xpath = $this->renderAndQuery($this->buildDashboard());
    $this->assertSame(0, $xpath->query(sprintf('//*[%s]', self::hasClass('ys-dashboard__announcement-new')))->count(), 'No markers remain.');
  }

  /**
   * Clearing confirms itself to assistive technology, not just visually.
   */
  public function testSubmittingTheControlAnnouncesTheResult(): void {
    $this->setLastSeen(self::OLDEST);

    $this->submitMarkAllRead();

    $messages = \Drupal::messenger()->messagesByType('status');
    $this->assertNotEmpty($messages, 'A status message confirms the action.');
    $this->assertStringContainsStringIgnoringCase(
      'marked as read',
      implode(' ', array_map('strval', $messages)),
      'The confirmation says what happened rather than relying on the markers simply vanishing.'
    );
  }

  /**
   * The per-user render must not be shared between editors.
   *
   * The markers vary per user, so the page has to carry the user cache context
   * and the per-user badge tag or one editor's markers get served to another
   * from the shared render cache.
   */
  public function testRenderIsCachedPerUser(): void {
    $this->setLastSeen(self::MIDDLE);

    $build = $this->buildDashboard();

    $this->assertContains('user', $build['#cache']['contexts'], 'The render varies per user.');
    $this->assertContains(
      DashboardAnnouncements::unreadCacheTag((int) $this->editor->id()),
      $build['#cache']['tags'],
      'The render is invalidated when this user clears their announcements.'
    );
  }

}
