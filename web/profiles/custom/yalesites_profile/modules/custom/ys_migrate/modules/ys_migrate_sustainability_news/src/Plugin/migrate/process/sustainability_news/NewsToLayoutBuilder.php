<?php

declare(strict_types=1);

namespace Drupal\ys_migrate_sustainability_news\Plugin\migrate\process\sustainability_news;

use Drupal\block_content\BlockContentInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Transforms D7 news fields into Layout Builder sections for D10 Post nodes.
 *
 * Creates a default "Title and Metadata" section plus a one-column content
 * section containing an image block and/or text block, built inline from
 * the current row's source data. No prior block_content migrations are needed.
 *
 * Usage in migration YAML:
 *
 * @code
 * process:
 *   layout_builder__layout:
 *     plugin: sn_news_to_layout_builder
 *     create_default_section: true
 *     default_section_type: post
 *     sections:
 *       - id: layout_onecol
 *         components:
 *           - type: image
 *             # 'sources' tries each in order and uses the first non-null value.
 *             sources:
 *               - '@_teaser_mid_image2'
 *               - '@_teaser_mid_image'
 *           - type: text
 *             source: 'body/0/value'
 * @endcode
 *
 * Source values prefixed with '@' are resolved from destination (processed)
 * properties set earlier in the pipeline; all others are source properties.
 */
