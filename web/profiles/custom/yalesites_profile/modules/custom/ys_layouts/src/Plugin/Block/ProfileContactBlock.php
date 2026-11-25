<?php

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
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Block for profile contact data that appears in the sidebar of profiles.
 *
 * The "layout_builder.entity" context slot and its name are explained on
 * \Drupal\ys_core\Plugin\Block\LayoutBuilderEntityContextTrait. An
 * attribute cannot be inherited from a trait, so the slot is declared here.
 */
#[Block(
  id: 'profile_contact_block',
  admin_label: new TranslatableMarkup('Profile Contact Block'),
  category: new TranslatableMarkup('YaleSites Layouts'),
  context_definitions: [
    'layout_builder.entity' => new ContextDefinition(
      data_type: 'entity',
      label: new TranslatableMarkup('Entity being viewed'),
      required: FALSE,
    ),
  ],
)]
class ProfileContactBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * Constructs a new ProfileContactBlock instance.
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
  public function defaultConfiguration() {
    return [
      'padding_options' => 'default',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $config = $this->getConfiguration();

    $form['padding_options'] = [
      '#type' => 'select',
      '#title' => $this->t('Padding Options'),
      '#description' => $this->t('Choose the padding configuration for this block.'),
      '#default_value' => $config['padding_options'] ?? 'default',
      '#options' => [
        'default' => $this->t('Padding on both top and bottom'),
        'no_top' => $this->t('No top padding'),
        'no_bottom' => $this->t('No bottom padding'),
        'no_padding' => $this->t('No padding (removes both top and bottom padding)'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['padding_options'] = $form_state->getValue('padding_options');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    $email = NULL;
    $phone = NULL;
    $address = NULL;

    $node = $this->getCurrentNode();

    if ($node) {
      // Profile fields.
      $email = $this->getValueFor($node, 'field_email');
      $phone = $this->getValueFor($node, 'field_telephone');
      $address = $this->getValueFor($node, 'field_address');
    }

    return [
      '#theme' => 'ys_profile_contact_block',
      '#email' => $email,
      '#phone' => $phone,
      '#address' => $address,
      '#padding_options' => $this->configuration['padding_options'] ?? 'default',
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
   * Get the value for a specific field.
   *
   * @param object $node
   *   The node object.
   * @param string $name
   *   The field name.
   *
   * @return mixed
   *   The field value.
   */
  protected function getValueFor($node, $name) {
    try {
      $fieldObject = $node->get($name);
    }
    catch (\Exception $e) {
      return NULL;
    }

    $valueObject = $fieldObject->getValue();
    if ($valueObject) {
      $firstElement = $valueObject[0];
      if ($firstElement) {
        return $firstElement['value'];
      }
    }

    return NULL;
  }

}
