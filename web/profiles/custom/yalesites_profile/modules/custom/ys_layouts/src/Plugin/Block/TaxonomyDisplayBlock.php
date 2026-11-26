<?php

declare(strict_types=1);

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\node\NodeInterface;
use Drupal\ys_core\Plugin\Block\LayoutBuilderEntityContextTrait;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ys_themes\ColorTokenResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a yalesites taxonomy display block block.
 *
 * The "layout_builder.entity" context slot and its name are explained on
 * \Drupal\ys_core\Plugin\Block\LayoutBuilderEntityContextTrait. An
 * attribute cannot be inherited from a trait, so the slot is declared here.
 */
#[Block(
  id: 'ys_taxonomy_display_block',
  admin_label: new TranslatableMarkup('Taxonomy Display Block'),
  category: new TranslatableMarkup('YaleSites Layouts'),
  context_definitions: [
    'layout_builder.entity' => new ContextDefinition(
      data_type: 'entity',
      label: new TranslatableMarkup('Entity being viewed'),
      required: FALSE,
    ),
  ],
)]
class TaxonomyDisplayBlock extends BlockBase implements ContainerFactoryPluginInterface {

  use LayoutBuilderEntityContextTrait;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The color token resolver.
   *
   * @var \Drupal\ys_themes\ColorTokenResolver
   */
  protected ColorTokenResolver $colorTokenResolver;

  /**
   * Constructs the plugin instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entityTypeManager,
    RequestStack $requestStack,
    ColorTokenResolver $colorTokenResolver,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entityTypeManager;
    $this->requestStack = $requestStack;
    $this->colorTokenResolver = $colorTokenResolver;
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
      $container->get('request_stack'),
      $container->get('ys_themes.color_token_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'vocabularies' => [],
      'theme_selection' => 'one',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {

    $node = $this->getCurrentNode();
    $event_vocabularies = ['event_category', 'audience', 'custom_vocab'];

    if ($node) {
      // Get all fields on this node.
      $fields = $node->getFields();
      $vocabulary_fields = [];

      // Loop through fields looking for taxonomy term reference fields.
      foreach ($fields as $field) {
        if ($field->getFieldDefinition()->getType() === 'entity_reference' &&
            $field->getFieldDefinition()->getSetting('target_type') === 'taxonomy_term') {

          // Get the vocabulary ID this field references.
          $handler_settings = $field->getFieldDefinition()->getSetting('handler_settings');
          if (!empty($handler_settings['target_bundles'])) {
            foreach ($handler_settings['target_bundles'] as $vid) {
              // Skip 'tags' and localist vocabularies for events.
              if ($vid == 'tags' || ($node->bundle() == 'event' && !in_array($vid, $event_vocabularies))) {
                continue;
              }
              else {
                $vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($vid);
                if ($vocabulary) {
                  $vocabulary_fields[$field->getName()] = $field->getFieldDefinition()->getLabel();
                }
              }
            }
          }
        }
      }

      if (!empty($vocabulary_fields)) {
        $vocabulary_fields_title = $this->t('Select vocabularies to display');
        $form['vocabulary_fields'] = [
          '#type' => 'checkboxes',
          '#title' => $vocabulary_fields_title,
          '#options' => $vocabulary_fields,
          '#default_value' => $this->configuration['vocabulary_fields'] ?? [],
          '#description' => $this->t('Choose which vocabularies should be displayed in this block.'),
          '#required' => TRUE,
          // Layout Builder Browser strips #ajax from this form's submit, so a
          // server-side "required" error reloads the form as a standalone page
          // and the editor leaves the dialog. A checkbox group cannot carry
          // HTML5 required either, so this check blocks the submit while no
          // vocabulary is checked and shows Drupal's inline error under the
          // field instead. #required stays as the no-JS fallback.
          '#attributes' => [
            'class' => ['js-ys-required-checkboxes'],
            'data-ys-required-message' => $this->t('@name field is required.', [
              '@name' => $vocabulary_fields_title,
            ]),
          ],
          '#attached' => [
            'library' => ['ys_layouts/required_checkboxes'],
          ],
        ];

        $form['theme_selection'] = [
          '#type' => 'select',
          '#title' => $this->t('Theme'),
          '#options' => [
            'default' => $this->t('Default - No Color'),
            'one' => $this->t('One'),
            'two' => $this->t('Two'),
            'three' => $this->t('Three'),
            'four' => $this->t('Four'),
            'five' => $this->t('Five'),
            'six' => $this->t('Six'),
          ],
          '#default_value' => $this->configuration['theme_selection'] ?? 'one',
          '#process' => [
            [$this, 'processColorPicker'],
          ],
        ];

      }
      else {
        $form['no_vocabulary_fields'] = [
          '#type' => 'markup',
          '#markup' => $this->t('This content type has no tags or categories.'),
        ];
      }

    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['vocabulary_fields'] = $form_state->getValue('vocabulary_fields');
    $this->configuration['theme_selection'] = $form_state->getValue('theme_selection');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $selected_fields = array_filter($this->configuration['vocabulary_fields'] ?? []);

    $node = $this->getCurrentNode();

    $items = [];

    if ($node && !empty($selected_fields)) {
      foreach ($selected_fields as $field_name) {
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

          $items[$field_name] = [
            'label' => $field_label,
            'terms' => $terms,
          ];
        }
      }
    }

    return [
      '#theme' => 'ys_taxonomy_display_block',
      '#items' => $items,
      '#theme_selection' => $this->configuration['theme_selection'],
    ];
  }

  /**
   * Gets the node being rendered.
   *
   * The entity Layout Builder hands over is preferred over anything the
   * request names; the request tiers below it are kept for the contexts
   * Layout Builder offers nothing in.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The node being rendered, or NULL when no source resolves one.
   */
  protected function getCurrentNode(): ?NodeInterface {
    $node = $this->getRenderedEntitySavedNode();
    if ($node) {
      return $node;
    }

    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return NULL;
    }

    $node = $request->attributes->get('node');

    // When removing the contact block when one already exists,
    // it no longer has access to the node object. Therefore, we must load it
    // manually via the ajaxified path.
    if (!$node) {
      // The Layout Builder ajax path names the entity as "node.<nid>." --
      // e.g. /layout_builder/update/overrides/node.42.default.en/0/content.
      $layoutBuilderPath = $request->getPathInfo();
      if (preg_match('/node\.(\d+)/', $layoutBuilderPath, $matches)) {
        $nodeStorage = $this->entityTypeManager->getStorage('node');
        $node = $nodeStorage->load($matches[1]);
      }
    }

    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Process callback to add the color picker palette UI.
   *
   * Wraps the ColorTokenResolver processColorPicker method with the correct
   * entity type and bundle to use the callout/content_spotlight mapping.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $complete_form
   *   The complete form.
   *
   * @return array
   *   The processed form element.
   */
  public function processColorPicker(
    array &$element,
    FormStateInterface $form_state,
    array &$complete_form,
  ) {
    // Use 'block_content' and 'callout' to get the same color mapping as
    // callout, content_spotlight, content_spotlight_portrait, cta_banner,
    // and grand_hero blocks.
    return $this->colorTokenResolver->processColorPicker(
      $element,
      $form_state,
      $complete_form,
      'block_content',
      'callout',
    );
  }

}
