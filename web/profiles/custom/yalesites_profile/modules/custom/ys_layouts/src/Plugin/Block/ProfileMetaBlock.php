<?php

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
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
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RouteMatchInterface $route_match,
    RequestStack $request_stack,
    EntityTypeManagerInterface $entity_type_manager,
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
    $plugin_definition,
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

    $title = NULL;
    $position = NULL;
    $subtitle = NULL;
    $department = NULL;
    $mediaId = NULL;

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
      $title = $node->getTitle();
      $position = $node->get('field_position')->getValue()[0]['value'] ?? NULL;
      $subtitle = $node->get('field_subtitle')->getValue()[0]['value'] ?? NULL;
      $department = $node->get('field_department')->getValue()[0]['value'] ?? NULL;
      $mediaId = $node->get('field_media')->getValue()[0]['target_id'] ?? NULL;
    }

    return [
      '#theme' => 'ys_profile_meta_block',
      '#profile_meta__heading' => $title,
      '#profile_meta__title_line' => $position,
      '#profile_meta__subtitle_line' => $subtitle,
      '#profile_meta__department' => $department,
      '#media_id' => $mediaId,
      '#profile_meta__image_orientation' => $this->configuration['image_orientation'] ?? 'landscape',
      '#profile_meta__image_style' => $this->configuration['image_style'] ?? 'inline',
      '#profile_meta__image_alignment' => $this->configuration['image_alignment'] ?? 'left',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) : array {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    // The form field is defined and added to the form array here.
    $form['image_orientation'] = [
      '#type' => 'select',
      '#title' => $this->t('Image orientation'),
      '#default_value' => $config['image_orientation'] ?? 'portrait',
      '#options' => [
        'landscape' => $this->t('Landscape'),
        'portrait' => $this->t('Portrait'),
      ],
    ];

    $form['image_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Image style'),
      '#default_value' => $config['image_style'] ?? 'inline',
      '#options' => [
        'inline' => $this->t('Inline'),
        'outdent' => $this->t('Outdent'),
      ],
    ];

    $form['image_alignment'] = [
      '#type' => 'select',
      '#title' => $this->t('Image alignment'),
      '#default_value' => $config['image_alignment'] ?? 'left',
      '#options' => [
        'left' => $this->t('Left'),
        'right' => $this->t('Right'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) : void {
    parent::blockSubmit($form, $form_state);
    $this->configuration['image_orientation'] = $form_state->getValue('image_orientation');
    $this->configuration['image_style'] = $form_state->getValue('image_style');
    $this->configuration['image_alignment'] = $form_state->getValue('image_alignment');
  }

}
