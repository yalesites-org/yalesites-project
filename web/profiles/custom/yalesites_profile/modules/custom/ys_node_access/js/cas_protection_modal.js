/**
 * @file
 * CAS Protection Modal functionality.
 */

(function ($, Drupal) {

  'use strict';

  /**
   * CAS Protection Modal behavior.
   */
  Drupal.behaviors.casProtectionModal = {
    attach: function (context, settings) {
      // Find the CAS protection field.
      var $casField = $('[name="field_login_required[value]"]', context);
      
      if ($casField.length) {
        // Store initial state.
        $casField.each(function() {
          var $this = $(this);
          var initialState = $this.is(':checked');
          $this.data('original-state', initialState);
        });
        
        // Use event delegation to catch changes.
        if (!$(document).data('cas-protection-modal-attached')) {
          $(document).data('cas-protection-modal-attached', true);
          $(document).on('change.casProtectionModal', '[name="field_login_required[value]"]', function (event) {
          var $checkbox = $(this);
          var isChecked = $checkbox.is(':checked');
          var originalState = $checkbox.data('original-state');
          
          // Only show modal when enabling CAS protection (changing from false to true).
          if (originalState === false && isChecked === true) {
            // Revert the change temporarily to show confirmation modal.
            $checkbox.prop('checked', originalState);
            
            // Show confirmation modal.
            Drupal.casProtectionModal.showConfirmation(isChecked, $checkbox);
          } else if (originalState !== isChecked) {
            // For disabling, just update the stored state without modal.
            $checkbox.data('original-state', isChecked);
          }
        });
        }
      }
    }
  };

  /**
   * CAS Protection Modal namespace.
   */
  Drupal.casProtectionModal = Drupal.casProtectionModal || {};

  /**
   * Current checkbox element being processed.
   */
  Drupal.casProtectionModal.currentCheckbox = null;

  /**
   * Current target state (enabling/disabling).
   */
  Drupal.casProtectionModal.targetState = null;

  /**
   * Shows the confirmation modal.
   *
   * @param {boolean} enabling
   *   Whether CAS protection is being enabled.
   * @param {jQuery} $checkbox
   *   The checkbox element.
   */
  Drupal.casProtectionModal.showConfirmation = function (enabling, $checkbox) {
    // Store current context.
    this.currentCheckbox = $checkbox;
    this.targetState = enabling;
    
    var action = enabling ? Drupal.t('enable') : Drupal.t('disable');
    var actionCap = enabling ? Drupal.t('Enable') : Drupal.t('Disable');
    
    // Create modal content.
    var content = [
      '<div class="cas-protection-modal-content" role="dialog" aria-labelledby="cas-modal-title" aria-describedby="cas-modal-description">',
      '<h2 id="cas-modal-title" class="modal-title">' + Drupal.t('Confirm @action CAS protection', {'@action': action}) + '</h2>',
      '<div class="security-warning" role="alert">',
      '<strong>' + Drupal.t('YaleSites is for low-risk data only. Do not store sensitive information.') + '</strong>',
      '</div>',
      '<p id="cas-modal-description">',
      enabling 
        ? Drupal.t('Are you sure you want to enable CAS protection for this page?')
        : Drupal.t('Are you sure you want to disable CAS protection for this page?'),
      '</p>',
      '</div>'
    ].join('');

    // Create and show dialog.
    var $dialog = $(content);
    var dialog = Drupal.dialog($dialog, {
      title: Drupal.t('CAS Protection Confirmation'),
      dialogClass: 'cas-protection-modal',
      resizable: false,
      closeOnEscape: true,
      width: 500,
      height: 'auto',
      modal: true,
      buttons: [
        {
          text: Drupal.t('Cancel'),
          class: 'button button--secondary',
          click: function () {
            Drupal.casProtectionModal.cancel();
          }
        },
        {
          text: actionCap,
          class: 'button button--primary',
          click: function () {
            Drupal.casProtectionModal.confirm();
          }
        }
      ]
    });

    dialog.showModal();
    
    // Set focus to the first button for accessibility.
    setTimeout(function() {
      $dialog.parent().find('.button--secondary').focus();
    }, 100);
  };

  /**
   * Handles modal confirmation.
   */
  Drupal.casProtectionModal.confirm = function () {
    if (this.currentCheckbox && this.targetState !== null) {
      // Apply the change.
      this.currentCheckbox.prop('checked', this.targetState);
      
      // Update original state to prevent re-triggering.
      this.currentCheckbox.data('original-state', this.targetState);
      
      // Show success message.
      var message = this.targetState 
        ? Drupal.t('CAS protection has been enabled for this page.')
        : Drupal.t('CAS protection has been disabled for this page.');
      
      // Add message if messenger is available.
      if (Drupal.messenger) {
        Drupal.messenger().addMessage(message, 'status');
      }
    }
    
    // Close dialog and reset state.
    this.closeDialog();
  };

  /**
   * Handles modal cancellation.
   */
  Drupal.casProtectionModal.cancel = function () {
    // Just close the dialog without making changes.
    this.closeDialog();
  };

  /**
   * Closes the dialog and resets state.
   */
  Drupal.casProtectionModal.closeDialog = function () {
    // Find and close any open cas protection modal.
    $('.ui-dialog').each(function() {
      var $dialog = $(this);
      if ($dialog.find('.cas-protection-modal-content').length) {
        var dialog = $dialog.find('.cas-protection-modal-content').data('dialog');
        if (dialog && dialog.close) {
          dialog.close();
        } else {
          // Fallback - remove the dialog manually.
          $dialog.remove();
          $('.ui-widget-overlay').remove();
        }
      }
    });
    
    // Reset state.
    this.currentCheckbox = null;
    this.targetState = null;
  };

  /**
   * Keyboard event handler for accessibility.
   */
  $(document).on('keydown', '.cas-protection-modal', function(event) {
    // Handle Escape key.
    if (event.which === 27) {
      Drupal.casProtectionModal.cancel();
      event.preventDefault();
    }
    
    // Trap focus within modal.
    var $modal = $(this);
    var $focusableElements = $modal.find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    var $firstElement = $focusableElements.first();
    var $lastElement = $focusableElements.last();
    
    if (event.which === 9) { // Tab key
      if (event.shiftKey) {
        // Shift+Tab - move to previous element
        if ($(event.target).is($firstElement)) {
          $lastElement.focus();
          event.preventDefault();
        }
      } else {
        // Tab - move to next element
        if ($(event.target).is($lastElement)) {
          $firstElement.focus();
          event.preventDefault();
        }
      }
    }
  });

})(jQuery, Drupal);