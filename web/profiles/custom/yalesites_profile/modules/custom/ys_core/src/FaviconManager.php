<?php

namespace Drupal\ys_core;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\File\FileUrlGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service for managing the favicon associated with a YaleSite.
 */
class FaviconManager extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $yaleSettings;

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

  public function getFavicons() {
    $customFaviconId = ($this->yaleSettings->get('custom_favicon')) ? $this->yaleSettings->get('custom_favicon')[0] : NULL;
    $faviconData = ($customFaviconId) ? $this->getCustomFavicon($customFaviconId) : $this->getFallbackFavicon();

    return $faviconData;
  }

  private function getCustomFavicon($fid) {
    /** @var \Drupal\file\Entity\File $fileEntity */
    $fileEntity = $this->entityTypeManager->getStorage('file');
    /** @var \Drupal\image\Entity\ImageStyle $imageStyle */
    $imageStyle = $this->entityTypeManager->getStorage('image_style');
    $file = $fileEntity->load($fid);

    $faviconData['apple-touch-icon'] = [
      '#tag' => 'link',
      '#attributes' => [
        'href' => $this->fileUrlGenerator->transformRelative($imageStyle->load('favicon_180x180')->buildUrl($file->getFileUri())),
        'sizes' => '180x180',
        'rel' => 'apple-touch-icon',
      ],
    ];

    $faviconData['icon-32'] = [
      '#tag' => 'link',
      '#attributes' => [
        'href' => $this->fileUrlGenerator->transformRelative($imageStyle->load('favicon_32x32')->buildUrl($file->getFileUri())),
        'sizes' => '32x32',
        'rel' => 'icon',
        'type' => 'image/png',
      ],
    ];

    $faviconData['icon-16'] = [
      '#tag' => 'link',
      '#attributes' => [
        'href' => $this->fileUrlGenerator->transformRelative($imageStyle->load('favicon_16x16')->buildUrl($file->getFileUri())),
        'sizes' => '16x16',
        'rel' => 'icon',
        'type' => 'image/png',
      ],
    ];

    return $faviconData;

  }

  private function getFallbackFavicon() {
    return "Fallback";
  }

}


/**
 * Implements hook_page_attachments_alter().
 */
// function ys_core_page_attachments(array &$page) {
//   // Add favicons to page, either custom or fallback.

//   $config = \Drupal::config('ys_core.site');

//   // if($fid = $config->get('custom_favicon')) {
//   //   $file = File::load($fid[0]);
//   //   $favicons['apple-touch-icon'] = [
//   //     'href' => \Drupal::service('file_url_generator')
//   //       ->transformRelative(\Drupal\image\Entity\ImageStyle::load('favicon_180x180')
//   //       ->buildUrl($file->getFileUri())),
//   //     'sizes' => '180x180',
//   //   ];
//   // }

//   $favicons = [
//     '#type' => 'html_tag',
//     '#tag' => 'link',
//     '#attributes' => [
//       'rel' => 'testing',
//       'content' => 'atrus',
//     ],
//     '#type' => 'html_tag',
//     '#tag' => 'link',
//     '#attributes' => [
//       'rel' => 'testing2',
//       'href' => 'thisishref',
//     ]
//   ];
//   $page['#attached']['html_head'][] = [$favicons, 'ys_core_favicons'];
// }
