<?php

namespace Drupal\layout_builder_block_clone\Form;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\StringTranslation\TranslationManager;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\Core\Ajax\AjaxFormHelperTrait;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\layout_builder\Controller\LayoutRebuildTrait;
use Drupal\layout_builder\LayoutBuilderHighlightTrait;
use Drupal\entity_clone\Event\EntityCloneEvents;
use Drupal\entity_clone\Event\EntityCloneEvent;

/**
 * Class CloneLayoutBlockForm
 *
 * Provides a form for clone a layout block.
 *
 * @package Drupal\layout_builder_block_clone\Form
 */
class CloneLayoutBlockForm extends ConfirmFormBase {

  use AjaxFormHelperTrait;
  use LayoutBuilderHighlightTrait;
  use LayoutRebuildTrait;

  /**
   * The layout tempstore repository.
   *
   * @var \Drupal\layout_builder\LayoutTempstoreRepositoryInterface
   */
  protected $layoutTempstoreRepository;

  /**
   * The section storage.
   *
   * @var \Drupal\layout_builder\SectionStorageInterface
   */
  protected $sectionStorage;

  /**
   * The field delta.
   *
   * @var int
   */
  protected $delta;

  /**
   * The current region.
   *
   * @var string
   */
  protected $region;

  /**
   * The UUID of the block being removed.
   *
   * @var string
   */
  protected $uuid;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity type définition.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface
   */
  protected $entityTypeDefinition;

  /**
   * The string translation manager.
   *
   * @var \Drupal\Core\StringTranslation\TranslationManager
   */
  protected $stringTranslationManager;

  /**
   * Event dispatcher service.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\Messenger
   */
  protected $messenger;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The UUID generator.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuidGenerator;

  /**
   * CloneLayoutBlockForm constructor.
   *
   * @param \Drupal\layout_builder\LayoutTempstoreRepositoryInterface $layout_tempstore_repository
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\StringTranslation\TranslationManager $string_translation
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   * @param \Drupal\Core\Messenger\Messenger $messenger
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(LayoutTempstoreRepositoryInterface $layout_tempstore_repository,
                              EntityTypeManagerInterface $entity_type_manager, TranslationManager $string_translation,
                              EventDispatcherInterface $eventDispatcher, Messenger $messenger, ModuleHandlerInterface $module_handler,
                              UuidInterface $uuid) {
    $this->layoutTempstoreRepository = $layout_tempstore_repository;
    $this->entityTypeManager = $entity_type_manager;
    $this->stringTranslationManager = $string_translation;
    $this->eventDispatcher = $eventDispatcher;
    $this->messenger = $messenger;
    $this->entityTypeDefinition = $entity_type_manager->getDefinition('block_content');
    $this->moduleHandler = $module_handler;
    $this->uuidGenerator = $uuid;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('layout_builder.tempstore_repository'),
      $container->get('entity_type.manager'),
      $container->get('string_translation'),
      $container->get('event_dispatcher'),
      $container->get('messenger'),
      $container->get('module_handler'),
      $container->get('uuid')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $label = $this->sectionStorage
      ->getSection($this->delta)
      ->getComponent($this->uuid)
      ->getPlugin()
      ->label();

    return $this->t('Are you sure you want to clone the %label block?', ['%label' => $label]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Clone');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'layout_builder_block_clone.clone_block_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, SectionStorageInterface $section_storage = NULL, $delta = NULL, $region = NULL, $uuid = NULL) {
    $this->sectionStorage = $section_storage;
    $this->delta = $delta;
    $this->uuid = $uuid;
    $this->region = $region;

    $form['status_messages'] = [
      '#type' => 'status_messages',
      '#weight' => -1000,
    ];

    $form['copy_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Clone block "Block description"'),
      '#maxlength' => 128,
      '#size' => 128,
      '#required' => TRUE,
    ];

    $form['reusable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Set Cloned Block as reusable'),
      '#description' => $this->t('Set Cloned Block as reusable.
      </br><b>NOTE:</b> Any "block_content" will be set to reusable by default! Since those are reusable by nature!
      </br><b>NOTE:</b> Cloned block will NOT be automatically added to current section!'),
    ];

    $form['config_clone'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Clone Block config and additional info and add Cloned Block to current section'),
      '#description' => $this->t('Clone Block config and additional info.
      </br><b>NOTE:</b> Block will be automatically added to current section.'),
    ];

    $form = parent::buildForm($form, $form_state);

    if ($this->isAjax()) {
      $form['actions']['submit']['#ajax']['callback'] = '::ajaxSubmit';
      $form['actions']['cancel']['#attributes']['class'][] = 'dialog-cancel';
      $target_highlight_id = !empty($this->uuid) ? $this->blockUpdateHighlightId($this->uuid) : $this->sectionUpdateHighlightId($delta);
      $form['#attributes']['data-layout-builder-target-highlight-id'] = $target_highlight_id;
    }

    // Mark this as an administrative page for JavaScript ("Back to site" link).
    $form['#attached']['drupalSettings']['path']['currentPathIsAdmin'] = TRUE;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function successfulAjaxSubmit(array $form, FormStateInterface $form_state) {
    return $this->rebuildAndClose($this->sectionStorage);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->sectionStorage->getLayoutBuilderUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Extract block content uuid.
    $original_section = $this->sectionStorage->getSection($this->delta);
    $component = $original_section->getComponent($this->uuid);

    // Get entity info.
    $entity_info = $component->getPluginId();
    $entity_info = explode(':', $entity_info);
    $entity_type = $entity_info[0];
    $entity = NULL;

    // Get config and additional info.
    $config = $component->get('configuration');
    $additional = $component->get('additional');

    // Get eck.
    switch ($entity_type) {
      case 'inline_block':
        $block_serialized = $config['block_serialized'];
        $entity = unserialize($block_serialized);
        if(!$entity instanceof BlockContent) {
          $revision_id = $config['block_revision_id'];
          $entity = $this->entityTypeManager->getStorage('block_content')->loadRevision($revision_id);
        }
        break;
      case 'block_content':
        $entity = $this->entityTypeManager->getStorage($entity_type)->loadByProperties(['uuid' => $entity_info[1]]);
        $entity = reset($entity);
        break;
    }

    // Check if eck is Block Content and clone it.
    if($entity instanceof BlockContent) {
      // Check if entity clone module is enabled.
      $entity_clone = $this->moduleHandler->moduleExists('entity_clone');
      if($entity_clone) {
        /** @var \Drupal\entity_clone\EntityClone\EntityCloneInterface $entity_clone_handler */
        $entity_clone_handler = $this->entityTypeManager->getHandler($this->entityTypeDefinition->id(), 'entity_clone');
        if ($this->entityTypeManager->hasHandler($this->entityTypeDefinition->id(), 'entity_clone_form')) {
          $entity_clone_form_handler = $this->entityTypeManager->getHandler($this->entityTypeDefinition->id(), 'entity_clone_form');
        }

