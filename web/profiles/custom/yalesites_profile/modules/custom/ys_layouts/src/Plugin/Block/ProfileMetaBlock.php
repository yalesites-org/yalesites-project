<?php

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Block for profile meta data that appears above profiles.
 *
 * @Block(
 *   id = "profile_meta_block",
 *   admin_label = @Translation("Profile Meta Block"),
 *   category = @Translation("YaleSites Layouts"),
 * )
 */
class ProfileMetaBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new BookNavigationBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->routeMatch->getParameter('node');
    if (!($node instanceof NodeInterface)) {
      return [];
    }

    // Profile fields.
    $title = $node->getTitle();
    $position = ($node->field_position->first()) ? $node->field_position->first()->getValue()['value'] : NULL;
    $subtitle = ($node->field_subtitle->first()) ? $node->field_subtitle->first()->getValue()['value'] : NULL;
    $department = ($node->field_department->first()) ? $node->field_department->first()->getValue()['value'] : NULL;
    $mediaId = ($node->field_media->first()) ? $node->field_media->first()->getValue()['target_id'] : NULL;

    return [
      '#theme' => 'ys_profile_meta_block',
      '#profile_meta__heading' => $title,
      '#profile_meta__title_line' => $position,
      '#profile_meta__subtitle_line' => $subtitle,
      '#profile_meta__department' => $department,
      '#media_id' => $mediaId,
    ];
  }

}
