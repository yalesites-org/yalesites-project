<?php

namespace Drupal\ys_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Autocomplete controller to find paths for nodes.
 */
class AutocompletePathController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The node query service.
   *
   * @var \Drupal\Core\Entity\Query\QueryInterface
   */
  protected $nodeQuery;

  /**
   * AutocompletePathController constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
    $this->nodeQuery = $entityTypeManager->getStorage('node')->getQuery();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Handle the autocomplete request to return the path to a node.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function handleAutocomplete(Request $request) {
    $input = strtolower($request->query->get('q'));
    $matches = [];

    // Don't search until we get to 3 characters.
    if (strlen($input) < 3) {
      return new JsonResponse($matches);
    }

    // For users who know the node they want, it will pull the node they
    // targeted as a validation to the user.
    if (preg_match('/\/node\/(\d+)$/', $input, $matches)) {
      $node = $this->entityTypeManager()->getStorage('node')->load($matches[1]);
      if ($node) {
        $matches = [
          [
            'value' => '/node/' . $node->id(),
            'label' => $node->getTitle(),
          ],
        ];
      }
    }
    // Otherwise they're trying to find a node, so let's search for it.
    else {
      $query = $this->nodeQuery;
      $query->condition('title', '%' . $input . '%', 'LIKE');
      $query->accessCheck(TRUE);
      $nids = $query->execute();

      if (!empty($nids)) {
        $nodes = $this
          ->entityTypeManager()
          ->getStorage('node')
          ->loadMultiple($nids);
        foreach ($nodes as $node) {
          $matches[] = [
            'value' => '/node/' . $node->id(),
            'label' => $node->getTitle(),
          ];
        }
      }
    }

    return new JsonResponse($matches);
  }

}