        $properties = [];
        if (isset($entity_clone_form_handler) && $entity_clone_form_handler) {
          $properties = $entity_clone_form_handler->getValues($form_state);
        }
      }

      // Create block duplicate.
      $cloned_entity = $entity->createDuplicate();

      // If entity clone module is used dispatch events and clone via handler.
      if($entity_clone) {
        $this->eventDispatcher->dispatch(EntityCloneEvents::PRE_CLONE, new EntityCloneEvent($entity, $cloned_entity, $properties));
        $cloned_entity = $entity_clone_handler->cloneEntity($entity, $cloned_entity, $properties);
        $this->eventDispatcher->dispatch(EntityCloneEvents::POST_CLONE, new EntityCloneEvent($entity, $cloned_entity, $properties));
      }

      // Set new subject to duplicated eck.
      $label_key = $this->entityTypeManager->getDefinition($this->entityTypeDefinition->id())->getKey('label');
      if ($label_key && $cloned_entity->hasField($label_key)) {
        $cloned_entity->set($label_key, $form_state->getValue('copy_subject'));
        // Set reusable if selected.
        // We need to set block_content to reusable, or we will have broken component.
        if ($form_state->getValue('reusable') || $entity_type === 'block_content') {
          $cloned_entity->setReusable();
        } else {
          $cloned_entity->setNonReusable();
        }
        $cloned_entity->save();
      }

      // Clone config and additional if it is selected OR reusable was NOT set!
      if($form_state->getValue('config_clone') || !$form_state->getValue('reusable')) {
        $new_conf = $config;
        $new_conf['label'] = $form_state->getValue('copy_subject');

        // Set correct id based on origin.
        switch ($entity_type) {
          case 'inline_block':
            // If it is reusable set proper id.
            $new_conf['id'] = $entity_type . ':' . $cloned_entity->bundle();
            $new_conf['block_serialized'] = serialize($cloned_entity);
            $new_conf['block_revision_id'] = $cloned_entity->getRevisionId();
            break;
          case 'block_content':
            $new_conf['id'] = $entity_type . ':' . $cloned_entity->uuid();
            $new_conf['uuid'] = $cloned_entity->uuid();
            break;
        }

        // Attach additional info only if we ask for it.
        if($form_state->getValue('config_clone')) {
          $new_additional = is_array($additional) ? $additional : [];
          $component_new = new SectionComponent($this->uuidGenerator->generate(), $this->region, $new_conf, $new_additional);
        } else {
          $component_new = new SectionComponent($this->uuidGenerator->generate(), $this->region, $new_conf, []);
        }

        // Set component to current section.
        $original_section->appendComponent($component_new);
      }

      // Get success msg.
      $message = $this->stringTranslationManager->translate('The entity <em>@entity (@entity_id)</em> of type <em>@type</em> was cloned: <em>@cloned_entity (@cloned_entity_id)</em> .', [
        '@entity' => $entity->label(),
        '@entity_id' => $entity->id(),
        '@type' => $entity->getEntityTypeId(),
        '@cloned_entity' => $cloned_entity->label(),
        '@cloned_entity_id' => $cloned_entity->id(),
      ]);
    }

    $this->layoutTempstoreRepository->set($this->sectionStorage);

    $response = $this->rebuildLayout($this->sectionStorage);
    $response->addCommand(new MessageCommand($message));
    $response->addCommand(new CloseDialogCommand('#drupal-off-canvas'));

    return $response;
  }

  /**
   * Check access for layout block clone.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface|NULL $section_storage
   * @param null $delta
   * @param null $region
   * @param null $uuid
   *
   * @return \Drupal\Core\Access\AccessResultAllowed|\Drupal\Core\Access\AccessResultForbidden
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function checkAccess(SectionStorageInterface $section_storage = NULL, $delta = NULL, $region = NULL, $uuid = NULL) {
    // Get config.
    $original_section = $section_storage->getSection($delta);
    $component = $original_section->getComponent($uuid);
    $id = explode(':', $component->getPluginId());

    // Allow access only for block content.
    $allowed = [
      'inline_block' => 1,
      'block_content' => 1
    ];

    if(isset($allowed[$id[0]])) {
      return AccessResult::allowed();
    }

    // If this is other type deni access!
    return AccessResult::forbidden();
  }
}

