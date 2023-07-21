<?php

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;

/**
 * Block for profile contact data that appears in the sidebar of profiles.
 *
 * @Block(
 *   id = "profile_contact_block",
 *   admin_label = @Translation("Profile Contact Block"),
 *   category = @Translation("YaleSites Layouts"),
 * )
 */
class ProfileContactBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
    $email = ($node->field_email->first()) ? $node->field_email->first()->getValue()['value'] : NULL;
    $phone = ($node->field_telephone->first()) ? $node->field_telephone->first()->getValue()['value'] : NULL;
    $address = ($node->field_address->first()) ? $node->field_address->first()->getValue() : NULL;

    return [
      '#theme' => 'ys_profile_contact_block',
      '#email' => $email,
      '#phone' => $phone,
      '#address' => $address,
    ];
  }

}
