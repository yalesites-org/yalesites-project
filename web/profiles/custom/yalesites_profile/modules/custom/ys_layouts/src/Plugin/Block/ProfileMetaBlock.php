<?php

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\node\NodeInterface;
use Drupal\ys_core\Plugin\Block\LayoutBuilderEntityContextTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Block for profile meta data that appears above profiles.
 *
 * The "layout_builder.entity" context slot and its name are explained on
 * \Drupal\ys_core\Plugin\Block\LayoutBuilderEntityContextTrait. An
 * annotation cannot be inherited from a trait, so the slot is declared here.
 *
 * @Block(
 *   id = "profile_meta_block",
 *   admin_label = @Translation("Profile Meta Block"),
 *   category = @Translation("YaleSites Layouts"),
 *   context_definitions = {
 *     "layout_builder.entity" = @ContextDefinition("entity",
 *       label = @Translation("Entity being viewed"),
 *       required = FALSE
 *     )
 *   }
 * )
 */
class ProfileMetaBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
  protected $entityTypeManager;

  /**
   * Constructs a new ProfileMetaBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RequestStack $request_stack,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    $title = NULL;
    $position = NULL;
    $subtitle = NULL;
    $department = NULL;
    $pronouns = NULL;
    $mediaId = NULL;

    $node = $this->getCurrentNode();

    if ($node && $node->bundle() === 'profile') {
      // Profile fields.
      $title = $node->getTitle();

      $position = $node->get('field_position')->getValue()[0]['value'] ?? NULL;
      $subtitle = $node->get('field_subtitle')->getValue()[0]['value'] ?? NULL;
      $department = $node->get('field_department')->getValue()[0]['value'] ?? NULL;
      $pronouns = $node->get('field_pronouns')->getValue()[0]['value'] ?? NULL;
      $mediaId = $node->get('field_media')->getValue()[0]['target_id'] ?? NULL;
    }

    return [
      '#theme' => 'ys_profile_meta_block',
      '#profile_meta__heading' => $title,
      '#profile_meta__title_line' => $position,
      '#profile_meta__subtitle_line' => $subtitle,
      '#profile_meta__department' => $department,
      '#profile_meta__pronouns' => $pronouns,
      '#media_id' => $mediaId,
      '#profile_meta__image_orientation' => $this->configuration['image_orientation'] ?? 'portrait',
      '#profile_meta__image_style' => $this->configuration['image_style'] ?? 'inline',
      '#profile_meta__image_alignment' => $this->configuration['image_alignment'] ?? 'left',

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
      $layoutBuilderPath = $request->getPathInfo();
      preg_match('/(node\.+(\d+))/', $layoutBuilderPath, $matches);
      if (!empty($matches)) {
        $nodeStorage = $this->entityTypeManager->getStorage('node');
        $node = $nodeStorage->load($matches[2]);
      }
    }

    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) : array {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    // The form field is defined and added to the form array here.
    $form['image_orientation'] = [
      '#type' => 'select',
      '#title' => $this->t('Image orientation'),
      '#default_value' => $config['image_orientation'] ?? 'portrait',
      '#options' => [
        'landscape' => $this->t('Landscape'),
        'portrait' => $this->t('Portrait'),
      ],
    ];

    $form['image_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Image style'),
      '#default_value' => $config['image_style'] ?? 'inline',
      '#options' => [
        'inline' => $this->t('Inline'),
        'outdent' => $this->t('Outdent'),
      ],
    ];

    $form['image_alignment'] = [
      '#type' => 'select',
      '#title' => $this->t('Image alignment'),
      '#default_value' => $config['image_alignment'] ?? 'left',
      '#options' => [
        'left' => $this->t('Left'),
        'right' => $this->t('Right'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) : void {
    parent::blockSubmit($form, $form_state);
    $this->configuration['image_orientation'] = $form_state->getValue('image_orientation');
    $this->configuration['image_style'] = $form_state->getValue('image_style');
    $this->configuration['image_alignment'] = $form_state->getValue('image_alignment');
  }

}
