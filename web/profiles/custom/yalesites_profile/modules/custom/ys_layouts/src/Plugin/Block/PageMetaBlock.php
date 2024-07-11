<?php

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Controller\TitleResolver;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Block for page meta data that appears above pages.
 *
 * @Block(
 *   id = "page_meta_block",
 *   admin_label = @Translation("Page Meta Block"),
 *   category = @Translation("YaleSites Layouts"),
 * )
 */
class PageMetaBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Controller\TitleResolver
   */
  protected $titleResolver;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  protected $entityTypeManager;

  /**
   * Constructs a new PageMetaBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Controller\TitleResolver $title_resolver
   *   The title resolver.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match, TitleResolver $title_resolver, RequestStack $request_stack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
    $this->titleResolver = $title_resolver;
    $this->requestStack = $request_stack;
    $this->entityTypeManager = \Drupal::service('entity_type.manager');
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
      $container->get('title_resolver'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    $route = $this->routeMatch->getRouteObject();
    $request = $this->requestStack->getCurrentRequest();
    $page_title = '';
    $node = NULL;

    // Get the page title.
    if ($route) {
      $page_title = $this->titleResolver->getTitle($request, $route);
      $node = $this->getEntityNode($page_title, $this->entityTypeManager, $request);
      if ($node instanceof NodeInterface) {
        $page_title = $node->getTitle();
      }
    }

    return [
      '#theme' => 'ys_page_meta_block',
      '#page_title' => $page_title,
      '#page_title_display' => $this->configuration['page_title_display'] ?? '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) : array {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    // The form field is defined and added to the form array here.
    $form['page_title_display'] = [
      '#type' => 'select',
      '#title' => $this->t('Title Display'),
      '#default_value' => $config['page_title_display'] ?? '',
      '#options' => [
        'visible' => $this->t('Display Title: Your page title is visible and used as the H1'),
        'visually-hidden' => $this->t('Visually Hidden: Hide your page title without impacting site accessibility'),
        'hidden' => $this->t('Hide Title: Should only be used when a page’s banner Block title is set to H1'),
      ],
      '#description' => $this->t('For more information about conditional banner titles, <a href="https://yalesites.yale.edu/posts/2023-10-17-community-spotlight-yale-united-way-campaign#tips" target="_blank">view our tips and tricks on this topic</a>.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) : void {
    parent::blockSubmit($form, $form_state);
    $this->configuration['page_title_display'] = $form_state->getValue('page_title_display');
  }

  /**
   * Checks if the given title is a UUID.
   *
   * @param string $title
   *   The title to check.
   *
   * @return bool
   *   TRUE if the title is a UUID, FALSE otherwise.
   */
  protected function isUuid(string $title): bool {
    if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $title)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   *
   */
  protected function isId(string $title): bool {
    if (is_numeric($title)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Gets the node for the given UUID.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The node entity if found, NULL otherwise.
   */
  protected function getNodeForUuid(string $uuid, $entityTypeManager) {
    if ($this->isId($uuid)) {
      $nodeStorage = $entityTypeManager->getStorage('node');
      /*$node = $nodeStorage->loadByProperties(['uuid' => $uuid]);*/
      $node = $nodeStorage->load($uuid);
      if ($node) {
        return $node;
        /*return reset($node);*/
      }
    }

    return NULL;
  }

  /**
   *
   */
  protected function getEntityNode($title, $entityTypeManager, $request) {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->routeMatch->getParameter('node');
    if (!($node instanceof NodeInterface)) {
      /** @var \Drupal\node\NodeInterface $node */
      $node = $request->attributes->get('node');
      if (!($node instanceof NodeInterface)) {
        $node = $this->getNodeForUuid($title, $entityTypeManager);
      }
    }

    return $node;
  }

}
