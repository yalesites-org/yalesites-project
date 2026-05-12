<?php

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\TitleResolver;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\ys_layouts\Service\ResourceAuthorBuilder;
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
   * The resource author list builder.
   *
   * @var \Drupal\ys_layouts\Service\ResourceAuthorBuilder
   */
  protected $resourceAuthorBuilder;

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
   * @param \Drupal\ys_layouts\Service\ResourceAuthorBuilder $resource_author_builder
   *   The resource author list builder.
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
    ResourceAuthorBuilder $resource_author_builder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->routeMatch = $route_match;
    $this->titleResolver = $title_resolver;
    $this->requestStack = $request_stack;
    $this->dateFormatter = $date_formatter;
    $this->entityTypeManager = $entity_type_manager;
    $this->resourceAuthorBuilder = $resource_author_builder;
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
      $container->get('ys_layouts.resource_author_builder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->getCurrentNode();
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
    $description = NULL;
    $citation = NULL;
    $abstract = NULL;
    $journalPublicationName = NULL;
    $journalPublicationIssue = NULL;
    $authors = [];
    $authorCacheTags = [];
    $externalSource = [];

    $route = $this->routeMatch->getRouteObject();

    if ($route) {
      // Get Resource fields.
      $title = $node->getTitle();
      $fieldPublishDate = $node?->field_publish_date;
      $fieldDateFormat = $node?->field_date_format?->first()?->getString();
      $fieldCategory = $node?->field_category?->first()?->getValue();
      $fieldMedia = $node?->field_media?->first()?->getValue();
      $fieldDescription = $node?->field_content_description?->first()?->getValue();

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

      // Set DESCRIPTION variable.
      if ($fieldDescription) {
        // Process the text through the text format filters.
        $description = check_markup(
          $fieldDescription['value'],
          $fieldDescription['format']
        );
      }

      // Get CATEGORY term.
      if ($fieldCategory) {
        /** @var \Drupal\taxonomy\Entity\Term $categoryTerm */
        $categoryTerm = $this->entityTypeManager->getStorage('taxonomy_term')->load($fieldCategory['target_id']);
        if ($categoryTerm) {
          $categoryName = $categoryTerm->getName();
        }
      }

      // Select specific taxonomy fields to show in the METADATA grid.
      // field_authors is handled separately below as first-class data so the
      // template can render it as a full-width cell with comma-joined links.
      $selected_term_fields = [
        'field_discipline',
        'field_audience',
        'field_areas_of_study',
        'field_academic_years',
        'field_geographic_areas',
        'field_tags',
        'field_custom_vocab',
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

      // Handle DCN field (cf_dcn) - custom field type.
      if ($node->hasField('field_cf_dcn') && !$node->get('field_cf_dcn')->isEmpty()) {
        $field = $node->get('field_cf_dcn');
        $field_label = $field->getFieldDefinition()->getLabel();
        $dcn_items = [];

        foreach ($field as $item) {
          $dcn_type = $item->getDcnType();
          $dcn_identifier = $item->dcn_identifier;

          if ($dcn_type && $dcn_identifier) {
            $dcn_items[] = [
              '#plain_text' => $dcn_type->getName() . ' ' . $dcn_identifier,
            ];
          }
        }

        if ($dcn_items) {
          $metadata['field_cf_dcn'] = [
            'label' => $field_label,
            'items' => $dcn_items,
          ];
        }
      }

      // Combined author list: affiliated profile refs + non-affiliated rows,
      // merged and sorted last-then-first. Same service feeds the view-row
      // templates via atomic_preprocess_node() so output stays consistent.
      $authors = $this->resourceAuthorBuilder->build($node, $authorCacheTags);

      // Handle External Source link field.
      if ($node->hasField('field_external_source') && !$node->get('field_external_source')->isEmpty()) {
        $linkItem = $node->get('field_external_source')->first();
        $url = UrlHelper::filterBadProtocol($linkItem->getUrl()->toString());
        if ($url) {
          $externalSource = [
            'url' => $url,
            'title' => $linkItem->get('title')->getValue() ?: $url,
          ];
        }
      }

      // Handle Citation field as a standalone text block.
      if ($node->hasField('field_citation') && !$node->get('field_citation')->isEmpty()) {
        $field_value = $node->get('field_citation')->first()->getValue();
        if (!empty($field_value['value'])) {
          $citation = check_markup($field_value['value'], $field_value['format'] ?? 'basic_html');
        }
      }

      // Handle Abstract field as a standalone text block.
      if ($node->hasField('field_abstract') && !$node->get('field_abstract')->isEmpty()) {
        $field_value = $node->get('field_abstract')->first()->getValue();
        if (!empty($field_value['value'])) {
          $abstract = check_markup($field_value['value'], $field_value['format'] ?? 'basic_html');
        }
      }

      // Handle Journal Publication Name.
      if ($node->hasField('field_journal_publication_name') && !$node->get('field_journal_publication_name')->isEmpty()) {
        $field_value = $node->get('field_journal_publication_name')->first()->getString();
        if (!empty($field_value)) {
          $journalPublicationName = $field_value;
        }
      }

      // Handle Journal Publication Issue.
      if ($node->hasField('field_journal_publication_issue') && !$node->get('field_journal_publication_issue')->isEmpty()) {
        $field_value = $node->get('field_journal_publication_issue')->first()->getString();
        if (!empty($field_value)) {
          $journalPublicationIssue = $field_value;
        }
      }

      // Set MEDIA.
      if ($fieldMedia) {
        /** @var \Drupal\media\Entity\Media $media */
        $media = $this->entityTypeManager->getStorage('media')->load($fieldMedia['target_id']);
        if ($media) {
          $mediaBundle = $media->bundle();
          $mediaLabel = $media->label();
          $mediaId = $media->id();

          if ($mediaBundle === 'document') {
            $fieldMediaFile = $media->field_media_file->first()->getValue();

            /** @var \Drupal\file\Entity\File $file */
            $file = $this->entityTypeManager->getStorage('file')->load($fieldMediaFile['target_id']);
            if ($file) {
              $fileUrl = Url::fromRoute('ys_layouts.resource_download', ['file_id' => $file->id()])->toString();
            }
          }

          // Build a thumbnail render array for non-video media bundles that
          // expose a usable thumbnail (document, image, etc.). Video bundles
          // are excluded because the embedded player itself is the visual,
          // and the auto-generated YouTube preview alongside it is redundant.
          $thumbnail = $media?->thumbnail;

          if ($mediaBundle !== 'video' && $thumbnail) {
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

    $build = [
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
      '#resource_meta__description' => $description,
      '#resource_meta__citation' => $citation,
      '#resource_meta__abstract' => $abstract,
      '#resource_meta__journal_publication_name' => $journalPublicationName,
      '#resource_meta__journal_publication_issue' => $journalPublicationIssue,
      '#resource_meta__authors' => $authors,
      '#resource_meta__external_source' => $externalSource,
    ];

    // Bubble up referenced-profile cache tags so author name/URL changes
    // invalidate the rendered block.
    if ($authorCacheTags) {
      $build['#cache']['tags'] = $authorCacheTags;
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $tags = parent::getCacheTags();
    $node = $this->getCurrentNode();
    if ($node instanceof NodeInterface) {
      $tags = Cache::mergeTags($tags, $node->getCacheTags());
    }
    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route', 'user.permissions']);
  }

  /**
   * Get the current node from either route match or Layout Builder context.
   */
  protected function getCurrentNode() {

    $request = $this->requestStack->getCurrentRequest();
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

    return $node ?? NULL;
  }

}
