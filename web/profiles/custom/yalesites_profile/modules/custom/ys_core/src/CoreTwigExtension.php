<?php

namespace Drupal\ys_core;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
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
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs the object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration interface.
   * @param \Drupal\ys_core\YaleSitesMediaManager $yale_media_manager
   *   The YaleSites Media Manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The Request Stack to retrieve the domain.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(ConfigFactoryInterface $configFactory, YaleSitesMediaManager $yale_media_manager, RequestStack $requestStack, LoggerChannelFactoryInterface $logger_factory) {
    $this->yaleCoreSettings = $configFactory->getEditable('ys_core.site');
    $this->yaleHeaderSettings = $configFactory->getEditable('ys_core.header_settings');
    $this->yaleMediaManager = $yale_media_manager;
    $this->requestStack = $requestStack;
    $this->loggerFactory = $logger_factory;
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
      new TwigFunction('getQueryParam', [$this, 'getQueryParam']),
      new TwigFunction('getAssetPath', [$this, 'getAssetPath']),
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
    return $this->urlHasCurrentDomain($url) || $this->isQueryString($url) || $this->isAnchor($url) || $this->isRelative($url) || $this->isData($url);
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
      'rtf',
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
   * Check if a URL is a query string.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if the URL is a query string.
   */
  private function isQueryString($url) {
    return str_starts_with($url, '?');
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

  /**
   * Retrieve a query parameter from the current request.
   *
   * @param string $parameter_name
   *   The name of the query parameter to retrieve.
   *
   * @return mixed
   *   The value of the query parameter, or null if it does not exist.
   */
  public function getQueryParam($parameter_name) {
    return $this->requestStack->getCurrentRequest()->query->get($parameter_name);
  }

  /**
   * Get the versioned asset path from the webpack manifest.
   *
   * @param string $asset_name
   *   The original asset filename (e.g., 'icons.svg').
   * @param string $directory
   *   Optional directory path for theme (e.g. 'themes/contrib/atomic').
   *
   * @return string
   *   The versioned asset path, or the original filename if manifest not found.
   */
  public function getAssetPath($asset_name, $directory = NULL) {
    $logger = $this->loggerFactory->get('ys_core');

    // Build list of possible manifest paths to check.
    $possible_paths = [];

    // If directory is provided, construct path relative to that directory.
    if ($directory) {
      $possible_paths[] = DRUPAL_ROOT . '/' . $directory . '/node_modules/@yalesites-org/component-library-twig/dist/manifest.json';
      // Also check in _yale-packages (local development).
      $possible_paths[] = DRUPAL_ROOT . '/' . $directory . '/_yale-packages/component-library-twig/dist/manifest.json';
    }
    else {
      // Default to themes/contrib/atomic.
      $possible_paths[] = DRUPAL_ROOT . '/themes/contrib/atomic/node_modules/@yalesites-org/component-library-twig/dist/manifest.json';
      // Also check in _yale-packages (local development).
      $possible_paths[] = DRUPAL_ROOT . '/themes/contrib/atomic/_yale-packages/component-library-twig/dist/manifest.json';
    }

    // Log all paths being checked.
    $logger->debug('getAssetPath: Checking manifest paths for @asset', [
      '@asset' => $asset_name,
      'paths' => $possible_paths,
    ]);

    // Try each possible path until we find the manifest.
    $manifest_path = NULL;
    foreach ($possible_paths as $path) {
      if (file_exists($path)) {
        $manifest_path = $path;
        $logger->info('getAssetPath: Found manifest at @path', ['@path' => $path]);
        break;
      }
      else {
        $logger->debug('getAssetPath: Manifest not found at @path', ['@path' => $path]);
      }
    }

    // If manifest file doesn't exist, fallback to original filename.
    if (!$manifest_path) {
      $logger->warning('getAssetPath: Manifest not found for @asset, using original filename', ['@asset' => $asset_name]);
      return $asset_name;
    }

    // Read and parse the manifest.
    $manifest_content = file_get_contents($manifest_path);
    $manifest = json_decode($manifest_content, TRUE);

    // Check if manifest is valid and contains the asset.
    if (!is_array($manifest) || !isset($manifest[$asset_name])) {
      $logger->warning('getAssetPath: Asset @asset not found in manifest, using original filename', [
        '@asset' => $asset_name,
        'manifest_keys' => is_array($manifest) ? array_keys($manifest) : 'invalid manifest',
      ]);
      // Fallback to original filename if asset not in manifest.
      return $asset_name;
    }

    $versioned_filename = $manifest[$asset_name];
    $logger->info('getAssetPath: Resolved @asset to @versioned', [
      '@asset' => $asset_name,
      '@versioned' => $versioned_filename,
      '@manifest_path' => $manifest_path,
    ]);

    // Return the versioned filename from manifest.
    return $versioned_filename;
  }

}
