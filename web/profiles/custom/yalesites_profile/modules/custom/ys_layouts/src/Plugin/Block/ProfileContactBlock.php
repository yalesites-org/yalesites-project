<?php

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

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
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new ProfileContactBlock instance.
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

    $email = NULL;
    $phone = NULL;
    $address = NULL;

    $request = $this->requestStack->getCurrentRequest();
    $route = $this->routeMatch->getRouteObject();

    if ($route) {
      $node = $request->attributes->get('node');
      // Profile fields.
      $email = ($node->field_email->first()) ? $node->field_email->first()->getValue()['value'] : NULL;
      $phone = ($node->field_telephone->first()) ? $node->field_telephone->first()->getValue()['value'] : NULL;
      $address = ($node->field_address->first()) ? $node->field_address->first()->getValue()['value'] : NULL;
    }

    return [
      '#theme' => 'ys_profile_contact_block',
      '#email' => $email,
      '#phone' => $phone,
      '#address' => $address,
    ];
  }

}
