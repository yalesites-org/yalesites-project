<?php

namespace Drupal\ys_embed\Form;

use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\media\MediaTypeInterface;
use Drupal\media_library\Form\AddFormBase;

/**
 * A form to create embed media entities from within Media Library.
 */
class EmbedMediaLibraryAddForm extends AddFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'media_entity_embed_media_library_add';
  }

  /**
   * {@inheritdoc}
   */
  protected function buildInputElement(array $form, FormStateInterface $form_state) {
    $form['container'] = [
      '#type' => 'container',
    ];

    // Input field is used to capture the raw user input for the embed code.
    $form['container']['input'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Embed Code or URL'),
      '#size' => 80,
      '#rows' => 2,
      '#required' => !empty($element['#required']),
    ];

    // Help text opens a model window with instructions and embed code examples.
    $form['container']['input']['#description'] = [
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

    $form['container']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add'),
      '#button_type' => 'primary',
      '#submit' => ['::addButtonSubmit'],
      '#ajax' => [
        'callback' => '::updateFormCallback',
        'wrapper' => 'media-library-wrapper',
        'url' => Url::fromRoute('media_library.ui'),
        'options' => [
          'query' => $this->getMediaLibraryState($form_state)->all() + [
            FormBuilderInterface::AJAX_FORM_REQUEST => TRUE,
          ],
          'attributes' => [
            'class' => ['media-library-add-form-submit'],
          ],
        ],
      ],
    ];

    // Add error handling for AJAX submissions.
    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
    $form['#attached']['library'][] = 'core/jquery.form';

    return $form;
  }

  /**
   * Submit handler for the add button.
   *
   * @param array $form
   *   The form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function addButtonSubmit(array $form, FormStateInterface $form_state) {
    try {
      $this->processInputValues(
        [$form_state->getValue('input')],
        $form,
        $form_state
      );
    }
    catch (\Exception $e) {
      $form_state->setError($form['container']['input'], $this->t('Error processing embed code: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateFormCallback(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    if ($triggering_element['#name'] === 'op' && $triggering_element['#value'] === $this->t('Add')) {
      // If there are no errors, close the dialog.
      if (!$form_state->getErrors()) {
        $form_state->setRedirect('<none>');
      }
    }
    return $form;
  }

  /**
   * Returns the definition of the source field for a media type.
   *
   * @param \Drupal\media\MediaTypeInterface $media_type
   *   The media type to get the source definition for.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface|null
   *   The field definition.
   */
  protected function getSourceFieldDefinition(MediaTypeInterface $media_type) {
    return $media_type->getSource()->getSourceFieldDefinition($media_type);
  }

}
