<?php

declare(strict_types=1);

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\ContextAwarePluginAssignmentTrait;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Plugin\ContextAwarePluginTrait;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a yalesites taxonomy display block block.
 *
 * @Block(
 *   id = "ys_taxonomy_display_block",
 *   admin_label = @Translation("Taxonomy Display Block"),
 *   category = @Translation("YaleSites Layouts"),
 * )
 */
class TaxonomyDisplayBlock extends BlockBase implements ContextAwarePluginInterface, ContainerFactoryPluginInterface {

  use ContextAwarePluginTrait;
  use ContextAwarePluginAssignmentTrait;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The route match service.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected CurrentRouteMatch $routeMatch;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the plugin instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entityTypeManager,
    CurrentRouteMatch $routeMatch,
    RequestStack $requestStack,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entityTypeManager;
    $this->routeMatch = $routeMatch;
    $this->requestStack = $requestStack;
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
      $container->get('current_route_match'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'vocabularies' => [],
      'theme_selection' => 'default',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {

    $node = $this->getCurrentNode();
    $event_vocabularies = ['event_category', 'audience', 'custom_vocab'];

    if ($node instanceof Node) {
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
        $form['vocabulary_fields'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Select vocabularies to display'),
          '#options' => $vocabulary_fields,
          '#default_value' => $this->configuration['vocabulary_fields'] ?? [],
          '#description' => $this->t('Choose which vocabularies should be displayed in this block.'),
          '#required' => TRUE,
        ];

        $form['theme_selection'] = [
          '#type' => 'select',
          '#title' => $this->t('Theme'),
          '#options' => [
            'default' => $this->t('Default - No color'),
            'one' => $this->t('One'),
            'two' => $this->t('Two'),
            'three' => $this->t('Three'),
            'four' => $this->t('Four'),
            'five' => $this->t('Five'),
          ],
          '#default_value' => $this->configuration['theme_selection'] ?? 'default',
        ];

      }
      else {
        $form['no_vocabulary_fields'] = [
          '#type' => 'markup',
          '#markup' => $this->t('No taxonomy fields found on this content type.'),
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
          if (!empty($terms)) {
            $items[$field_name] = [
              'label' => $field_label,
              'terms' => $terms,
            ];
          }
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
