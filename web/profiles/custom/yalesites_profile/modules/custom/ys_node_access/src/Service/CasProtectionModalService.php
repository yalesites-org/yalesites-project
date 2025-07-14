<?php

namespace Drupal\ys_node_access\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for handling CAS protection confirmation modal functionality.
 */
class CasProtectionModalService {

  use StringTranslationTrait;

  /**
   * Gets the modal content for CAS protection confirmation.
   *
   * @param bool $enabling
   *   TRUE if enabling CAS protection, FALSE if disabling.
   *
   * @return string
   *   Modal content string for testing purposes.
   */
  public function getModalContent($enabling = TRUE) {
    $content = $this->t('YaleSites is for low-risk data only. Do not store sensitive information. This content should not be published to Yale sites if it contains sensitive information. Are you sure you want to change the login requirements for this Yale page?');
    return (string) $content;
  }

  /**
   * Gets the modal content as renderable array.
   *
   * @param bool $enabling
   *   TRUE if enabling CAS protection, FALSE if disabling.
   *
   * @return array
   *   Renderable array for modal content.
   */
  public function getModalContentRenderable($enabling = TRUE) {
    $action = $enabling ? $this->t('enable') : $this->t('disable');
    $title = $this->t('Confirm @action CAS protection', ['@action' => $action]);

    $content = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cas-protection-modal-content']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $title,
        '#attributes' => ['class' => ['modal-title']],
      ],
      'warning' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->t('YaleSites is for low-risk data only. Do not store sensitive information.'),
        '#attributes' => ['class' => ['security-warning'], 'role' => 'alert'],
      ],
      'description' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $enabling
          ? $this->t('Are you sure you want to enable CAS protection for this page?')
          : $this->t('Are you sure you want to disable CAS protection for this page?'),
      ],
    ];

    return $content;
  }

  /**
   * Gets the modal settings for CAS protection confirmation.
   *
   * @param bool $enabling
   *   TRUE if enabling CAS protection, FALSE if disabling.
   *
   * @return array
   *   Modal settings array.
   */
  public function getModalSettings($enabling = TRUE) {
    $action = $enabling ? $this->t('Enable') : $this->t('Disable');

    return [
      'dialogClass' => 'cas-protection-modal',
      'resizable' => FALSE,
      'closeOnEscape' => TRUE,
      'width' => 500,
      'height' => 'auto',
      'modal' => TRUE,
      'title' => $this->t('CAS Protection Confirmation'),
      'buttons' => [
        [
          'text' => $this->t('Cancel'),
          'class' => 'button button--secondary',
          'click' => 'Drupal.casProtectionModal.cancel',
        ],
        [
          'text' => $action,
          'class' => 'button button--primary',
          'click' => 'Drupal.casProtectionModal.confirm',
        ],
      ],
    ];
  }

  /**
   * Generates the modal HTML structure.
   *
   * @param bool $enabling
   *   TRUE if enabling CAS protection, FALSE if disabling.
   *
   * @return string
   *   Modal HTML markup.
   */
  public function generateModalHtml($enabling = TRUE) {
    $content = $this->getModalContent($enabling);
    return \Drupal::service('renderer')->render($content);
  }

  /**
   * Attaches the required libraries for the modal.
   *
   * @return array
   *   Libraries attachment array.
   */
  public function getLibraryAttachments() {
    return [
      'library' => [
        'ys_node_access/cas_protection_modal',
        'core/drupal.dialog.ajax',
      ],
    ];
  }

  /**
   * Validates if the modal should be shown.
   *
   * @param bool $old_value
   *   The previous field value.
   * @param bool $new_value
   *   The new field value.
   *
   * @return bool
   *   TRUE if modal should be shown, FALSE otherwise.
   */
  public function shouldShowModal($old_value, $new_value) {
    // Show modal only when there's an actual change.
    return $old_value !== $new_value;
  }

  /**
   * Gets the confirmation message after successful action.
   *
   * @param bool $enabled
   *   TRUE if CAS protection was enabled, FALSE if disabled.
   *
   * @return string
   *   Confirmation message.
   */
  public function getConfirmationMessage($enabled) {
    if ($enabled) {
      return $this->t('CAS protection has been enabled for this page.');
    }
    return $this->t('CAS protection has been disabled for this page.');
  }

  /**
   * Gets modal buttons configuration.
   *
   * @return array
   *   Array of button configurations.
   */
  public function getModalButtons() {
    return [
      [
        'text' => (string) $this->t('Cancel'),
        'class' => 'button button--secondary',
        'click' => 'Drupal.casProtectionModal.cancel',
      ],
      [
        'text' => (string) $this->t('Confirm'),
        'class' => 'button button--primary',
        'click' => 'Drupal.casProtectionModal.confirm',
      ],
    ];
  }

  /**
   * Gets modal configuration options.
   *
   * @return array
   *   Modal configuration array.
   */
  public function getModalConfig() {
    return [
      'title' => (string) $this->t('CAS Protection Confirmation'),
      'width' => 600,
      'resizable' => TRUE,
      'closeOnEscape' => FALSE,
      'dialogClass' => 'cas-protection-confirm-dialog',
    ];
  }

  /**
   * Gets accessibility attributes for the modal.
   *
   * @return array
   *   Accessibility attributes array.
   */
  public function getModalAccessibilityAttributes() {
    return [
      'role' => 'dialog',
      'aria-label' => (string) $this->t('CAS Protection Confirmation'),
      'aria-modal' => TRUE,
      'aria-describedby' => 'cas-protection-description',
    ];
  }

}
