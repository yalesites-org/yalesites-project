<?php

namespace Drupal\ys_taxonomy_manager\Controller;

use Drupal\taxonomy_manager\Controller\MainController as TaxonomyManagerMainController;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Custom controller for taxonomy manager routes.
 */
class MainController extends TaxonomyManagerMainController {

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a MainController object.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(Request $request, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    parent::__construct($request);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function listVocabularies() {
    $build = [];

    // Define YaleSites vocabularies.
    $yalesites_vocabs = [
      'event_category',
      'profile_affiliation',
      'affiliation',
      'audience',
      'custom_vocab',
      'post_category',
      'page_category',
      'event_category',
      'tags',
    ];

    $yalesites_list = [];
    $localist_list = [];

    $vocabularies = $this->entityTypeManager()->getStorage('taxonomy_vocabulary')->loadMultiple();

    foreach ($vocabularies as $vocabulary) {
      if (!$this->entityTypeManager()->getAccessControlHandler('taxonomy_term')->createAccess($vocabulary->id())) {
        continue;
      }

      $vocabulary_form = Url::fromRoute('taxonomy_manager.admin_vocabulary',
        ['taxonomy_vocabulary' => $vocabulary->id()]);

      // Get content types using this vocabulary.
      $content_types = $this->getContentTypesUsingVocabulary($vocabulary->id());
      $content_types_markup = !empty($content_types) ? implode(', ', $content_types) : 'None';

      $row = [
        'data' => [
          Link::fromTextAndUrl($vocabulary->label(), $vocabulary_form),
          ['data' => ['#markup' => $content_types_markup]],
        ],
      ];

      // Sort into appropriate list.
      if (in_array($vocabulary->id(), $yalesites_vocabs)) {
        $yalesites_list[] = $row;
      }
      else {
        $localist_list[] = $row;
      }
    }

    $header = [
      [
        'data' => $this->t('Vocabulary'),
        'style' => 'width: 50%',
      ],
      [
        'data' => $this->t('Content Types'),
        'style' => 'width: 50%',
      ],
    ];

    if (!empty($yalesites_list)) {
      $build['yalesites'] = [
        '#type' => 'details',
        '#title' => $this->t('YaleSites Vocabularies'),
        '#open' => TRUE,
        'table' => [
          '#theme' => 'table',
          '#header' => $header,
          '#rows' => $yalesites_list,
          '#attributes' => [
            'style' => 'width: 100%;',
          ],
        ],
      ];
    }

    if (!empty($localist_list)) {
      $build['localist'] = [
        '#type' => 'details',
        '#title' => $this->t('Localist Vocabularies'),
        '#open' => TRUE,
        'table' => [
          '#theme' => 'table',
          '#header' => $header,
          '#rows' => $localist_list,
          '#attributes' => [
            'style' => 'width: 100%;',
          ],
        ],
      ];
    }

    if (empty($yalesites_list) && empty($localist_list)) {
      $build['empty'] = [
        '#markup' => $this->t('No Vocabularies available'),
      ];
    }

    return $build;
  }

  /**
   * Gets content types that use a specific vocabulary.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   *
   * @return array
   *   Array of content type labels that use this vocabulary.
   */
  protected function getContentTypesUsingVocabulary($vocabulary_id) {
    $content_types = [];
    $node_types = $this->entityTypeManager()->getStorage('node_type')->loadMultiple();

    foreach ($node_types as $node_type) {
      $fields = $this->entityFieldManager->getFieldDefinitions('node', $node_type->id());
      foreach ($fields as $field) {
        if ($field->getType() === 'entity_reference' &&
            $field->getSetting('target_type') === 'taxonomy_term') {
          // Get handler settings and check if this vocabulary is referenced.
          $handler_settings = $field->getSetting('handler_settings');
          $target_bundles = $handler_settings['target_bundles'] ?? [];

          if (!empty($target_bundles) && array_key_exists($vocabulary_id, $target_bundles)) {
            $content_types[] = $node_type->label();
            break;
          }
        }
      }
    }

    return $content_types;
  }

}
