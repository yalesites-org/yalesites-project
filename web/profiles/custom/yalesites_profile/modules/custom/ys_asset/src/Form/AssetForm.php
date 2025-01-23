<?php

namespace Drupal\ys_asset\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for the ys_asset entity edit forms.
 *
 * @ingroup ys_asset
 */
class AssetForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\ys_asset\Entity\Asset $entity */
    $form = parent::buildForm($form, $form_state);
    $entity = $this->entity;

    $form['langcode'] = [
      '#title' => $this->t('Language'),
      '#type' => 'language_select',
      '#default_value' => $entity->getUntranslated()->language()->getId(),
      '#languages' => LanguageInterface::STATE_ALL,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $status = parent::save($form, $form_state);

    $entity = $this->entity;
    if ($status == SAVED_UPDATED) {
      $this->messenger()
        ->addMessage($this->t('The contact %feed has been updated.', ['%feed' => $entity->toLink()->toString()]));
    }
    else {
      $this->messenger()
        ->addMessage($this->t('The contact %feed has been added.', ['%feed' => $entity->toLink()->toString()]));
    }

    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

}
