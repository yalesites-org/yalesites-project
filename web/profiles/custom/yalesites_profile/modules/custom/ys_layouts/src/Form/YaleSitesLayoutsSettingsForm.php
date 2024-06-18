<?php

namespace Drupal\ys_layouts\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Renderer;
use Drupal\ys_layouts\Service\LayoutUpdater;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form class for managing YaleSites Layout settings.
 *
 * This form provides an UI for interacting with YaleSite's Layout Builder
 * settings. Currently it includes actions for updating locks to keep existing
 * nodes in sync with the default configuration. It can be extended in the
 * future to accommodate other types of updates, such as adding or removing
 * default sections or blocks.
 */
class YaleSitesLayoutsSettingsForm extends FormBase {

  /**
   * The layout updater service.
   *
   * @var \ys_layouts\Service\LayoutUpdater
   */
  protected $layoutUpdater;

  /**
   * The Drupal renderer service.
   *
   * @var \Drupal\Core\Render\Renderer
   */
  protected $renderer;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_layouts_settings_form';
  }

  /**
   * Constructs a new YaleSitesLayoutsSettingsForm.
   *
   * @param \Drupal\ys_layouts\Service\LayoutUpdater $layout_updater
   *   The layout updater service.
   * @param \Drupal\Core\Render\Renderer $renderer
   *   The renderer service.
   */
  public function __construct(LayoutUpdater $layout_updater, Renderer $renderer) {
    $this->layoutUpdater = $layout_updater;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ys_layouts.updater'),
      $container->get('renderer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['content'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Content'),
      '#description' => $this->t('
      YaleSites uses Layout Builder to compose content, allowing each node to have unique configuration overrides. However, changes to the default display may not update existing content. This interface manually applies layout updates, currently supporting lock updates but can be extended for other configurations like sections or blocks in the future.'),
    ];

    $form['update_text_formats'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Update text formats'),
      '#description' => $this->t('
        If a default block field changes text formats, this will update the text format on existing placed blocks.'),
    ];

    $rows = [];
    foreach ($this->layoutUpdater->getContentTypes() as $type) {
      $rows[] = [
        $type->label(),
        $this->getNodeCount($type->id()),
        $this->getLockedSectionNames($type->id()),
      ];
    }
    $form['content']['content_types_table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Content Type'),
        $this->t('Node Count'),
        $this->t('Default Locked Sections'),
      ],
      '#rows' => $rows,
    ];
    $form['content']['action_update_locks'] = [
      '#type' => 'submit',
      '#value' => 'Update Locks',
      '#submit' => ['::submitUpdateLocks'],
    ];

    $form['update_text_formats']['block_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Block type'),
      '#options' => $this->layoutUpdater->getBlockTypes(),
      '#ajax' => [
        'callback' => [$this, 'updateBlockFields'],
        'disable-refocus' => FALSE,
        'event' => 'change',
        'wrapper' => 'edit-field-name-wrapper',
      ],
    ];

    $form['update_text_formats']['field_name'] = [
      '#type' => 'select',
      '#title' => $this->t('Field name'),
      '#options' => [
        '' => $this->t('No text fields found.'),
      ],
      '#states' => [
        'invisible' => [
          'select[id="edit-block-type"]' => ['value' => ''],
        ],
      ],
      '#prefix' => '<div id="edit-field-name-wrapper">',
      '#suffix' => '</div>',
      '#validated' => TRUE,
    ];

    $form['update_text_formats']['action_update_block_field'] = [
      '#type' => 'submit',
      '#value' => 'Update text format',
      '#submit' => ['::submitUpdateTextFormats'],
    ];

    $form['tempstore'] = [
      '#type' => 'details',
      '#title' => $this->t('Nodes that have not received layout updates'),
      '#description' => $this->t('When editing a layout, modifications are automatically saved in the temporary storage table "key_value_expire". This table serves as a convenient repository for Drupal to monitor changes. However, the storage is a convoluted mix of content and settings. Due to its complexity, we have opted not to programmatically update nodes in this table. Instead, nodes can be manually updated by saving or discarding changes, followed by executing any update actions provided by this interface.'),
    ];
    $rows = [];
    foreach ($this->layoutUpdater->getTempStoreNodes() as $node) {
      $rows[] = [
        'id' => $node->id(),
        'title' => $node->getTitle(),
        'view' => Link::fromTextAndUrl($this->t('View'), $node->toUrl())->toString(),
      ];
    }
    $form['tempstore']['node_table'] = [
      '#type' => 'table',
      '#header' => [
        'id' => $this->t('Node ID'),
        'title' => $this->t('Title'),
        'view' => $this->t('View Node'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('There are no nodes stored in the temp table.'),
    ];

    return $form;
  }

  /**
   * Count the nodes for a specific content type.
   *
   * @param string $nodeBundleId
   *   The machine name of the content type (node bundle).
   *
   * @return int
   *   A count of the number of nodes for this content type.
   */
  public function getNodeCount($nodeBundleId) {
    return count($this->layoutUpdater->getAllNodeIds($nodeBundleId));
  }

  /**
   * Get a list of all sections names for a content type's default config.
   *
   * @param string $nodeBundleId
   *   The machine name of the content type (node bundle).
   *
   * @return string
   *   A comma separated list of all locked section names.
   */
  protected function getLockedSectionNames($nodeBundleId) {
    return implode(
      ", ",
      array_keys($this->layoutUpdater->getLockConfigs($nodeBundleId))
    );
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Handle form submission if needed in future.
  }

  /**
   * Form submission handler for updating all node locks.
   *
   * This task should be completed as part of a deploy hook any time the node
   * locks are altered for default section. But this button may be a useful tool
   * if encountering bugs.
   */
  public function submitUpdateLocks() {
    $this->layoutUpdater->updateAllLocks();
  }

  /**
   * Custom submit for updating text formats on existing blocks.
   *
   * @param array $form
   *   The form array.
   * @param Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitUpdateTextFormats(array &$form, FormStateInterface $form_state) {
    $blockType = $form_state->getValue('block_type');
    $fieldName = $form_state->getValue('field_name');
    $this->layoutUpdater->updateTextFormats($blockType, $fieldName);
  }

  /**
   * Ajax callback to return only view modes for the specified block type.
   */
  public function updateBlockFields(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    $blockType = $form_state->getValue('block_type');
    $fields = $this->layoutUpdater->getTextBlockFields($blockType);
    if ($fields) {
      $form['update_text_formats']['field_name']['#options'] = $fields;

      $select_element_rendered = $this->renderer->render($form['update_text_formats']['field_name']);

      $response->addCommand(new HtmlCommand('#edit-field-name-wrapper', $select_element_rendered));
    }
    return $response;
  }

}
