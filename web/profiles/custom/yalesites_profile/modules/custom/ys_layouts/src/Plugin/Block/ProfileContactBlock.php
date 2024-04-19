<?php

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RouteMatchInterface $route_match,
    RequestStack $request_stack,
    EntityTypeManagerInterface $entity_type_manager
    ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->routeMatch = $route_match;
    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
    ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
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
    $node = $request->attributes->get('node');
    // When removing the contact block when one already exists,
    // it no longer has access to the node object. Therefore, we must load it
    // manually via the ajaxified path.
    if (!$node) {
      $layoutBuilderPath = $request->getPathInfo();
      preg_match('/(node\.+(\d+))/', $layoutBuilderPath, $matches);
      if (!empty($matches)) {
        $nodeStorage = $this->entityTypeManager->getStorage('node');
        $node = $nodeStorage->load($matches[2]);
      }
    }

    if ($route && $node) {
      // Profile fields.
      $email = $this->getValueFor($node, 'field_email');
      $phone = $this->getValueFor($node, 'field_telephone');
      $address = $this->getValueFor($node, 'field_address');
    }

    return [
      '#theme' => 'ys_profile_contact_block',
      '#email' => $email,
      '#phone' => $phone,
      '#address' => $address,
    ];
  }

  /**
   * Get the value for a specific field.
   *
   * @param object $node
   *   The node object.
   * @param string $name
   *   The field name.
   *
   * @return mixed
   *   The field value.
   */
  protected function getValueFor($node, $name) {
    try {
      $fieldObject = $node->get($name);
    }
    catch (\Exception $e) {
      return NULL;
    }

    $valueObject = $fieldObject->getValue();
    if ($valueObject) {
      $firstElement = $valueObject[0];
      if ($firstElement) {
        return $firstElement['value'];
      }
    }

    return NULL;
  }

}
