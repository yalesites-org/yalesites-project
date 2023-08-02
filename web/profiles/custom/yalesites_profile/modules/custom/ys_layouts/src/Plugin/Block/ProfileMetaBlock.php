<?php

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

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
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new ProfileMetaBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match, RequestStack $request_stack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->routeMatch = $route_match;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    $title = NULL;
    $position = NULL;
    $subtitle = NULL;
    $department = NULL;
    $mediaId = NULL;

    $request = $this->requestStack->getCurrentRequest();
    $route = $this->routeMatch->getRouteObject();

    if ($route) {
      $node = $request->attributes->get('node');
      // Profile fields.
      $title = $node->getTitle();
      $position = ($node->field_position->first()) ? $node->field_position->first()->getValue()['value'] : NULL;
      $subtitle = ($node->field_subtitle->first()) ? $node->field_subtitle->first()->getValue()['value'] : NULL;
      $department = ($node->field_department->first()) ? $node->field_department->first()->getValue()['value'] : NULL;
      $mediaId = ($node->field_media->first()) ? $node->field_media->first()->getValue()['target_id'] : NULL;
    }

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
