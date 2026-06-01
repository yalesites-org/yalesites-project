<?php

namespace Drupal\ys_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\InfoParserInterface;
use Drupal\ys_core\DashboardAnnouncements;
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
    return [
      '#theme' => 'ys_dashboard',
      '#platform_version' => $this->getPlatformVersion(),
      '#announcements' => $this->announcements->getAnnouncements(),
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => ['config:ys_core.dashboard_settings'],
      ],
    ];
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
