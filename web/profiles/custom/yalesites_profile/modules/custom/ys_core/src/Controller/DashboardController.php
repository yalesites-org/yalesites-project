<?php

namespace Drupal\ys_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\InfoParserInterface;
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
   * Constructs a DashboardController object.
   *
   * @param \Drupal\Core\Extension\InfoParserInterface $info_parser
   *   The info parser service.
   */
  public function __construct(InfoParserInterface $info_parser) {
    $this->infoParser = $info_parser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('info_parser')
    );
  }

  /**
   * Dashboard page contents.
   */
  public function content() {
    $platform_version = $this->getPlatformVersion();

    return [
      '#theme' => 'ys_dashboard',
      '#platform_version' => $platform_version,
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
