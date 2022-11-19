<?php

namespace Drupal\ys_embed\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Url;
use Drupal\ys_embed\Plugin\EmbedSourceManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'embed_default' widget.
 *
 * @FieldWidget(
 *   id = "embed_default",
 *   label = @Translation("Embed Default Widget"),
 *   field_types = {"embed"}
 * )
 */
class EmbedDefaultWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  /**
   * Drupal\ys_embed\Plugin\EmbedSourceManager definition.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSourceManager
   */
  protected $embedManager;

  /**
   * {@inheritDoc}
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, EmbedSourceManager $embed_manager) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->embedManager = $embed_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('plugin.manager.embed_source')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {

    // An instance of the plugin is available on load for existing content.
    $plugin = $this->embedManager->loadPluginByCode($items[$delta]->input);

    // Input field is used to capture the raw user input for the embed code.
    $element['input'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Embed Code or URL'),
      '#default_value' => $items[$delta]->input ?? NULL,
      '#size' => 80,
      '#required' => !empty($element['#required']),
      // '#ajax' => [
      //   'callback' => [$this, 'refreshSettingsForm'],
      //   'disable-refocus' => FALSE,
      //   'event' => 'input',
      //   'wrapper' => 'edit-settings',
      // ],
    ];

    // Help text opens a model window with instructions and embed code examples.
    $element['input']['#description'] = [
      '#type' => 'link',
      '#title' => $this->t('Learn about supported formats and options'),
      '#url' => Url::fromRoute('ys_embed.instructions'),
      '#attributes' => [
        'class' => [
          'use-ajax',
        ],
      ],
    ];

    // Field settings can be added to the settings container.
    $form['settings'] = [
      '#type' => 'container',
      '#prefix' => '<div id="edit-settings">',
      '#suffix' => '</div>',
    ];

    // Title field is hidden by default and shown via AJAX callback.
    $form['settings']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $items[$delta]->title,
      '#description' => 'The title attribute for the embedded content, used for accessibility markup.',
      '#size' => 80,
      '#maxlength' => 1024,
      '#required' => !empty($element['#required']),
      // '#access' => $plugin->requireTitle(),
    ];

    // Attach the library for pop-up dialogs/modals.
    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';

    return $element;
  }

  /**
   * AJAX callback for updating the settings portion of the widget form.
   *
   * @param array $form
   *   The form structure where field elements are attached to.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form elements to be refreshed via AJAX callback.
   */
  // public function refreshSettingsForm(array &$form, FormStateInterface $form_state) {
  //   // Find the EmbedPlugin that matches this input string or exit early.
  //   $input = $form_state->getTriggeringElement()['#value'];
  //   // Show the title field if it is required in the plugin annotation.
  //   $plugin = $this->embedManager->loadPluginByCode($input);
  //   $form['settings']['title']['#access'] = $plugin->requireTitle();
  //   $form['settings']['title']['#value'] = $form_state->getUserInput()['title'];
  //   return $form['settings'];
  // }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      // Store the field values in the database.
      $source = $this->embedManager->loadPluginByCode($value['input']);
      $value['embed_source'] = $source->getPluginId();
      $value['title'] = $form_state->getValue('title');
      $value['params'] = $source->getParams($value['input']);
    }
    return $values;
  }

}
