<?php

namespace Drupal\ys_views_wizard_spike\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\OpenDialogCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormBase;
use Drupal\layout_builder\Form\AddBlockForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\layout_builder\SectionStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Prototype B - the two picker questions, then an AJAX swap in place.
 *
 * SPIKE ONLY. Continue is an AJAX submit whose callback builds core's
 * AddBlockForm for the resolved plugin and re-opens the SAME dialog with it,
 * so the editor never leaves #layout-builder-modal. This is approach B in
 * issue #1586. Prototype A (plain redirect out to the standalone configure
 * page) is on the 1586-views-wizard-redirect branch; everything except the
 * handoff is identical between the two, deliberately, so the comparison is
 * about the handoff and nothing else.
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
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->options = $container->get('ys_views_wizard_spike.options');
    $instance->formBuilder = $container->get('form_builder');
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
      // The whole point of prototype B. Contrib's
      // layout_builder_browser_form_alter() strips #ajax from
      // layout_builder_add_block's submit, but it whitelists by form ID, so
      // the wizard's own submit keeps its #ajax.
      '#ajax' => ['callback' => '::openConfigureForm'],
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
   * AJAX callback: swap the wizard for the real configure form, in place.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response.
   */
  public function openConfigureForm(array &$form, FormStateInterface $form_state): AjaxResponse {
    /** @var \Drupal\layout_builder\SectionStorageInterface $section_storage */
    $section_storage = $form_state->get('section_storage');
    $delta = $form_state->get('delta');
    $region = $form_state->get('region');
    $plugin_id = $this->options->resolvePluginId(
      $form_state->getValue('entity_types'),
      $form_state->getValue('view_mode')
    );

    // No seeding, and no ?ys_wizard_seed query parameter. On the views rework
    // branch every (content type, display mode) pair has its own bundle, and
    // the bundle IS the answer to both questions - the widget reads the view
    // mode straight off ViewsBasicManager::LISTING_BUNDLES.
    $configure_form = $this->formBuilder->getForm(AddBlockForm::class, $section_storage, $delta, $region, $plugin_id);

    // Load bearing. FormBuilder defaults #action to the current request URI,
    // which here is the WIZARD's route - without this the embedded form would
    // POST back to a route that builds a different form.
    //
    // It still does not fix the embedded form's own #ajax URLs, which are
    // derived from the request the same way. On develop that sank approach B.
    // On the views rework branch it does not bite: the listing bundles'
    // configure forms have zero #ajax bindings, because the per-bundle widget
    // dropped the content-type / display-mode radios that carried them. See
    // the README for the measurement.
    $configure_form['#action'] = Url::fromRoute('layout_builder.add_block', [
      'section_storage_type' => $section_storage->getStorageType(),
      'section_storage' => $section_storage->getStorageId(),
      'delta' => $delta,
      'region' => $region,
      'plugin_id' => $plugin_id,
    ])->toString();

    $response = new AjaxResponse();
    $response->addCommand(new OpenDialogCommand('#layout-builder-modal', $this->t('Configure block'), $configure_form, [
      'width' => '80%',
      'height' => 'auto',
      'modal' => TRUE,
      'autoResize' => TRUE,
    ]));
    return $response;
  }

  /**
   * {@inheritdoc}
   *
   * The display mode radios carry #validated so the AJAX-swapped options are
   * accepted, which means core does not check the submitted value against the
   * option list. Check that the pair resolves to a real listing bundle here.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $resolved = $this->options->resolvePluginId(
      $form_state->getValue('entity_types'),
      $form_state->getValue('view_mode')
    );
    if ($resolved === NULL) {
      $form_state->setErrorByName('view_mode', $this->t('That combination has no listing block.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Required, and non-obvious. Without setRebuild() a plain FormBase issues
    // a redirect to the current URL after submission, and Drupal returns that
    // redirect instead of ever invoking the #ajax callback - the POST comes
    // back 200 and the dialog silently does nothing. Everything else happens
    // in openConfigureForm(). A non-JS fallback would need prototype A's
    // redirect here; noting that gap rather than building it is deliberate
    // for a throwaway.
    $form_state->setRebuild(TRUE);
  }

}
