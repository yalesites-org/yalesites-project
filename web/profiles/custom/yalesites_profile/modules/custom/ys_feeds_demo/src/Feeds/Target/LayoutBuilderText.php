<?php

namespace Drupal\ys_feeds_demo\Feeds\Target;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\FieldTargetDefinition;
use Drupal\feeds\Plugin\Type\Target\ConfigurableTargetInterface;
use Drupal\feeds\Plugin\Type\Target\FieldTargetBase;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Puts imported text into a Layout Builder text block. SPIKE, not a feature.
 *
 * Every YaleSites bundle renders through Layout Builder, so an import that
 * only fills fields produces a node whose body area is empty. This target
 * closes that gap for the simplest possible case: one paragraph of text in a
 * text block, dropped into the empty content section that the bundle's default
 * view display already provides.
 *
 * It is deliberately limited, and the limits are the point of the exercise:
 *
 * - It runs on create only. If a node already has a layout, this target does
 *   nothing at all, because overwriting an editor's page would be far worse
 *   than leaving the body empty. That also means a changed description in the
 *   source will never reach a node that has already been imported.
 * - It knows nothing about layout_builder_restrictions or
 *   layout_builder_lock, both of which are enabled on this platform. A
 *   component placed this way bypasses both.
 * - Deleting an imported node leaves the inline block behind. YaleSites
 *   already ships a cleaner for that (drush ys-orphaned-blocks), which is the
 *   only reason this is survivable in a demo.
 *
 * Making this production-ready means solving component diffing, restriction
 * awareness and orphan handling. That is a feature, not an afternoon.
 *
 * @FeedsTarget(
 *   id = "ys_layout_builder_text",
 *   field_types = {"layout_section"}
 * )
 */
class LayoutBuilderText extends FieldTargetBase implements ConfigurableTargetInterface, ContainerFactoryPluginInterface {

  /**
   * The uuid generator.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuid;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity display repository.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected $displayRepository;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    UuidInterface $uuid,
    EntityTypeManagerInterface $entity_type_manager,
    EntityDisplayRepositoryInterface $display_repository,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->uuid = $uuid;
    $this->entityTypeManager = $entity_type_manager;
    $this->displayRepository = $display_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('uuid'),
      $container->get('entity_type.manager'),
      $container->get('entity_display.repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected static function prepareTarget(FieldDefinitionInterface $field_definition) {
    return FieldTargetDefinition::createFromFieldDefinition($field_definition)
      ->addProperty('value');
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'format' => 'basic_html',
      'section_label' => 'Content Section',
      'block_label' => 'Imported content',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $formats = [];
    foreach ($this->entityTypeManager->getStorage('filter_format')->loadMultiple() as $id => $format) {
      $formats[$id] = $format->label();
    }

    $form['format'] = [
      '#type' => 'select',
      '#title' => $this->t('Text format'),
      '#options' => $formats,
      '#default_value' => $this->configuration['format'],
    ];

    $form['section_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Section to place the block in'),
      '#default_value' => $this->configuration['section_label'],
      '#description' => $this->t('The label of a section in the bundle default layout. If no section matches, a new one-column section is appended.'),
    ];

    $form['block_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Block label'),
      '#default_value' => $this->configuration['block_label'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    return $this->t('Creates a text block in the %section section, on first import only', [
      '%section' => $this->configuration['section_label'],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function setTarget(FeedInterface $feed, EntityInterface $entity, $field_name, array $values) {
    $text = trim((string) ($values[0]['value'] ?? ''));

    if ($text === '' || !$entity->hasField($field_name)) {
      return;
    }

    // Never touch a layout that already exists. An editor may have spent an
    // afternoon on this page; a sync has no business rearranging it.
    if (!$entity->get($field_name)->isEmpty()) {
      return;
    }

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text',
      'info' => $this->configuration['block_label'],
      'reusable' => FALSE,
      'field_text' => [
        'value' => $text,
        'format' => $this->configuration['format'],
      ],
    ]);
    $block->save();

    $component = new SectionComponent($this->uuid->generate(), 'content', [
      'id' => 'inline_block:text',
      'label' => $this->configuration['block_label'],
      'provider' => 'layout_builder',
      'label_display' => FALSE,
      'view_mode' => 'full',
      'block_revision_id' => $block->getRevisionId(),
      'context_mapping' => [],
    ]);

    $entity->set($field_name, $this->buildSections($entity, $component));
  }

  /**
   * Builds the section list for an entity, with the component placed in it.
   *
   * The bundle's default view display already ships sections — a banner, a
   * meta section, an empty content section, related content. Starting from
   * those and filling the empty one keeps the imported node looking like every
   * other node of its type, rather than replacing the whole layout with a bare
   * one-column section.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being imported.
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component to place.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The sections to store on the entity.
   */
  protected function buildSections(EntityInterface $entity, SectionComponent $component) {
    $sections = [];

    $display = $this->displayRepository->getViewDisplay(
      $entity->getEntityTypeId(),
      $entity->bundle(),
      'default'
    );

    if ($display instanceof LayoutEntityDisplayInterface) {
      foreach ($display->getSections() as $section) {
        // Clone: these belong to a config entity and must not be mutated.
        $sections[] = clone $section;
      }
    }

    $target_label = $this->configuration['section_label'];
    foreach ($sections as $section) {
      $settings = $section->getLayoutSettings();
      if (($settings['label'] ?? '') === $target_label) {
        $section->appendComponent($component);
        return $sections;
      }
    }

    // No matching section, so append one rather than losing the content.
    $sections[] = new Section('layout_onecol', ['label' => $target_label], [$component]);

    return $sections;
  }

}
