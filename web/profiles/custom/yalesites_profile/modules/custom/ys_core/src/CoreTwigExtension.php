<?php

namespace Drupal\ys_core;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
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
   * The Request Stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The current domain.
   *
   * @var string
   */
  protected $currentDomain;

  /**
   * Constructs the object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration interface.
   * @param \Drupal\ys_core\YaleSitesMediaManager $yale_media_manager
   *   The YaleSites Media Manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The Request Stack to retrieve the domain.
   */
  public function __construct(ConfigFactoryInterface $configFactory, YaleSitesMediaManager $yale_media_manager, RequestStack $requestStack) {
    $this->yaleCoreSettings = $configFactory->getEditable('ys_core.site');
    $this->yaleHeaderSettings = $configFactory->getEditable('ys_core.header_settings');
    $this->yaleMediaManager = $yale_media_manager;
    $this->requestStack = $requestStack;
    $this->currentDomain = $this->getCurrentDomain();
  }

  /**
   * {@inheritdoc}
   */
  public function getFunctions() {
    return [
      new TwigFunction('getCoreSetting', [$this, 'getCoreSetting']),
      new TwigFunction('getHeaderSetting', [$this, 'getHeaderSetting']),
      new TwigFunction('getUrlType', [$this, 'getUrlType']),
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

  /**
   * Given a URL, it will return the type of URL it is.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return string
   *   The type of URL it is.
   */
  public function getUrlType($url) {
    if ($this->isDownload($url)) {
      $urlType = 'download';
    }
    elseif ($this->isInternal($url)) {
      $urlType = 'internal';
    }
    elseif ($this->isMailTo($url)) {
      $urlType = 'mailto';
    }
    elseif ($this->isExternal($url)) {
      $urlType = 'external';
    }
    else {
      $urlType = 'internal';
    }

    return $urlType;
  }

  /**
   * Check if a URL is internal.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if the URL is internal.
   */
  private function isInternal($url) {
    return $this->urlHasCurrentDomain($url) || $this->isAnchor($url) || $this->isRelative($url) || $this->isData($url);
  }

  /**
   * Check if a URL is external.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if the URL is external.
   */
  private function isExternal($url) {
    return !$this->isInternal($url);
  }

  /**
   * Check if a URL is a download.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if the URL is download.
   */
  private function isDownload($url) {
    $fileExtensions = array_map('strtolower', [
      'pdf',
      'doc',
      'docx',
      'xls',
      'xlsx',
      'ppt',
      'pptx',
      'zip',
      'csv',
      'xml',
    ]);
    $extension = strtolower(pathinfo($url, PATHINFO_EXTENSION));
    return in_array($extension, $fileExtensions);
  }

  /**
   * Check if a URL has the current domain.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if the URL has the current domain.
   */
  private function urlHasCurrentDomain($url) {
    $urlDomain = parse_url($url, PHP_URL_HOST);

    if (empty($urlDomain)) {
      return TRUE;
    }

    return $urlDomain === $this->currentDomain;
  }

  /**
   * Get the current domain.
   *
   * @return string
   *   The current domain.
   */
  private function getCurrentDomain() {
    $request = $this->requestStack->getCurrentRequest();

    return $request->getHost();
  }

  /**
   * Check if a URL is an anchor.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if the URL is an anchor.
   */
  private function isAnchor($url) {
    return str_starts_with($url, '#');
  }

  /**
   * Check if a URL is relative URL.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if the URL is a relative URL.
   */
  private function isRelative($url) {
    return str_starts_with($url, '/');
  }

  /**
   * Check if a URL is data URL.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if the URL is a data URL.
   */
  private function isData($url) {
    return str_starts_with($url, 'data:');
  }

  /**
   * Check if a URL is mailto.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if the URL is mailto.
   */
  private function isMailTo($url) {
    return str_starts_with($url, 'mailto:');
  }

}
