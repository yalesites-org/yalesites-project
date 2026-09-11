<?php

namespace Drupal\ys_views_basic\Plugin\views\style;

use Drupal\Core\Entity\EntityDisplayRepository;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\views\Plugin\views\style\StylePluginBase;
use Drupal\ys_views_basic\ViewsBasicManager;
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
      $rendered_row = $this->view->rowPlugin->render($row);
      // If row has node__field_event_date_delta,
      // inject it into the render array.
      if (isset($row->node__field_event_date_delta)) {
        $rendered_row['#delta'] = $row->node__field_event_date_delta;
      }
      $rows[] = $rendered_row;
    }

    // Map the view mode in Drupal to the type attribute for the component.
    $viewModesMap = [
      'card' => 'grid',
      'portrait_grid' => 'grid',
      'list_item' => 'list',
      'condensed' => 'condensed',
      'directory' => 'profile-directory',
    ];

    $type = $viewModesMap[$this->view->rowPlugin->options['view_mode']];

    $contentType = $this->view->args[0];
    $cardCollectionModifiers = [];
    if ($contentType === 'resource' && $this->view->rowPlugin->options['view_mode'] === 'portrait_grid') {
      $cardCollectionModifiers[] = 'resource-portrait';
    }

    // Get node type to pass to template to determine width.
    $entity = $this->routeMatch->getParameter('node');
    $parentNode = isset($entity) ? $entity->getType() : NULL;

    return [
      '#theme' => 'views_basic_rows',
      '#rows' => $rows,
      '#card_collection_type' => $type,
      '#card_collection_modifiers' => $cardCollectionModifiers,
      '#parentNode' => $parentNode,
      '#contentType' => $contentType,
      '#card_size' => $this->cardSize(),
    ];
  }

  /**
   * Returns the card size for this listing (#1648).
   *
   * The dial travels in the shared field_display_options argument because it
   * describes the collection as a whole rather than an individual result row.
   * Anything unexpected — another view using this style plugin, a view
   * rendered outside setupView(), or a stored value the SCSS has no rule for
   * — falls back to the large (3-up) grid every listing had before the dial
   * existed. ViewsBasicManager::normalizeCardSize() also accepts the numeric
   * cards_per_row value the dial briefly used, so an argument set built before
   * the rename still resolves to the size it was rendering.
   *
   * The view-id guard matters: this style plugin also serves the
   * content_resources view, which builds its own, shorter argument list in
   * ViewsContentResourcesManager — index 8 is pin_settings there, not field
   * display options — so reading the index blindly would decode a different
   * argument entirely.
   *
   * @return string
   *   A member of ViewsBasicManager::CARD_SIZE_OPTIONS.
   */
  protected function cardSize(): string {
    if (!in_array($this->view->id(), ViewsBasicManager::SCAFFOLD_VIEWS, TRUE)) {
      return ViewsBasicManager::CARD_SIZE_DEFAULT;
    }

    $field_display_options = json_decode($this->view->args[ViewsBasicManager::viewArgumentIndex('field_display_options')] ?? '', TRUE);
    // Anything that is not the object setupView() writes — a stale argument
    // set, or a JSON scalar — falls back. Tested with is_array() rather than
    // a truthiness check, which would leave a decoded scalar to be indexed
    // below and emit an "array offset on value of type int" warning.
    if (!is_array($field_display_options)) {
      $field_display_options = [];
    }

    return ViewsBasicManager::normalizeCardSize(
      $field_display_options['card_size'] ?? $field_display_options['cards_per_row'] ?? NULL
    );
  }

}
