<?php

namespace Drupal\ys_templated_content\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the content menu.
 *
 * Overrides the node/add type look and feel with the templated versions.
 */
class CreateContentMenuController extends ControllerBase {
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  protected $renderer;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(ContainerInterface $container) {
    $this->renderer = $container->get('renderer');
    $this->entityTypeManager = $container->get('entity_type.manager');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container);
  }

  /**
   * Creates the menu page for users to drill down to the menu items.
   */
  public function menusPage() {
    $contentTypes = $this->entityTypeManager->getStorage('node_type')->loadMultiple();

    // Build the list of content types.
    $links = [];
    foreach ($contentTypes as $contentType) {
      $links[] = [
        'label' => $contentType->label(),
        'add_link' => Link::createFromRoute('Add ' . $contentType->label(), 'ys_templated_content.selection', ['content_type' => $contentType->id()]),
        'description' => $contentType->getDescription(),
      ];
    }

    // Set up the content.
    $content = [
      '#theme' => 'entity_add_list',
      '#title' => 'Add content',
      '#description' => $this->t('Add content to your site'),
      '#bundles' => $links,
    ];

    return $content;
  }

}
