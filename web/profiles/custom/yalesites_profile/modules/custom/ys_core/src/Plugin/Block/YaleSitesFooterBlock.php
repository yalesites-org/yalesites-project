<?php

namespace Drupal\ys_core\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
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
   * Drupal messenger.
   *
   * @var Drupal\Core\Messenger\Messenger
   */
  protected $messenger;

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
    Messenger $messenger,
    ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->socialLinks = $social_links_manager;
    $this->footerSettings = $config_factory->getEditable('ys_core.footer_settings');
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
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
      $container->get('messenger'),
    );
  }

  /**
   * Removes config for a deleted media item if used in the footer.
   *
   * @param string $configName
   *   The configuration key to remove.
   */
  protected function clearFooterConfig($configName) {
    $this->footerSettings->set($configName, NULL);
    $this->footerSettings->save();
    $logoType = NULL;

    if (str_starts_with($configName, 'content.logos')) {
      $logoType = 'one of the footer logo images';
    }
    else {
      $logoType = 'the school footer logo image';
    }
    if ($logoType) {
      $footerSettingsPath = Url::fromRoute('ys_core.admin_footer_settings')->toString();
      $message = $this->t('Note, :logo_type has been deleted. You may set a new one in the <a href=":footer_settings_path">footer settings form<a>.',
        [
          ':logo_type' => $logoType,
          ':footer_settings_path' => $footerSettingsPath,
        ]);
      $this->messenger->addError($message);
    }
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
        if (isset($logoData['logo'])) {
          // This check is because if a media item is deleted and one added,
          // it creates an array of ID's which does not work.
          if (is_numeric($logoData['logo'])) {
            $footerLogoMedia = $this->entityTypeManager->getStorage('media')->load($logoData['logo']);
            if ($footerLogoMedia) {
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
            else {
              $this->clearFooterConfig('content.logos.' . $key);
            }
          }
          else {
            $this->clearFooterConfig('content.logos.' . $key);
          }
        }
      }
    }

    // Responsive image render array for school logo.
    if ($schoolLogoId = $this->footerSettings->get('content.school_logo')) {
      // This check is because if a media item is deleted and one added,
      // it creates an array of ID's which does not work.
      if (is_numeric($schoolLogoId)) {
        if ($schoolLogoMedia = $this->entityTypeManager->getStorage('media')->load($schoolLogoId)) {
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
        else {
          $this->clearFooterConfig('content.school_logo');
        }
      }
      else {
        $this->clearFooterConfig('content.school_logo');
      }
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
