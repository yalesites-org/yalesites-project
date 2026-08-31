<?php

namespace Drupal\ys_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\InfoParserInterface;
use Drupal\ys_core\DashboardAnnouncements;
use Drupal\ys_core\Form\MarkAnnouncementsReadForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the dashboard page.
 */
class DashboardController extends ControllerBase {

  /**
   * The info parser service.
   *
   * @var \Drupal\Core\Extension\InfoParserInterface
   */
  protected $infoParser;

  /**
   * The dashboard announcements service.
   *
   * @var \Drupal\ys_core\DashboardAnnouncements
   */
  protected DashboardAnnouncements $announcements;

  /**
   * Constructs a DashboardController object.
   *
   * @param \Drupal\Core\Extension\InfoParserInterface $info_parser
   *   The info parser service.
   * @param \Drupal\ys_core\DashboardAnnouncements $announcements
   *   The dashboard announcements service.
   */
  public function __construct(InfoParserInterface $info_parser, DashboardAnnouncements $announcements) {
    $this->infoParser = $info_parser;
    $this->announcements = $announcements;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('info_parser'),
      $container->get('ys_core.dashboard_announcements')
    );
  }

  /**
   * Dashboard page contents.
   */
  public function content() {
    $account = $this->currentUser();
    // Deliberately read-only -- clearing is the control below, not the visit.
    // @see \Drupal\ys_core\DashboardAnnouncements::markAllRead()
    $items = $this->announcements->getAnnouncementsForUser($account);
    $unread = DashboardAnnouncements::countUnread($items);

    $build = [
      '#theme' => 'ys_dashboard',
      '#platform_version' => $this->getPlatformVersion(),
      '#announcements' => $items,
      '#cache' => [
        // The "new" markers vary per user, so this render must never be shared
        // between editors. Declared explicitly rather than leaned on: the page
        // already picks up `user` from its embedded views, and `user` subsumes
        // the `user.permissions` context this previously named, but the markers
        // are the reason it is required rather than incidental.
        'contexts' => ['user'],
        'tags' => [
          'config:ys_core.dashboard_settings',
          DashboardAnnouncements::FEED_CACHE_TAG,
          // Dropped when this user marks their announcements as read, so the
          // page loses its markers in the same interaction as the toolbar
          // badge, which depends on the same tag.
          DashboardAnnouncements::unreadCacheTag($account->id()),
        ],
        // Bounds this render's own freshness against the consumer feed cache,
        // so a pure-consumer site (where no local node hook fires) still picks
        // up new announcements within the hour. Note the response as a whole is
        // not cached anyway -- the embedded form's zero max-age bubbles up and
        // Dynamic Page Cache declines anything carrying the `user` context.
        'max-age' => 3600,
      ],
    ];

    // Offering a button that would do nothing is worse than not offering one,
    // so the control only exists while there is something to clear.
    if ($unread > 0) {
      $build['#mark_all_read_form'] = $this->formBuilder()->getForm(MarkAnnouncementsReadForm::class);
    }

    return $build;
  }

  /**
   * Gets the YaleSites platform version from the profile info file.
   *
   * @return string|null
   *   The platform version string, or NULL if not found.
   */
  protected function getPlatformVersion() {
    $profile_path = DRUPAL_ROOT . '/profiles/custom/yalesites_profile/yalesites_profile.info.yml';

    if (file_exists($profile_path)) {
      try {
        $info = $this->infoParser->parse($profile_path);
        return $info['version'] ?? NULL;
      }
      catch (\Exception $e) {
        // Log error but don't break the dashboard.
        $this->getLogger('ys_core')->error('Failed to parse YaleSites profile info file: @message', ['@message' => $e->getMessage()]);
      }
    }

    return NULL;
  }

}
