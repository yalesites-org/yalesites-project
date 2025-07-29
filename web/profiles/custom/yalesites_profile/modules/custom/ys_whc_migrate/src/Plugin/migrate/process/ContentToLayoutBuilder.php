<?php

declare(strict_types=1);

namespace Drupal\ys_whc_migrate\Plugin\migrate\process;

use Drupal\block_content\BlockContentInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
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
 *
 * @MigrateProcessPlugin(id = "whc_content_to_layout_builder")
 */
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
   */
  public function createDefaultSection(): Section {
    $section_components = [
      new SectionComponent($this->uuid->generate(), 'content', [
        'id' => 'event_meta_block',
        'label' => 'Event Meta Block',
        'label_display' => '',
        'provider' => 'ys_layouts',
      ]),
      new SectionComponent($this->uuid->generate(), 'content', [
        'id' => 'extra_field_block:node:event:content_moderation_control',
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
          'id' => 'inline_block:' . $component['type'],
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

    return match ($type) {
      'text' => $this->createTextBlock($source, $component_definition),
      default => NULL,
    };
  }

  /**
   * Creates a text block entity.
   *
   * @param string $value
   *   The text value.
   * @param array ...$args
   *   Additional arguments.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The text block entity.
   */
  private function createTextBlock(string $value, array ...$args): BlockContentInterface {
    $text_block = $this->entityTypeManager
      ->getStorage('block_content')
      ->create([
        'type' => 'text',
        'title' => $value,
        'field_text' => [
          'value' => $value,
          'format' => 'basic_html',
        ],
        'field_style_variation' => $args['field_style_variation'] ?? 'default',
      ]);

    $text_block->save();
    return $text_block;
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
