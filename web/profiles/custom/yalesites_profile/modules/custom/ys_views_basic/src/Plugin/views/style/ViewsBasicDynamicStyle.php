<?php

namespace Drupal\ys_views_basic\Plugin\views\style;

use Drupal\Core\Entity\EntityDisplayRepository;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\views\Plugin\views\style\StylePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unformatted style plugin to render rows with dynamic view mode.
 *
 * Row are rendered one after another with no decorations.
 *
 * @ingroup views_style_plugins
 *
 * @ViewsStyle(
 *   id = "ys_views_basic_dynamic_style",
 *   title = @Translation("Views Basic Dynamic Style"),
 *   help = @Translation("Displays rows one after another."),
 *   theme = "views_view_unformatted",
 *   display_types = {"normal"}
 * )
 */
class ViewsBasicDynamicStyle extends StylePluginBase implements ContainerFactoryPluginInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The entity display repository to fetch display modes.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepository
   */
  protected $entityDisplay;

  /**
   * {@inheritdoc}
   */
  protected $usesRowPlugin = TRUE;

  /**
   * Does the style plugin support custom css class for the rows.
   *
   * @var bool
   */
  protected $usesRowClass = TRUE;

  /**
   * Constructs a ViewsBasicDynamicStyle object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityDisplayRepository $entity_display
   *   The entity display repository.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The URL Generator class.
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, EntityDisplayRepository $entity_display, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityDisplay = $entity_display;
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
      $container->get('entity_display.repository'),
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function preRender($result) {
    if (!empty($this->view->rowPlugin)) {

      // Gets passed view mode from ViewsBasicDefaultFormatter and sets per row.
      if (isset($this->view->args[4])) {
        $viewMode = $this->view->args[4];
        $validViewModes = $this->entityDisplay->getViewModeOptions('node');
        if (array_key_exists($viewMode, $validViewModes)) {
          $this->view->rowPlugin->options['view_mode'] = $viewMode;
        }
        else {
          $this->view->rowPlugin->options['view_mode'] = 'teaser';
        }
      }

      $this->view->rowPlugin->preRender($result);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $rows = [];

    foreach ($this->view->result as $row) {
      $rows[] = $this->view->rowPlugin->render($row);
    }

    // Map the view mode in Drupal to the type attribute for the component.
    $type = $this->view->rowPlugin->options['view_mode'] == 'list_item' ? 'list' : 'grid';

    // Get node type to pass to template to determine width.
    $entity = $this->routeMatch->getParameter('node');
    $parentNode = ($entity) ? $entity->getType() : NULL;

    return [
      '#theme' => 'views_basic_rows',
      '#rows' => $rows,
      '#card_collection_type' => $type,
      '#parentNode' => $parentNode,
    ];
  }

}
