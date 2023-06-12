<?php

namespace Drupal\ys_embed\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
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
   * The embed source plugin manager service.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSourceManager
   */
  protected $embedManager;

  /**
   * Constructs a EmbedDefaultWidget object.
   *
   * @param string $plugin_id
   *   The plugin_id for the widget.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the widget is associated.
   * @param array $settings
   *   The widget settings.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\ys_embed\Plugin\EmbedSourceManager $embed_manager
   *   The EmbedSource management service.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    EmbedSourceManager $embed_manager
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $third_party_settings
    );
    $this->embedManager = $embed_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
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

    // Title is used within iframes for accessibility. Also for media library.
    $element['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $items[$delta]->title,
      '#description' => $this->t('Describe the embedded content. Used in accessibility markup.'),
      '#size' => 80,
      '#maxlength' => 1024,
      '#required' => !empty($element['#required']),
    ];

    // Input field is used to capture the raw user input for the embed code.
    $element['input'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Embed Code or URL'),
      '#default_value' => $items[$delta]->input ?? NULL,
      '#size' => 80,
      '#rows' => 2,
      '#required' => !empty($element['#required']),
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

    // Attach the library for pop-up dialogs/modals.
    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      $source = $this->embedManager->loadPluginByCode($value['input']);
      // Store the id of the EmbedSource plugin in the database.
      $value['embed_source'] = $source->getPluginId();
      // Store all named regex groups in a serialized database field.
      $value['params'] = $source->getParams($value['input']);
    }
    return $values;
  }

}
