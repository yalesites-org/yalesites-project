<?php

namespace Drupal\ys_toolbar;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Menu\LocalTaskManagerInterface;
use Drupal\Core\Routing\RedirectDestination;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Tools for customizing the Drupal toolbar to engance the authoring experience.
 */
class ToolbarItemsService {

  const NODE_VIEW_ROUTE = 'entity.node.canonical';
  const NODE_EDIT_ROUTE = 'entity.node.edit_form';
  const NODE_LAYOUT_ROUTE = 'layout_builder.overrides.node.view';
  const TERM_EDIT_ROUTE = 'entity.taxonomy_term.edit_form';
  const TERM_LAYOUT_ROUTE = 'layout_builder.overrides.taxonomy_term.view';

  /**
   * The access manager.
   *
   * @var \Drupal\Core\Access\AccessManagerInterface
   */
  protected $accessManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The node related to the current route.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $currentNode;

  /**
   * The taxonomy term related to the current route.
   *
   * @var \Drupal\taxonomy\TermInterface|null
   */
  protected $currentTerm;

  /**
   * Local task manager.
   *
   * @var Drupal\Core\Menu\LocalTaskManagerInterface
   */
  private $localTaskManager;

  /**
   * Local route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  private $routeMatch;

  /**
   * Redirect destination service.
   *
   * @var \Drupal\Core\Routing\RedirectDestination
   */
  private $redirectDestination;

  /**
   * Toolbar menu items.
   *
   * @var array
   */
  private $toolbarItems = [];

  /**
   * Create a new service.
   *
   * @param \Drupal\Core\Access\AccessManagerInterface $access_manager
   *   The access manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The route match.
   * @param \Drupal\Core\Routing\RedirectDestination $redirect_destination
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\Core\Menu\LocalTaskManagerInterface $local_task_manager
   *   A local task manager instance.
   */
  public function __construct(
    AccessManagerInterface $access_manager,
    RouteMatchInterface $routeMatch,
    RedirectDestination $redirect_destination,
    AccountInterface $account,
    LocalTaskManagerInterface $local_task_manager,
  ) {
    $this->accessManager = $access_manager;
    $this->routeMatch = $routeMatch;
    $this->redirectDestination = $redirect_destination;
    $this->account = $account;
    $this->localTaskManager = $local_task_manager;
    $this->currentNode = $this->routeMatch->getParameter('node');
    $this->currentTerm = $this->routeMatch->getParameter('taxonomy_term');
  }

  /**
   * Build a render array of items to add to the toolbar.
   *
   * @return array
   *   A renderable array of toolbar items.
   */
  public function addItems(): array {
    // Taxonomy term pages get a reduced set of buttons: Manage Settings and
    // Edit Layout.
    if ($this->isCurrentRouteTerm()) {
      return $this->addTermItems();
    }

    // Exit early if the current route is not a node or a term.
    if (!$this->isCurrentRouteNode()) {
      return [];
    }

    // Add a dedicated 'edit' link to the toolbar on select routes. This link
    // appears in the contextual menu but is so useful that we want to surface
    // this in a more prominent location.
    if ($this->showEditButton()) {
      $this->toolbarItems['toolbar_edit_link'] = $this->buildButton(
        'entity.node.edit_form',
        'Manage Settings'
      );
    }

    // Add a dedicated 'layout' link to the toolbar. Access controls are used to
    // ensure this link only appears on entities with layout overrides enabled.
    $this->toolbarItems['toolbar_layout_link'] = $this->buildButton(
      'layout_builder.overrides.node.view',
      'Edit Layout And Content'
    );

    // Add a publish button to the toolbar when viewing an unpublished node.
    if ($this->showPublishButton()) {
      $this->toolbarItems['toolbar_publish_link'] = $this->buildButton(
        'entity.node.publish',
        'Publish'
      );
    }

    // Add an unpublish button to the toolbar when viewing a published node.
    if ($this->showUnpublishButton()) {
      $this->toolbarItems['toolbar_unpublish_link'] = $this->buildButton(
        'entity.node.publish',
        'Unpublish'
      );
    }

    $this->toolbarItems['toolbar_theme_settings_link'] = $this->buildOffCanvasButton(
        'ys_themes.theme_settings',
        'Theme Settings'
      );

    return $this->toolbarItems;
  }

  /**
   * Build the toolbar items for a taxonomy term route.
   *
   * @return array
   *   A renderable array of toolbar items.
   */
  protected function addTermItems(): array {
    // Skip the edit button on the edit form itself. The destination returns
    // the editor to the term page after saving.
    if ($this->routeMatch->getRouteName() !== self::TERM_EDIT_ROUTE) {
      $this->toolbarItems['toolbar_edit_link'] = $this->buildButton(
        self::TERM_EDIT_ROUTE,
        'Manage Settings',
        ['query' => $this->redirectDestination->getAsArray()]
      );
    }

    // Access checks keep this link hidden unless the term has Layout Builder
    // enabled. The route does not exist otherwise, which denies access.
    $this->toolbarItems['toolbar_layout_link'] = $this->buildButton(
      self::TERM_LAYOUT_ROUTE,
      'Edit Layout And Content'
    );

    $this->toolbarItems['toolbar_theme_settings_link'] = $this->buildOffCanvasButton(
      'ys_themes.theme_settings',
      'Theme Settings'
    );

    return $this->toolbarItems;
  }

