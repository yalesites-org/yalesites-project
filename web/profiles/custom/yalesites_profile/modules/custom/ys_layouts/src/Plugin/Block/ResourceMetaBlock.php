<?php

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Controller\TitleResolver;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Block for resource meta data that appears above resources.
 *
 * @Block(
 *   id = "resource_meta_block",
 *   admin_label = @Translation("Resource Meta Block"),
 *   category = @Translation("YaleSites Layouts"),
 * )
 */
class ResourceMetaBlock extends BlockBase implements ContainerFactoryPluginInterface {

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

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Constructs a new ResourceMetaBlock object.
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
   * @param \Drupal\Core\Datetime\DateFormatter $date_formatter
   *   The date formatter.
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RouteMatchInterface $route_match,
    TitleResolver $title_resolver,
    RequestStack $request_stack,
    DateFormatter $date_formatter,
    EntityTypeManager $entity_type_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->routeMatch = $route_match;
    $this->titleResolver = $title_resolver;
    $this->requestStack = $request_stack;
    $this->dateFormatter = $date_formatter;
    $this->entityTypeManager = $entity_type_manager;
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
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->requestStack->getCurrentRequest()->attributes->get('node');
    if (!($node instanceof NodeInterface) || $node->bundle() !== 'resource') {
      return [];
    }

    $title = NULL;
    $categoryName = NULL;
    $publishDate = NULL;
    $metadata = [];
    $mediaBundle = NULL;
    $fileUrl = NULL;
    $mediaLabel = NULL;
    $mediaId = NULL;
    $documentImage = NULL;

    $route = $this->routeMatch->getRouteObject();

    if ($route) {
      // Get Resource fields.
      $title = $node->getTitle();
      $fieldPublishDate = $node?->field_publish_date;
      $fieldDateFormat = $node?->field_date_format?->first()?->getString();
      $fieldCategory = $node?->field_category?->first()?->getValue();
      $fieldMedia = $node?->field_media?->first()?->getValue();

      $date_formats = [
        'date' => 'F j, Y',
        'month_year' => 'F Y',
        'year_only' => 'Y',
      ];

      // Set PUBLISH DATE variables.
      if ($fieldPublishDate->getValue() and $fieldDateFormat) {
        $publishDateValue = strtotime($fieldPublishDate->first()->getValue()['value']);
        $publishDate = $this->dateFormatter->format($publishDateValue, '', $date_formats[$fieldDateFormat]);
      }

      // Get CATEGORY term.
      if ($fieldCategory) {
        /** @var \Drupal\taxonomy\Entity\Term $categoryTerm */
        $categoryTerm = $this->entityTypeManager->getStorage('taxonomy_term')->load($fieldCategory['target_id']);
        $categoryName = $categoryTerm->getName();
      }

      // Select specific taxonomy fields to show in the METADATA.
      $selected_term_fields = [
        'field_custom_vocab',
        'field_audience',
      ];

      foreach ($selected_term_fields as $field_name) {
        if ($node->hasField($field_name)) {
          $field = $node->get($field_name);
          $field_label = $field->getFieldDefinition()->getLabel();
          $terms = [];

          foreach ($field->referencedEntities() as $term) {
            $terms[] = [
              '#type' => 'link',
              '#title' => $term->label(),
              '#url' => $term->toUrl(),
            ];
          }

          // Only set metadata if there are terms.
          if ($terms) {
            $metadata[$field_name] = [
              'label' => $field_label,
              'items' => $terms,
            ];
          }
        }
      }

      // Set MEDIA.
      if ($fieldMedia) {
        /** @var \Drupal\media\Entity\Media $media */
        $media = $this->entityTypeManager->getStorage('media')->load($fieldMedia['target_id']);
        $mediaBundle = $media->bundle();
        $mediaLabel = $media->label();
        $mediaId = $media->id();

        if ($mediaBundle === 'document') {
          $fieldMediaFile = $media->field_media_file->first()->getValue();

          /** @var \Drupal\file\Entity\File $file */
          $file = $this->entityTypeManager->getStorage('file')->load($fieldMediaFile['target_id']);
          $fileUrl = Url::fromRoute('ys_layouts.resource_download', ['file_id' => $file->id()])->toString();

          $thumbnail = $media?->thumbnail;

          if ($thumbnail) {
            /** @var \Drupal\file\Entity\File $thumbnail_file */
            $referenced_entities = $thumbnail->referencedEntities();
            $thumbnail_file = reset($referenced_entities);

            if ($thumbnail_file) {
              $documentImage = [
                '#theme' => 'responsive_image',
                '#uri' => $thumbnail_file->getFileUri(),
                '#responsive_image_style_id' => 'resource_thumbnail',
                '#height' => $thumbnail?->height,
                '#width' => $thumbnail?->width,
                '#attributes' => [
                  'loading' => 'lazy',
                  'alt' => $media->label(),
                ],
              ];
            }
          }
        }
      }
    }

    return [
      '#theme' => 'ys_resource_meta_block',
      '#resource_meta__heading' => $title,
      '#resource_meta__category' => $categoryName,
      '#resource_meta__publish_date' => $publishDate,
      '#resource_meta__metadata' => $metadata,
      '#resource_meta__resource_type' => $mediaBundle,
      '#resource_meta__download_url' => $fileUrl,
      '#resource_meta__download_label' => $this->t('Download'),
      '#resource_meta__download_aria_label' => $this->t('Download') . ' ' . $mediaLabel,
      '#resource_meta__media_id' => $mediaId,
      '#resource_meta__document_image' => $documentImage,
    ];
  }

}
