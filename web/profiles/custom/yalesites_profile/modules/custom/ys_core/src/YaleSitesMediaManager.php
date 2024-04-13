<?php

namespace Drupal\ys_core;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\File\FileUrlGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service for managing custom media associated with a YaleSite.
 */
class YaleSitesMediaManager extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $yaleSettings;

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $siteSettings;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGenerator
   */
  protected $fileUrlGenerator;

  /**
   * Constructs a SiteInformationForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileUrlGenerator $file_url_generator
   *   The file URL generator.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManager $entity_type_manager,
    FileUrlGenerator $file_url_generator
    ) {
    $this->yaleSettings = $config_factory->get('ys_core.site');
    $this->siteSettings = $config_factory->get('system.site');
    $this->entityTypeManager = $entity_type_manager;
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('file_url_generator')
    );
  }

  /**
   * Creates an array to render favicon markup, either custom or fallback.
   *
   * @return array
   *   Favicon data for ys_core_page_attachments() to render in the head.
   */
  public function getFavicons() {
    $customFaviconId = ($this->yaleSettings->get('custom_favicon')) ? $this->yaleSettings->get('custom_favicon')[0] : NULL;

    $faviconData = [
      'apple-touch-icon' => [
        '#image_style' => 'favicon_180x180',
        '#tag' => 'link',
        '#attributes' => [
          'rel' => 'apple-touch-icon',
          'href' => '/profiles/custom/yalesites_profile/modules/custom/ys_core/images/favicons/apple-touch-icon.png',
        ],
      ],
      'icon-32' => [
        '#image_style' => 'favicon_32x32',
        '#tag' => 'link',
        '#attributes' => [
          'sizes' => '32x32',
          'rel' => 'icon',
          'type' => 'image/png',
          'href' => '/profiles/custom/yalesites_profile/modules/custom/ys_core/images/favicons/favicon-32x32.png',
        ],
      ],
      'icon-16' => [
        '#image_style' => 'favicon_16x16',
        '#tag' => 'link',
        '#attributes' => [
          'sizes' => '16x16',
          'rel' => 'icon',
          'type' => 'image/png',
          'href' => '/profiles/custom/yalesites_profile/modules/custom/ys_core/images/favicons/favicon-16x16.png',
        ],
      ],
      'icon-ico' => [
        '#image_style' => 'favicon_16x16_ico',
        '#tag' => 'link',
        '#attributes' => [
          'rel' => 'shortcut icon',
          'href' => '/profiles/custom/yalesites_profile/modules/custom/ys_core/images/favicons/favicon.ico',
        ],
      ],
    ];

    if ($customFaviconId) {
      /*
       * If we have a custom favicon, get favicon image styles
       * (if they exist) and override the fallback icons.
       *
       */
      /** @var \Drupal\file\Entity\File $fileEntity */
      $fileEntity = $this->entityTypeManager->getStorage('file');
      /** @var \Drupal\image\Entity\ImageStyle $imageStyle */
      $imageStyle = $this->entityTypeManager->getStorage('image_style');
      $file = $fileEntity->load($customFaviconId);
      if ($file) {
        foreach ($faviconData as $key => $favicon) {
          if ($style = $imageStyle->load($favicon['#image_style'])) {
            $faviconData[$key]['#attributes']['href'] = $this->fileUrlGenerator->transformRelative($style->buildUrl($file->getFileUri()));
          }
          else {
            unset($faviconData[$key]);
          }
        }
      }
    }

    return $faviconData;
  }

  /**
   * Handles the creation and deletion of custom media in the filesystem.
   *
   * @param array $formValue
   *   An array with the form value of the media selected, if any.
   * @param array $configValue
   *   An array with the config value of the media saved, if any.
   */
  public function handleMediaFilesystem($formValue, $configValue) {
    $incomingFormValue = $formValue ? $formValue[0] : NULL;
    $incomingConfigValue = $configValue ? $configValue[0] : NULL;

    if ($incomingFormValue != $incomingConfigValue) {
      $fileEntity = $this->entityTypeManager->getStorage('file');

      // First, delete any previously set media.
      if ($incomingConfigValue) {
        /** @var \Drupal\file\Entity $file */
        $file = $fileEntity->load($incomingConfigValue);
        if ($file) {
          $file->delete();
        }
      }

      // Next, set the new media.
      if ($incomingFormValue) {
        /** @var \Drupal\file\Entity $file */
        $file = $fileEntity->load($incomingFormValue);
        $file->setPermanent();
        $file->save();
      }

    }
  }

  /**
   * Gets SVG for the uploaded site name as image and adds site name as title.
   *
   * @param int $fid
   *   File ID.
   *
   * @return string
   *   SVG in raw form for use in twig templates.
   */
  public function getSiteNameImage($fid) {

    /** @var \Drupal\file\Entity\File $file */
    if ($file = $this->entityTypeManager()
      ->getStorage('file')
      ->load($fid)) {
      $title = $this->siteSettings->get('name');
      if (str_ends_with($file->getFilename(), '.svg')) {
        $fileData = file_get_contents($file->getFileUri(), TRUE);
        $titlePattern = "/<title\\b[^>]*>(.*?)<\\/title>/";

        if (preg_match($titlePattern, $fileData)) {
          // We have a title tag, let's replace it.
          $replacement = "<title>{$title}</title>";
          $replacementSVG = preg_replace($titlePattern, $replacement, $fileData);
        }
        else {
          // No title tag, so let's add one right below the opening svg tag.
          $svgPattern = "/(<svg\\b[^>]*>)/";
          $replacement = "$1\n<title>{$title}</title>";
          $replacementSVG = preg_replace($svgPattern, $replacement, $fileData);
        }

        return $replacementSVG;
      }
    }
  }

}
