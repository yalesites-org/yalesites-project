/**
 * @file
 * CAS Protection Modal functionality.
 *
 * Provides a confirmation modal when users attempt to enable CAS protection
 * on content. The modal displays security warnings and requires explicit
 * confirmation before applying the change.
 *
 * Features:
 * - Configuration-driven content and styling
 * - Accessibility compliant with ARIA attributes and focus management
 * - Only shows when enabling CAS protection (not when disabling)
 * - Returns focus to original checkbox after modal closes
 */

(function ($, Drupal) {
  /**
   * Configuration object for easy customization of modal content and behavior.
   *
   * CUSTOMIZE THIS SECTION to change modal text, titles, and settings.
   * Note: This modal only appears when ENABLING CAS protection, not when disabling.
   */
  const modalConfig = {
    // Modal content and titles
    content: {
      title: Drupal.t("⚠️ Warning: YaleSites is NOT secure for sensitive data"),

      // Body content array - supports unlimited paragraphs and different content types
      // String items become paragraphs, objects can be links or other content types
      bodyContent: [
        Drupal.t(
          "Never upload personal information, student records, research data, or confidential documents to YaleSites. All content is stored on the public internet."
        ),
        Drupal.t(
          "CAS protection only limits who can view content - it does not make this platform secure for sensitive data."
        ),
        {
          type: "link",
          text: Drupal.t("Learn more about Yale's Data Classification Policy"),
          url: "https://your.yale.edu/policies-procedures/policies/1604-data-classification-policy#1604.1",
        },
      ],
      confirmationText: Drupal.t(""),
      successMessage: Drupal.t(
        "CAS protection has been enabled for this page."
      ),
    },

    // Modal dialog settings
    dialog: {
      title: Drupal.t("CAS Protection Confirmation"),
      dialogClass: "cas-protection-modal",
      width: 500,
      height: "auto",
      modal: true,
      resizable: false,
      closeOnEscape: true,
    },

    // Button configurations
    buttons: {
      cancel: {
        text: Drupal.t("Cancel"),
        class: "button button--secondary",
      },
      confirm: {
        text: Drupal.t("I understand - Enable CAS Protection"),
        class: "button button--primary",
      },
    },

    // CSS classes and IDs for styling and accessibility
    classes: {
      modalContent: "cas-protection-modal-content",
      modalTitle: "modal-title",
    },

    // Accessibility settings
    accessibility: {
      titleId: "cas-modal-title",
      descriptionId: "cas-modal-description",
      focusDelay: 100,
    },
  };

  // =============================================================================
  // DRUPAL BEHAVIOR INTEGRATION
  // =============================================================================

  /**
   * Drupal behavior for CAS Protection Modal functionality.
   *
   * This behavior attaches event listeners to CAS protection checkboxes
   * and shows a confirmation modal when users attempt to enable CAS protection.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.casProtectionModal = {
    /**
     * Attaches the CAS protection modal behavior to the page.
     *
     * @param {HTMLElement} context
     *   The context within which to search for CAS protection fields.
     * @param {object} settings
     *   Drupal settings object.
     */
    attach(context) {
      const $casField = $('[name="field_login_required[value]"]', context);

      if ($casField.length) {
        this.initializeCheckboxStates($casField);
        this.attachChangeHandler();
      }
    },

    /**
     * Stores the initial state of each CAS protection checkbox.
     *
     * @param {jQuery} $casField
     *   The CAS protection checkbox elements.
     */
    initializeCheckboxStates($casField) {
      $casField.each(function () {
        const $checkbox = $(this);
        const initialState = $checkbox.is(":checked");
        $checkbox.data("original-state", initialState);
      });
    },

    /**
     * Attaches change event handler to CAS protection checkboxes.
     */
    attachChangeHandler() {
      // Prevent multiple event handlers from being attached
      if ($(document).data("cas-protection-modal-attached")) {
        return;
      }

      $(document).data("cas-protection-modal-attached", true);
      $(document).on(
        "change.casProtectionModal",
        '[name="field_login_required[value]"]',
        this.handleCheckboxChange
      );
    },

    /**
     * Handles changes to the CAS protection checkbox.
     *
     * @param {Event} event
     *   The change event.
     */
    handleCheckboxChange() {
      const $checkbox = $(this);
      const isChecked = $checkbox.is(":checked");
      const originalState = $checkbox.data("original-state");

      if (originalState === false && isChecked === true) {
        // User is enabling CAS protection - show confirmation modal
        $checkbox.prop("checked", originalState); // Revert temporarily
        Drupal.casProtectionModal.showConfirmation(isChecked, $checkbox);
      } else if (originalState !== isChecked) {
        // User is disabling CAS protection - allow without modal
        $checkbox.data("original-state", isChecked);
      }
    },
  };

  // =============================================================================
  // NAMESPACE AND STATE VARIABLES
  // =============================================================================

  /**
   * CAS Protection Modal namespace.
   *
   * Contains all functions and state variables for managing the CAS protection
   * confirmation modal dialog.
   */
  Drupal.casProtectionModal = Drupal.casProtectionModal || {};

  /**
   * Current checkbox element being processed.
   * @type {jQuery|null}
   */
  Drupal.casProtectionModal.currentCheckbox = null;

  /**
   * Current target state (enabling/disabling CAS protection).
   * @type {boolean|null}
   */
  Drupal.casProtectionModal.targetState = null;

  /**
   * Original focused element for accessibility restoration.
   * @type {HTMLElement|null}
   */
  Drupal.casProtectionModal.originalFocusedElement = null;

  /**
   * Original checkbox jQuery object for reliable focus restoration.
   * @type {jQuery|null}
   */
  Drupal.casProtectionModal.originalCheckboxQuery = null;

  // =============================================================================
  // HELPER FUNCTIONS - State Management and Utilities
  // =============================================================================

  /**
   * Stores checkbox reference for later focus restoration.
   *
   * @param {jQuery} $checkbox
   *   The checkbox element to store.
   */
  Drupal.casProtectionModal.storeCheckboxReference = function ($checkbox) {
    this.currentCheckbox = $checkbox;
    [this.originalFocusedElement] = $checkbox;
    this.originalCheckboxQuery = $checkbox;
  };

  /**
   * Resets all stored state to clean values.
   */
  Drupal.casProtectionModal.resetState = function () {
    this.currentCheckbox = null;
    this.targetState = null;
    this.originalFocusedElement = null;
    this.originalCheckboxQuery = null;
  };

  /**
   * Displays success message to user.
   */
  Drupal.casProtectionModal.showSuccessMessage = function () {
    if (Drupal.messenger) {
      Drupal.messenger().addMessage(
        modalConfig.content.successMessage,
        "status"
      );
    }
  };

  /**
   * Scrolls element into view with smooth animation.
   *
   * @param {HTMLElement} element
   *   The element to scroll into view.
   */
  Drupal.casProtectionModal.scrollElementIntoView = function (element) {
    element.scrollIntoView({ behavior: "smooth", block: "center" });
  };

  /**
   * Attempts to focus an element with multiple fallback strategies.
   *
   * @param {jQuery} $element
   *   The jQuery element to focus.
   * @return {boolean}
   *   True if focus was successful, false otherwise.
   */
  Drupal.casProtectionModal.attemptElementFocus = function ($element) {
    if (!$element || !$element.length) {
      return false;
    }

    try {
      // First attempt: Direct focus
      $element[0].focus();
      if (document.activeElement === $element[0]) {
        return true;
      }

      // Second attempt: jQuery focus
      $element.focus();
      if (document.activeElement === $element[0]) {
        return true;
      }

      // Third attempt: Focus associated label
      const $label = $element.closest(".form-item").find("label").first();
      if ($label.length) {
        $label.attr("tabindex", "0").focus();
        return document.activeElement === $label[0];
      }

      // Final fallback: Focus by selector
      const $fallback = $('[name="field_login_required[value]"]');
      if ($fallback.length) {
        $fallback.focus();
        return document.activeElement === $fallback[0];
      }
    } catch (e) {
      // Silent error handling
    }

    return false;
  };

  // =============================================================================
  // DIALOG CREATION AND MANAGEMENT
  // =============================================================================

  /**
   * Creates dialog button configuration.
   *
   * @return {Array}
   *   Array of button configuration objects.
   */
  Drupal.casProtectionModal.createDialogButtons = function () {
    const config = modalConfig;

    return [
      {
        text: config.buttons.confirm.text,
        class: config.buttons.confirm.class,
        click() {
          Drupal.casProtectionModal.confirm();
        },
      },
      {
        text: config.buttons.cancel.text,
        class: config.buttons.cancel.class,
        click() {
          Drupal.casProtectionModal.cancel();
        },
      },
    ];
  };

  /**
   * Creates and configures the dialog instance.
   *
   * @param {jQuery} $dialogContent
   *   The dialog content element.
   * @return {Object}
   *   The Drupal dialog instance.
   */
  Drupal.casProtectionModal.createDialog = function ($dialogContent) {
    const config = modalConfig;

    return Drupal.dialog($dialogContent, {
      title: config.dialog.title,
      dialogClass: config.dialog.dialogClass,
      resizable: config.dialog.resizable,
      closeOnEscape: config.dialog.closeOnEscape,
      width: config.dialog.width,
      height: config.dialog.height,
      modal: config.dialog.modal,
      buttons: this.createDialogButtons(),
    });
  };

  /**
   * Sets initial focus on the dialog for accessibility.
   *
   * @param {jQuery} $dialogElement
   *   The dialog element.
   */
  Drupal.casProtectionModal.setInitialDialogFocus = function ($dialogElement) {
    setTimeout(function () {
      $dialogElement.parent().find(".button--secondary").focus();
    }, modalConfig.accessibility.focusDelay);
  };

  /**
   * Generates body content HTML from the flexible content array.
   *
   * @return {string}
   *   The body content HTML.
   */
  Drupal.casProtectionModal.generateBodyContent = function () {
    const config = modalConfig;
    let bodyHtml = "";

    config.content.bodyContent.forEach(function (item) {
      if (typeof item === "string") {
        // Regular paragraph
        bodyHtml += `<p>${item}</p>`;
      } else if (item.type === "link") {
        // Link paragraph
        bodyHtml += `<p><a href="${item.url}" target="_blank" rel="noopener">${item.text}</a></p>`;
      }
      // Future: could easily add support for other content types like lists, warnings, etc.
    });

    return bodyHtml;
  };

  /**
   * Generates modal content HTML using the configuration object.
   * Note: This modal only appears when enabling CAS protection.
   *
   * @return {string}
   *   The modal content HTML.
   */
  Drupal.casProtectionModal.generateModalContent = function () {
    const config = modalConfig;
    const bodyContent = this.generateBodyContent();

    return [
      `<div class="${config.classes.modalContent}" aria-labelledby="${config.accessibility.titleId}" aria-describedby="${config.accessibility.descriptionId}">`,
      `<h2 id="${config.accessibility.titleId}" class="${config.classes.modalTitle}">${config.content.title}</h2>`,
      bodyContent,
      `<p id="${config.accessibility.descriptionId}">`,
      config.content.confirmationText,
      "</p>",
      "</div>",
    ].join("");
  };

  // =============================================================================
  // MAIN MODAL FUNCTIONS
  // =============================================================================

  /**
   * Shows the confirmation modal for enabling CAS protection.
   *
   * @param {boolean} enabling
   *   Whether CAS protection is being enabled (always true for this modal).
   * @param {jQuery} $checkbox
   *   The checkbox element that triggered the modal.
   */
  Drupal.casProtectionModal.showConfirmation = function (enabling, $checkbox) {
    // Store checkbox reference and state for later use
    this.storeCheckboxReference($checkbox);
    this.targetState = enabling;

    // Generate and display the modal
    const modalContent = this.generateModalContent();
    const $dialog = $(modalContent);
    const dialog = this.createDialog($dialog);

    dialog.showModal();
    this.setInitialDialogFocus($dialog);
  };

  /**
   * Applies the CAS protection change to the checkbox.
   */
  Drupal.casProtectionModal.applyCasProtectionChange = function () {
    if (this.currentCheckbox && this.targetState !== null) {
      // Enable CAS protection on the checkbox
      this.currentCheckbox.prop("checked", this.targetState);

      // Update stored state to prevent re-triggering the modal
      this.currentCheckbox.data("original-state", this.targetState);
    }
  };

  /**
   * Handles modal confirmation for enabling CAS protection.
   */
  Drupal.casProtectionModal.confirm = function () {
    this.applyCasProtectionChange();
    this.showSuccessMessage();
    this.closeDialog();
  };

  /**
   * Handles modal cancellation.
   */
  Drupal.casProtectionModal.cancel = function () {
    this.closeDialog();
  };

  // =============================================================================
  // FOCUS MANAGEMENT
  // =============================================================================

  /**
   * Restores focus to the original checkbox element.
   *
   * @param {jQuery} $checkboxToFocus
   *   The checkbox element to focus.
   */
  Drupal.casProtectionModal.restoreFocus = function ($checkboxToFocus) {
    const self = this;

    if (!$checkboxToFocus || !$checkboxToFocus.length) {
      return;
    }

    setTimeout(function () {
      // Scroll checkbox into view and attempt focus
      self.scrollElementIntoView($checkboxToFocus[0]);

      setTimeout(function () {
        self.attemptElementFocus($checkboxToFocus);
      }, 100);
    }, modalConfig.accessibility.focusDelay);
  };

  /**
   * Closes any open CAS protection modal dialogs.
   */
  Drupal.casProtectionModal.closeModalDialogs = function () {
    $(".ui-dialog").each(function () {
      const $dialog = $(this);
      if ($dialog.find(".cas-protection-modal-content").length) {
        const dialog = $dialog
          .find(".cas-protection-modal-content")
          .data("dialog");
        if (dialog && dialog.close) {
          dialog.close();
        } else {
          // Fallback for manual cleanup
          $dialog.remove();
          $(".ui-widget-overlay").remove();
        }
      }
    });

    // Ensure body scroll is fully restored after modal cleanup
    this.restoreBodyScroll();
  };

  /**
   * Restores body scroll functionality with multiple fallback mechanisms.
   */
  Drupal.casProtectionModal.restoreBodyScroll = function () {
    // Method 1: Use Drupal's body scroll lock API if available
    if (
      typeof bodyScrollLock !== "undefined" &&
      bodyScrollLock.clearBodyLocks
    ) {
      try {
        bodyScrollLock.clearBodyLocks();
      } catch (e) {
        // Silent fallback if bodyScrollLock fails
      }
    }

    // Method 2: Remove any overflow restrictions on body and html
    const $body = $("body");
    const $html = $("html");

    $body.css({
      overflow: "",
      "overflow-x": "",
      "overflow-y": "",
      position: "",
      height: "",
      width: "",
    });

    $html.css({
      overflow: "",
      "overflow-x": "",
      "overflow-y": "",
      position: "",
      height: "",
      width: "",
    });

    // Method 3: Remove any data attributes that might affect scrolling
    $body.removeAttr("data-scroll-locked");
    $html.removeAttr("data-scroll-locked");

    // Method 4: Timeout-based cleanup as final fallback
    setTimeout(function () {
      $body.css("overflow", "");
      $html.css("overflow", "");

      // Force a reflow to ensure styles are applied
      // eslint-disable-next-line no-unused-expressions
      document.body.offsetHeight;
    }, 50);
  };

  /**
   * Closes the dialog and resets state.
   */
  Drupal.casProtectionModal.closeDialog = function () {
    // Store checkbox reference before resetting state
    const $checkboxToFocus = this.originalCheckboxQuery;

    // Close modal dialogs
    this.closeModalDialogs();

    // Restore focus to original checkbox
    this.restoreFocus($checkboxToFocus);

    // Clean up stored state
    this.resetState();
  };

  /**
   * Keyboard event handler for accessibility.
   */
  $(document).on("keydown", ".cas-protection-modal", function (event) {
    // Handle Escape key.
    if (event.which === 27) {
      Drupal.casProtectionModal.cancel();
      event.preventDefault();
    }

    // Trap focus within modal.
    const $modal = $(this);
    const $focusableElements = $modal.find(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    );
    const $firstElement = $focusableElements.first();
    const $lastElement = $focusableElements.last();

    if (event.which === 9) {
      // Tab key
      if (event.shiftKey) {
        // Shift+Tab - move to previous element
        if ($(event.target).is($firstElement)) {
          $lastElement.focus();
          event.preventDefault();
        }
      } else if ($(event.target).is($lastElement)) {
        // Tab - move to next element
        $firstElement.focus();
        event.preventDefault();
      }
    }
  });

  // =============================================================================
  // DRUPAL DIALOG EVENT HANDLING FOR ACCESSIBILITY
  // =============================================================================

  /**
   * Enhances CAS protection modals with proper ARIA attributes using Drupal's event system.
   *
   * This event listener uses Drupal's built-in dialog:aftercreate event to properly set
   * aria-modal="true" on the outer dialog container, following Drupal best practices
   * instead of manual DOM manipulation.
   */
  $(window).on("dialog:aftercreate", function (event, dialog, $element) {
    // Only apply to CAS protection modals
    if ($element.hasClass(modalConfig.classes.modalContent)) {
      const $dialogContainer = $element.parent(".ui-dialog");
      if ($dialogContainer.length) {
        $dialogContainer.attr("aria-modal", "true");
      }
    }
  });

  /**
   * Enhanced dialog close event handling to ensure body scroll is properly restored.
   *
   * This event listener uses Drupal's dialog:beforeclose and dialog:afterclose events
   * to provide additional scroll restoration for CAS protection modals, ensuring
   * scrolling is reliably restored even if the standard cleanup fails.
   */
  $(window).on("dialog:beforeclose", function (event, dialog, $element) {
    // Only apply to CAS protection modals
    if (
      $element &&
      $element.hasClass &&
      $element.hasClass(modalConfig.classes.modalContent)
    ) {
      // Ensure scroll restoration happens immediately before close
      Drupal.casProtectionModal.restoreBodyScroll();
    }
  });

  $(window).on("dialog:afterclose", function (event, dialog, $element) {
    // Only apply to CAS protection modals
    if (
      $element &&
      $element.hasClass &&
      $element.hasClass(modalConfig.classes.modalContent)
    ) {
      // Additional scroll restoration after close as failsafe
      setTimeout(function () {
        Drupal.casProtectionModal.restoreBodyScroll();
      }, 100);
    }
  });
})(jQuery, Drupal);
