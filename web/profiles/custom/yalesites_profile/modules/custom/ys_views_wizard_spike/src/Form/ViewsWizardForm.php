<?php

namespace Drupal\ys_views_wizard_spike\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\layout_builder\SectionStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Prototype A - the two picker questions, then a redirect into add_block.
 *
 * SPIKE ONLY. Continue is a plain (non-AJAX) submit that sets a redirect to
 * layout_builder.add_block, which is the literal reading of approach A in
 * issue #1586. The cost of that literal reading - the editor leaves the
 * Layout Builder modal and lands on the standalone Configure block page - is
 * the thing this prototype exists to measure. Prototype B keeps the editor in
 * the dialog and is on the spike/views-wizard-ajax branch.
 */
class ViewsWizardForm extends FormBase {

  /**
   * Wrapper ID for the AJAX-replaced display mode radios.
   */
  const VIEW_MODE_WRAPPER = 'ys-views-wizard-view-mode';

  /**
   * The wizard options resolver.
   *
   * @var \Drupal\ys_views_wizard_spike\ViewsWizardOptions
   */
  protected $options;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->options = $container->get('ys_views_wizard_spike.options');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_views_wizard_spike_choose';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage, upcast from the route.
   * @param int $delta
   *   The section delta.
   * @param string $region
   *   The region machine name.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SectionStorageInterface $section_storage = NULL, $delta = NULL, $region = NULL) {
    // The route gives $delta as a string; every Layout Builder consumer wants
    // an int, so normalise once here rather than at each call site.
    $delta = (int) $delta;
    $form_state->set('section_storage', $section_storage);
    $form_state->set('delta', $delta);
    $form_state->set('region', $region);

    $content_types = $this->options->getContentTypeOptions($section_storage, $delta, $region);
    if (!$content_types) {
      $form['empty'] = [
        '#markup' => $this->t('No listing blocks can be placed in this region.'),
      ];
      return $form;
    }

    $selected_type = $form_state->getValue('entity_types') ?: array_key_first($content_types);
    $view_modes = $this->options->getViewModeOptions($selected_type, $section_storage, $delta, $region);

    // The icon-card styling lives in ys_views_basic's library. It only takes
    // effect because the spike also opts this route and form in to gin_lb -
    // see GinLbContextValidator. Without that opt-in the icons render but the
    // cards do not, because every layout rule in views-basic.css is written
    // against gin_lb's .glb-* / .fieldset__wrapper--group DOM.
    $form['#attached']['library'][] = 'ys_views_basic/ys_views_basic';
    $form['wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['views-basic--group-user-selection']],
    ];
    $form['wrapper']['choices'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['grouped-items', 'views-basic--entity-view-mode'],
      ],
    ];

    // Deliberately the same labels, icons and question wording as
    // ViewsBasicDefaultWidget, sourced from the same ViewsBasicManager
    // accessors, so this step introduces no new terminology.
    $form['wrapper']['choices']['entity_types'] = [
      '#type' => 'radios',
      '#title' => $this->t('I Want To Show'),
      '#options' => $content_types,
      '#default_value' => $selected_type,
      '#required' => TRUE,
      '#wrapper_attributes' => [
        'class' => ['views-basic--user-selection', 'views-basic--entity-types'],
      ],
      '#ajax' => [
        'callback' => '::updateViewModes',
        'event' => 'change',
        'disable-refocus' => FALSE,
        'progress' => ['type' => 'none'],
      ],
    ];

    $form['wrapper']['choices']['view_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('As'),
      '#options' => $view_modes,
      '#default_value' => array_key_first($view_modes),
      '#required' => TRUE,
      '#validated' => TRUE,
      '#attributes' => ['class' => ['views-basic--view-mode']],
      '#wrapper_attributes' => ['class' => ['views-basic--user-selection']],
      '#prefix' => '<div id="' . self::VIEW_MODE_WRAPPER . '">',
      '#suffix' => '</div>',
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Continue'),
      '#button_type' => 'primary',
    ];
    $form['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back'),
      '#url' => Url::fromRoute('layout_builder.choose_block', [
        'section_storage_type' => $section_storage->getStorageType(),
        'section_storage' => $section_storage->getStorageId(),
        'delta' => $delta,
        'region' => $region,
      ]),
      // 'use-ajax' is what makes Back replace the dialog contents rather than
      // navigate the window. layout_builder_browser_link_alter() then supplies
      // the modal target, because layout_builder.choose_block IS on its
      // whitelist - unlike the wizard's own route.
      '#attributes' => ['class' => ['button', 'use-ajax']],
    ];

    return $form;
  }

  /**
   * AJAX callback: refresh the display mode radios for the chosen type.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response.
   */
  public function updateViewModes(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#' . self::VIEW_MODE_WRAPPER, $form['wrapper']['choices']['view_mode']));
    // Match ViewsBasicDefaultWidget::updateOtherSettings() - select the first
    // display mode so the editor is never left with nothing checked.
    $response->addCommand(new InvokeCommand(':input[name="view_mode"]:first', 'prop', [['checked' => TRUE]]));
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\layout_builder\SectionStorageInterface $section_storage */
    $section_storage = $form_state->get('section_storage');
    $content_type = $form_state->getValue('entity_types');
    $view_mode = $form_state->getValue('view_mode');

    $options = [];
    if ($this->options->needsSeed($content_type, $view_mode)) {
      $options['query']['ys_wizard_seed'] = $content_type . ':' . $view_mode;
    }

    $form_state->setRedirect('layout_builder.add_block', [
      'section_storage_type' => $section_storage->getStorageType(),
      'section_storage' => $section_storage->getStorageId(),
      'delta' => $form_state->get('delta'),
      'region' => $form_state->get('region'),
      'plugin_id' => $this->options->resolvePluginId($content_type, $view_mode),
    ], $options);
  }

}
