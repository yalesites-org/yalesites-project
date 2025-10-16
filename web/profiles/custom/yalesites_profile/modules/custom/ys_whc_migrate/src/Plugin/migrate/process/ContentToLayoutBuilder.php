<?php

declare(strict_types=1);

namespace Drupal\ys_whc_migrate\Plugin\migrate\process;

use Drupal\block_content\BlockContentInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
 * Provides a Content to Layout Builder process plugin.
 *
 * This process plugin allows to transform any content into a Layout Builder
 * block component within a Layout Builder section.
 *
 * Usage:
 *
 * @code
 * process:
 *
 *   layout_builder__layout:
 *     plugin: whc_content_to_layout_builder
 *     create_default_section: TRUE
 *     default_section_type: event
 *     sections:
 *       header:
 *         id: layout_onecol
 *         components:
 *           - type: text
 *             source: title
 *           - type: text
 *             source: field_subtitle
 *             additional_arg_1: default
 *       content:
 *         id: layout_onecol
 *         components:
 *           - type: text
 *             source: body
 * @endcode
 */
#[MigrateProcess(
  id: 'whc_content_to_layout_builder'
)]
class ContentToLayoutBuilder extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The current row.
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): mixed {
    if (!($sections = $this->configuration['sections'])) {
      throw new MigrateException('You must specify the layout sections.');
    }

    $this->row = $row;
    $layout_sections = [];

    if ($this->configuration['create_default_section'] ?? TRUE) {
      $layout_sections[] = $this->createDefaultSection();
    }

    foreach ($sections as $section) {
      if (!isset($section['components'])) {
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
   * Creates the default Layout Builder section for Title and Metadata.
   *
   * By default, when creating a node using layout builder, the default section
   * of the node type is dynamically created by Drupal. The default section is
   * only saved to the database after the layout is modified and saved.
   *
   * Due to populating the node through the migrate process, the default section
   * is not added to the layout. This function creates the default section.
   *
   * @return \Drupal\layout_builder\Section
   *   The default Layout Builder section.
   *
   * @throws \Drupal\migrate\MigrateException
   *   If the default section id is not specified.
   */
  public function createDefaultSection(): Section {
    $default_section_type = $this->configuration['default_section_type'] ?? NULL;

    if (!$default_section_type) {
      throw new MigrateException('You must specify the default section id.');
    }

    $section_components = [
      new SectionComponent($this->uuid->generate(), 'content', [
        'id' => $default_section_type . '_meta_block',
        'label' => ucfirst($default_section_type) . ' Meta Block',
        'label_display' => '',
        'provider' => 'ys_layouts',
      ]),
      new SectionComponent($this->uuid->generate(), 'content', [
        'id' => 'extra_field_block:node:' . $default_section_type . ':content_moderation_control',
        'label_display' => FALSE,
        'context_mapping' => [
          'entity' => 'layout_builder.entity',
        ],
      ]),
    ];

    return new Section(
      'layout_onecol',
      ['label' => 'Title and Metadata'],
      $section_components
    );
  }

  /**
   * Maps a section definition to a Layout Builder section.
   *
   * @param array $section
   *   The section definition.
   *
   * @return \Drupal\layout_builder\Section|null
   *   The Layout Builder section.
   */
  public function mapSection(array $section): ?Section {
    return match ($section['id']) {
      'layout_onecol' => $this->createOneColumnSection($section),
      default => NULL,
    };
  }

  /**
   * Creates a one-column Layout Builder section.
   *
   * @param array $section
   *   The section definition.
   *
   * @return \Drupal\layout_builder\Section
   *   The one-column Layout Builder section.
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
   * Maps a component definition to a block entity.
   *
   * @param array $component_definition
   *   The component definition.
   *
   * @return \Drupal\block_content\BlockContentInterface|null
   *   The block entity.
   */
  public function mapComponents(array $component_definition): ?BlockContentInterface {
    $type = $component_definition['type'];
    $source = $this->getComponentSourceValue($component_definition['source']);

    if (!$source) {
      return NULL;
    }

    return match ($type) {
      'image' => $this->createImageBlock($source),
      'text' => $this->createTextBlock($source, $component_definition),
      'video_banner' => $this->createVideoBannerBlock($source, $component_definition),
      default => NULL,
    };
  }

  /**
   * Creates a text block entity.
   *
   * @param string $value
   *   The text value.
   * @param array $additional_args
   *   Additional arguments.
   *
   * @return \Drupal\block_content\BlockContentInterface
   *   The text block entity.
   */
  private function createTextBlock(string $value, array $additional_args): BlockContentInterface {
    if ($additional_args['wrap_value'] ?? FALSE) {
      $value = '<p>' . $value . '</p>';
    }

    $text_block = $this->entityTypeManager
      ->getStorage('block_content')
      ->create([
        'type' => 'text',
        'title' => 'Text Block',
        'reusable' => FALSE,
        'field_text' => [
          'value' => $value,
          'format' => 'basic_html',
        ],
        'field_style_variation' => $additional_args['field_style_variation'] ?? 'default',
      ]);

    $text_block->save();
    return $text_block;
  }

  /**
   * Creates an image block entity.
   *
   * @param string $source
   *   The image source.
   *
   * @return \Drupal\block_content\BlockContentInterface
   *   The image block entity.
   */
  private function createImageBlock(string $source): ?BlockContentInterface {
    /** @var \Drupal\media\MediaInterface $media */
    $media = $this->entityTypeManager
      ->getStorage('media')
      ->load($source);

    if (!$media) {
      return NULL;
    }

    if ($caption = $media->get('field_media_image')->alt) {
      $caption = '<p>' . $caption . '</p>';
    }

    $image_block = $this->entityTypeManager
      ->getStorage('block_content')
      ->create([
        'type' => 'image',
        'title' => 'Image Block',
        'reusable' => FALSE,
        'field_media' => [
          'target_id' => $media->id(),
        ],
        'field_text' => [
          'value' => $caption,
          'format' => 'basic_html',
        ],
      ]);

    $image_block->save();
    return $image_block;
  }

  /**
   * Gets a term name.
   *
   * @param string $tid
   *   The term ID.
   *
   * @return string|null
   *   The term name.
   */
  public static function getTermName(string $tid): ?string {
    /** @var \Drupal\taxonomy\TermInterface $term */
    $term = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->load($tid);

    return $term?->getName();
  }

  /**
   * Creates a video banner block entity.
   *
   * @param string $source
   *   The video source.
   * @param array $additional_args
   *   Additional arguments.
   *
   * @return \Drupal\block_content\BlockContentInterface
   *   The video banner block entity.
   */
  private function createVideoBannerBlock(string $source, array $additional_args): BlockContentInterface {
    $video_banner_block = $this->entityTypeManager
      ->getStorage('block_content')
      ->create([
        'type' => 'video_banner',
        'title' => 'Video Banner',
        'reusable' => FALSE,
        'field_media' => [
          'target_id' => $source,
        ],
        'field_style_variation' => $additional_args['field_style_width'] ?? 'max',
      ]);

    $video_banner_block->save();
    return $video_banner_block;
  }

  /**
   * Convert a YouTube URL to a standard watch URL format.
   *
   * @param string $url
   *   The YouTube URL to convert.
   *
   * @return string
   *   The converted URL.
   */
  public static function toWatchUrl(string $url): string {
    $pattern = '/(?:youtube\.com\/(?:embed\/|v\/|watch\?v=)|youtu\.be\/|youtube-nocookie\.com\/embed\/)([a-zA-Z0-9_-]{11})/';
    if (preg_match($pattern, $url, $matches)) {
      $video_id = $matches[1];
      return "https://www.youtube.com/watch?v=" . $video_id;
    }

    return $url;
  }

  /**
   * Replaces straight quotes with curly quotes.
   *
   * @param string $text
   *   The text to replace.
   *
   * @return string
   *   The replaced text.
   */
  public static function replaceStraightQuotes(string $text): string {
    $text = preg_replace('/"([^\"]*)"/', '“$1”', $text);
    $text = preg_replace("/'([^']*)'/", '‘$1’', $text);

    return $text;
  }

  /**
   * Gets the value of a component source.
   *
   * @param string $source
   *   The component source.
   *
   * @return mixed
   *   The component source value.
   */
  public function getComponentSourceValue(string $source): mixed {
    return $this->row->get($source) ?? NULL;
  }

}