#[MigrateProcess(id: 'sn_news_to_layout_builder')]
class NewsToLayoutBuilder extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The current row being processed.
   *
   * Held only for the duration of transform() and reset to NULL afterwards.
   *
   * @var \Drupal\migrate\Row|null
   */
  private ?Row $row = NULL;

  /**
   * Constructs the plugin instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly UuidInterface $uuid,
    private readonly LoggerChannelInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('uuid'),
      $container->get('logger.factory')->get('migrate'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\layout_builder\Section[]
   *   An array of Layout Builder sections for the node.
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): array {
    if (empty($this->configuration['sections'])) {
      throw new MigrateException('sn_news_to_layout_builder: you must specify at least one section.');
    }

    $this->row = $row;
    $layout_sections = [];

    // The default "Title and Metadata" section is normally added by Drupal
    // when a node layout is first saved via the UI. During migration it must
    // be created explicitly, otherwise the post header is never rendered.
    if ($this->configuration['create_default_section'] ?? TRUE) {
      $layout_sections[] = $this->createDefaultSection();
    }

    foreach ($this->configuration['sections'] as $section) {
      if (empty($section['components'])) {
        continue;
      }
      if ($layout_section = $this->mapSection($section)) {
        $layout_sections[] = $layout_section;
      }
    }

    $this->row = NULL;
    return $layout_sections;
  }

  /**
   * Creates the default "Title and Metadata" Layout Builder section.
   *
   * Mirrors the section Drupal auto-generates on first layout save for the
   * given node type. Without it the post meta block (date, author, tags)
   * will not appear on migrated nodes.
   *
   * @return \Drupal\layout_builder\Section
   *   The default section.
   *
   * @throws \Drupal\migrate\MigrateException
   *   If default_section_type is not configured.
   */
  public function createDefaultSection(): Section {
    $node_type = $this->configuration['default_section_type'] ?? NULL;

    if (!$node_type) {
      throw new MigrateException('sn_news_to_layout_builder: default_section_type is required when create_default_section is TRUE.');
    }

    // Order matches core.entity_view_display.node.post.default.yml.
    // Weight 0: content_moderation_control, weight 1: post_meta_block.
    return new Section(
      'layout_onecol',
      ['label' => 'Title and Metadata'],
      [
        new SectionComponent($this->uuid->generate(), 'content', [
          'id' => 'extra_field_block:node:' . $node_type . ':content_moderation_control',
          'label_display' => FALSE,
          'context_mapping' => [
            'entity' => 'layout_builder.entity',
          ],
        ]),
        new SectionComponent($this->uuid->generate(), 'content', [
          'id' => $node_type . '_meta_block',
          'label' => ucfirst($node_type) . ' Meta Block',
          'label_display' => '',
          'provider' => 'ys_layouts',
        ]),
      ],
    );
  }

  /**
   * Dispatches a section definition to the appropriate builder method.
   *
   * @param array $section
   *   Section configuration from the migration YAML.
   *
   * @return \Drupal\layout_builder\Section|null
   *   The built section, or NULL if the layout type is unsupported.
   */
  public function mapSection(array $section): ?Section {
    return match ($section['id']) {
      'layout_onecol' => $this->createOneColumnSection($section),
      default => NULL,
    };
  }

  /**
   * Creates a one-column Layout Builder section from a list of components.
   *
   * @param array $section
   *   Section configuration including a 'components' key.
   *
   * @return \Drupal\layout_builder\Section
   *   The one-column section (may have zero components if all blocks failed).
   */
  public function createOneColumnSection(array $section): Section {
    $section_components = [];

    foreach ($section['components'] as $component) {
      if ($block = $this->mapComponents($component)) {
        $section_components[] = new SectionComponent($this->uuid->generate(), 'content', [
          'id' => 'inline_block:' . $block->bundle(),
          'label' => $block->label(),
          'provider' => 'layout_builder',
          'label_display' => FALSE,
          'view_mode' => 'full',
          'block_revision_id' => $block->getRevisionId(),
          'context_mapping' => [],
        ]);
      }
    }

    return new Section('layout_onecol', components: $section_components);
  }

  /**
   * Resolves a component definition to a saved block_content entity.
   *
   * @param array $component
   *   Component definition with 'type' and either 'source' or 'sources'.
   *
   * @return \Drupal\block_content\BlockContentInterface|null
   *   The created block, or NULL if the source value was empty or invalid.
   */
  public function mapComponents(array $component): ?BlockContentInterface {
    $type = $component['type'];
    $source = $this->resolveSource($component);

    if ($source === NULL || $source === '') {
      return NULL;
    }

    return match ($type) {
      'image' => $this->createImageBlock($source),
      'text'  => $this->createTextBlock($source, $component),
      default => NULL,
    };
  }

  /**
   * Creates and saves an image block_content entity from a media entity ID.
   *
   * Loads the D10 media entity (created by ys_sn_media / ys_sn_media2) and
   * wraps it in a new inline image block. The media image alt text is used
   * as the optional caption.
   *
   * @param mixed $media_id
   *   The D10 media entity ID resolved from the migration lookup.
   *
   * @return \Drupal\block_content\BlockContentInterface|null
   *   The saved image block, or NULL if the media entity cannot be loaded.
   */
  private function createImageBlock(mixed $media_id): ?BlockContentInterface {
    $media = $this->entityTypeManager->getStorage('media')->load($media_id);

    if (!$media) {
      $this->logger->warning(
        'sn_news_to_layout_builder: could not load media entity @id — image block skipped.',
        ['@id' => $media_id],
      );
      return NULL;
    }

    // Use the image alt text as an optional caption wrapped in a paragraph.
    $caption = '';
    if ($alt = $media->get('field_media_image')->alt) {
      $caption = '<p>' . $alt . '</p>';
    }

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type'      => 'image',
      'info'      => 'Image Block',
      'reusable'  => FALSE,
      'field_media' => ['target_id' => $media->id()],
      'field_text'  => ['value' => $caption, 'format' => 'basic_html'],
    ]);
    $block->save();
    return $block;
  }

  /**
   * Creates and saves a text block_content entity from a body value string.
   *
   * @param string $value
   *   The HTML body text (D7 body/0/value).
   * @param array $component
   *   Component definition; supports an optional 'wrap_value' boolean key to
   *   wrap plain text in a <p> tag before saving.
   *
   * @return \Drupal\block_content\BlockContentInterface
   *   The saved text block.
   */
  private function createTextBlock(string $value, array $component): BlockContentInterface {
    $value = $this->sanitizeBodyHtml($value);

    if ($component['wrap_value'] ?? FALSE) {
      $value = '<p>' . $value . '</p>';
    }

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type'     => 'text',
      'info'     => 'Text Block',
      'reusable' => FALSE,
      'field_text' => [
        'value'  => $value,
        'format' => 'basic_html',
      ],
    ]);
    $block->save();
    return $block;
  }

  /**
   * Removes D7-specific inline tokens that have no D10 equivalent.
   *
   * Strips two patterns commonly found in D7 WYSIWYG body fields:
   *
   * 1. Node Embed tokens: [[nid:1096]]
   *    Inserted by the Node Embed / Insert Node module to render another node's
   *    content inline. There is no equivalent in D10; the token would otherwise
   *    appear as literal text in the migrated body.
   *
   * 2. D7 Media WYSIWYG embeds: [[{"type":"media","fid":"123",...}]]
   *    Inserted by the D7 Media module's WYSIWYG integration. These JSON blobs
   *    are D7-specific and would also render as literal text in D10.
   *
   * @param string $value
   *   Raw body HTML from the D7 source.
   *
   * @return string
   *   The sanitized HTML with D7 tokens removed.
   */
  private function sanitizeBodyHtml(string $value): string {
    // Strip [[nid:NNN]] Node Embed tokens.
    $value = preg_replace('/\[\[nid:\d+\]\]/', '', $value);

    // Strip [[{...JSON...}]] D7 Media WYSIWYG embed tokens.
    // These always start with [[ and contain a JSON object.
    $value = preg_replace('/\[\[\{[^\]]*\}\]\]/', '', $value);

    return $value;
  }

  /**
   * Returns the first non-null resolved value for a component.
   *
   * Supports two YAML keys:
   *   - 'sources' (array): tried in order; the first non-null/empty value wins.
   *   - 'source'  (string): a single source key.
   *
   * @param array $component
   *   The component definition.
   *
   * @return mixed
   *   The resolved value, or NULL if nothing resolved.
   */
  private function resolveSource(array $component): mixed {
    if (!empty($component['sources'])) {
      foreach ($component['sources'] as $source) {
        $value = $this->getSourceValue($source);
        if ($value !== NULL && $value !== '') {
          return $value;
        }
      }
      return NULL;
    }

    if (isset($component['source'])) {
      return $this->getSourceValue($component['source']);
    }

    return NULL;
  }

  /**
   * Reads a value from the current row.
   *
   * Keys prefixed with '@' are resolved from destination (processed)
   * properties set earlier in the migration pipeline; all others are treated
   * as source properties (supports nested path notation, e.g. body/0/value).
   *
   * @param string $source
   *   The property key, optionally prefixed with '@'.
   *
   * @return mixed
   *   The resolved value, or NULL if not found.
   */
  private function getSourceValue(string $source): mixed {
    if (str_starts_with($source, '@')) {
      return $this->row->getDestinationProperty(substr($source, 1));
    }
    return $this->row->getSourceProperty($source);
  }

}
