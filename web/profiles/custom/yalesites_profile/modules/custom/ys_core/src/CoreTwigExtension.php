<?php

namespace Drupal\ys_core;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ys_core\YaleSitesMediaManager;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig functions to retrieve YaleSites core settings.
 */
class CoreTwigExtension extends AbstractExtension {

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $yaleCoreSettings;

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $yaleHeaderSettings;

  /**
   * The YaleSites Media Manager.
   *
   * @var \Drupal\ys_core\YaleSitesMediaManager
   */
  protected $yaleMediaManager;

  /**
   * Constructs the object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration interface.
   * @param \Drupal\ys_core\YaleSitesMediaManager $yale_media_manager
   *   The YaleSites Media Manager.
   */
  public function __construct(ConfigFactoryInterface $configFactory, YaleSitesMediaManager $yale_media_manager) {
    $this->yaleCoreSettings = $configFactory->getEditable('ys_core.site');
    $this->yaleHeaderSettings = $configFactory->getEditable('ys_core.header_settings');
    $this->yaleMediaManager = $yale_media_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getFunctions() {
    return [
      new TwigFunction('getCoreSetting', [$this, 'getCoreSetting']),
      new TwigFunction('getHeaderSetting', [$this, 'getHeaderSetting']),
    ];
  }

  /**
   * Actual function that returns core setting based on setting machine name.
   *
   * @param string $setting_name
   *   Setting machine name to pass in to retrieve setting from config.
   *
   * @return string
   *   Setting value from ys_core.site.
   */
  public function getCoreSetting($setting_name) {
    return($this->yaleCoreSettings->get($setting_name));
  }

  /**
   * Actual function that returns header setting based on setting machine name.
   *
   * @param string $setting_name
   *   Setting machine name to pass in to retrieve setting from config.
   *
   * @return string
   *   Setting value from ys_core.site.
   */
  public function getHeaderSetting($setting_name) {
    if ($setting_name == 'site_name_image') {
      $siteNameSVG = FALSE;
      if ($fid = $this->yaleHeaderSettings->get('site_name_image')) {
        $siteNameSVG = $this->yaleMediaManager->getSiteNameImage($fid[0]);
      }

      return $siteNameSVG;
    }
    else {
      return($this->yaleHeaderSettings->get($setting_name));
    }
  }

}