  /**
   * Check if the current route is a taxonomy term.
   *
   * @return bool
   *   True if the current route is a taxonomy term.
   */
  public function isCurrentRouteTerm(): bool {
    return $this->currentTerm instanceof TermInterface;
  }

  /**
   * Check if the current route is a node.
   *
   * @return bool
   *   True if the current route is a node.
   */
  public function isCurrentRouteNode(): bool {
    return $this->currentNode instanceof NodeInterface;
  }

  /**
   * Check if the current route is a canonical node route.
   *
   * @return bool
   *   True if the current route is a canonical node route.
   */
  protected function isViewRoute(): bool {
    return self::NODE_VIEW_ROUTE == $this->routeMatch->getRouteName();
  }

  /**
   * Check if the current route is a node-edit route.
   *
   * @return bool
   *   True if the current route is a node-edit route.
   */
  protected function isEditRoute(): bool {
    return self::NODE_EDIT_ROUTE == $this->routeMatch->getRouteName();
  }

  /**
   * Chech if the dedicated 'edit' button should appear on the current route.
   *
   * @return bool
   *   True if the edit button should appear on the current route.
   */
  protected function showEditButton(): bool {
    // Show the edit button on all node routes except the edit form.
    return $this->isCurrentRouteNode() && !$this->isEditRoute();
  }

  /**
   * Chech if a dedicated 'publish' button should appear on the current route.
   *
   * @return bool
   *   True if the button should appear on the toolbar.
   */
  protected function showPublishButton(): bool {
    // Show the button if viewing an unpublished node.
    return !$this->currentNode->isPublished() && $this->isViewRoute();
  }

  /**
   * Chech if a dedicated 'unpublish' button should appear on the current route.
   *
   * @return bool
   *   True if the button should appear on the toolbar.
   */
  protected function showUnpublishButton(): bool {
    // Show the button if viewing a published node.
    return $this->currentNode->isPublished() && $this->isViewRoute();
  }

  /**
   * Get node parameters for creating a route.
   *
   * A utility function for gettingn the current node ID in the route-parameters
   * format used by routinng services.
   *
   * @return array
   *   Array of values to substitute into the route path pattern.
   */
  protected function getNodeRouteParams(): array {
    if ($this->isCurrentRouteTerm()) {
      return ['taxonomy_term' => $this->currentTerm->id()];
    }
    return [
      'node' => $this->currentNode->id(),
    ];
  }

  /**
   * Build an item for the toolbar.
   *
   * @param string $route
   *   The route for the toolbar item destination.
   * @param string $label
   *   The text label for the toolbar item.
   * @param array $options
   *   Optional URL options, such as a destination query.
   *
   * @return array
   *   A rennder array for a toolbar item.
   */
  protected function buildButton(string $route, string $label, array $options = []): array {
    return [
      '#type' => 'toolbar_item',
      'tab' => [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute(
          $route,
          $this->getNodeRouteParams(),
          $options
        ),
        '#access' => $this->accessManager->checkNamedRoute(
          $route,
          $this->getNodeRouteParams(),
          $this->account
        ),
        '#attributes' => [
          'class' => [
            'toolbar-icon',
            'toolbar-icon-edit',
            strtolower(str_replace(' ', '-', $label)),
          ],
        ],
        '#cache' => [
          'contexts' => [
            'url.path',
          ],
        ],
      ],
    ];
  }

  /**
   * Build an off canvas item for the toolbar.
   *
   * @param string $route
   *   The route for the toolbar item destination.
   * @param string $label
   *   The text label for the toolbar item.
   *
   * @return array
   *   A rennder array for a toolbar item.
   */
  protected function buildOffCanvasButton(string $route, string $label): array {
    return [
      '#type' => 'toolbar_item',
      'tab' => [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute(
          $route,
          $this->redirectDestination->getAsArray(),
        ),
        '#access' => $this->account->hasPermission('yalesites manage settings'),
        '#attributes' => [
          'class' => [
            'use-ajax',
            'toolbar-icon',
            'toolbar-icon-edit',
          ],
          'data-dialog-type' => 'dialog',
          'data-dialog-renderer' => 'off_canvas',
          'data-dialog-options' => Json::encode(['width' => 400]),
        ],
        '#attached' => [
          'library' => [
            'core/drupal.dialog.ajax',
            'ys_toolbar/ys_toolbar',
          ],
        ],
        '#cache' => [
          'contexts' => [
            'url.path',
          ],
        ],
      ],
    ];
  }

}
