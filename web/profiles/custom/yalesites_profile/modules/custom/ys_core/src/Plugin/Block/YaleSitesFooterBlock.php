<?php

namespace Drupal\ys_core\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\ys_core\SocialLinksManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds a footer block with logos, text, links, and social from footer settings.
 *
 * @Block(
 *   id = "ys_footer_block",
 *   admin_label = @Translation("YaleSites Footer Block"),
 *   category = @Translation("YaleSites Core"),
 * )
 */
class YaleSitesFooterBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Social Links Manager.
   *
   * @var \Drupal\ys_core\SocialLinksManager
   */
  protected $socialLinks;

  /**
   * Footer settings.
   *
   * @var Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $footerSettings;

  /**
   * Entity type manager.
   *
   * @var Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    SocialLinksManager $social_links_manager,
    ConfigFactoryInterface $config_factory,
    EntityTypeManager $entity_type_manager,
    ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->socialLinks = $social_links_manager;
    $this->footerSettings = $config_factory->get('ys_core.footer_settings');
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ys_core.social_links_manager'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $fileEntity = $this->entityTypeManager->getStorage('file');
    $footerLogosRender = $schoolLogoRender = [];

    // Responsive image render array for logos.
    if ($footerLogosConfig = $this->footerSettings->get('content.logos')) {
      foreach ($footerLogosConfig as $key => $logoData) {
        if ($logoData['logo']) {
          $footerLogoMedia = $this->entityTypeManager->getStorage('media')->load($logoData['logo']);
          $footerLogoFileUri = $fileEntity->load($footerLogoMedia->field_media_image->target_id)->getFileUri();
          $footerLogosRender[$key]['url'] = $logoData['logo_url'] ?? NULL;
          $footerLogosRender[$key]['logo'] = [
            '#type' => 'responsive_image',
            '#responsive_image_style_id' => 'image_logos',
            '#uri' => $footerLogoFileUri,
            '#attributes' => [
              'alt' => $footerLogoMedia->get('field_media_image')->first()->get('alt')->getValue(),
            ],
          ];
        }
      }
    }

    // Responsive image render array for school logo.
    if ($schoolLogoId = $this->footerSettings->get('content.school_logo')) {
      $schoolLogoMedia = $this->entityTypeManager->getStorage('media')->load($schoolLogoId);
      $schoolLogoFileUri = $fileEntity->load($schoolLogoMedia->field_media_image->target_id)->getFileUri();
      $schoolLogoRender = [
        '#type' => 'responsive_image',
        '#responsive_image_style_id' => 'image_horizontal_logos',
        '#uri' => $schoolLogoFileUri,
        '#attributes' => [
          'alt' => $schoolLogoMedia->get('field_media_image')->first()->get('alt')->getValue(),
        ],
      ];
    }

    $footerBlockRender = [
      '#theme' => 'ys_footer_block',
      '#footer_variation' => $this->footerSettings->get('footer_variation'),
      '#footer_logos' => $footerLogosRender,
      '#school_logo' => $schoolLogoRender,
      '#school_logo_url' => $this->footerSettings->get('content.school_logo_url'),
      '#footer_text' => [
        '#type' => 'processed_text',
        '#text' => $this->footerSettings->get('content.text')['value'] ?? NULL,
        '#format' => 'restricted_html',
      ],
      '#footer_links_col_1_heading' => $this->footerSettings->get('links.links_col_1_heading'),
      '#footer_links_col_2_heading' => $this->footerSettings->get('links.links_col_2_heading'),
      '#footer_links_col_1' => $this->footerSettings->get('links.links_col_1'),
      '#footer_links_col_2' => $this->footerSettings->get('links.links_col_2'),
    ];

    return $footerBlockRender;
  }

}
