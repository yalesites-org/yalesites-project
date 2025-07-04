<?php

namespace Drupal\ys_taxonomy_manager\Controller;

use Drupal\taxonomy_manager\Controller\MainController as TaxonomyManagerMainController;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ys_core\TaxonomyVocabularyManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Custom controller for taxonomy manager routes.
 */
class YsTaxonomyManagerMainController extends TaxonomyManagerMainController {

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
   * The taxonomy vocabulary manager.
   *
   * @var \Drupal\ys_core\TaxonomyVocabularyManager
   */
  protected $taxonomyVocabularyManager;

  /**
   * Constructs a new MainController.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    TaxonomyVocabularyManager $taxonomy_vocabulary_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->taxonomyVocabularyManager = $taxonomy_vocabulary_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('ys_core.taxonomy_vocabulary_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function listVocabularies() {
    $build = [];

    // Define YaleSites vocabularies.
    $yalesites_vocabs = $this->taxonomyVocabularyManager->getYaleSitesVocabularyIds();

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
      $content_types = $this->taxonomyVocabularyManager->getAssociatedContentTypes($vocabulary);
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

}
