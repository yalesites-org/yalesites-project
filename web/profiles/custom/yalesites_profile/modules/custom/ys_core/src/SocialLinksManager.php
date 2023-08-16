<?php

namespace Drupal\ys_core;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for managing the social links associated with a YaleSite.
 */
class SocialLinksManager {

  /**
   * List of supported social sites.
   *
   * @todo This would make a great enum once PHP 8.1 is supported.
   */
  const SITES = [
    'facebook' => 'Facebook',
    'instagram' => 'Instagram',
    'twitter' => 'Twitter',
    'youtube' => 'YouTube',
    'weibo' => 'Weibo',
    'linkedin' => 'LinkedIn',
  ];

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $yaleSettings;

  /**
   * Builds an array of renderable links.
   */
  public function buildRenderableLinks() {
    $links = [];
    foreach (self::SITES as $id => $name) {
      if ($this->isSocialLinkSet($id)) {
        $links[] = [
          'url' => $this->getSocialLinkUrl($id),
          'name' => $name,
          'icon' => $id,
        ];
      }
    }
    return $links;
  }

  /**
   * Part of the DependencyInjection magic happening here.
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->yaleSettings = $configFactory->get('ys_core.social_links');
  }

  /**
   * Checks if the website has a url set for a given social network.
   *
   * @param string $id
   *   The id of a social network (as managed in $this->sites)
   *
   * @return bool
   *   TRUE if the configuration has a value, otherwise FALSE.
   */
  protected function isSocialLinkSet(string $id) {
    return (bool) $this->yaleSettings->get($id);
  }

  /**
   * Get the URL for a socail network associated with this website.
   *
   * @param string $id
   *   The id of a social network (as managed in $this->sites)
   *
   * @return string
   *   The URL for a given social network or NULL.
   */
  public function getSocialLinkUrl(string $id) {
    return $this->yaleSettings->get($id);
  }

}
