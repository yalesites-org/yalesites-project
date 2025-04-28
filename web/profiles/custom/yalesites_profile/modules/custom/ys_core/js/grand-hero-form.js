/**
 * @file
 * JavaScript for the Grand Hero block form.
 */

(function ($, Drupal, once) {
  'use strict';

  /**
   * Grand Hero Form namespace.
   */
  const GrandHeroForm = {
    /**
     * Configuration options.
     */
    config: {
      maxRetries: 10,
      retryDelay: 300,
      defaultDisplayMode: 'text',
      defaultHeadingText: 'Heading text goes here',
      debug: true
    },

    /**
     * Cache for jQuery selectors.
     */
    selectors: {
      displayMode: 'select[name="settings[block_form][field_display_mode]"], #edit-settings-block-form-field-display-mode, .field--name-field-display-mode select, select[name*="field_display_mode"]',
      headingField: 'input[name*="field_heading"], textarea[name*="field_heading"], .field--name-field-heading input, .field--name-field-heading textarea',
      overlayField: 'input[name*="field_overlay"], textarea[name*="field_overlay"], .field--name-field-overlay input, .field--name-field-overlay textarea',
      overlayLegend: 'legend:contains("Banner Overlay PNG"), legend:contains("Overlay")',
      formWrapper: '.form-wrapper, .js-form-wrapper, .field--type-text, .field--type-text-long',
      formItem: '.form-item, .js-form-item',
      requiredClass: '.js-form-required, .form-required',
      textFormatWrapper: '.js-text-format-wrapper',
      requiredIndicator: '.form-required',
      errorClass: '.error, .has-error',
      errorMessage: '.error-message, .form-error-message'
    },

    /**
     * Log a debug message if debugging is enabled.
     *
     * @param {string} message - The message to log.
     */
    log: function(message) {
      if (this.config.debug) {
        console.debug('Grand Hero Form: ' + message);
      }
    },

    /**
     * Initialize the Grand Hero form.
     *
     * @param {jQuery} $context - The context element.
     */
    init: function($context) {
      try {
        this.log('Initializing form');
        
        // Find the display mode select
        const $displayModeSelect = this.findDisplayModeSelect($context);
        if (!$displayModeSelect.length) {
          this.log('Display mode select not found');
          return;
        }
        this.log('Display mode select found');

        // Set default value if not already set
        if (!$displayModeSelect.val()) {
          $displayModeSelect.val(this.config.defaultDisplayMode);
          this.log('Set default display mode to: ' + this.config.defaultDisplayMode);
        }

        // Find the heading and overlay wrappers
        const $headingWrapper = this.findHeadingWrapper($context);
        const $overlayWrapper = this.findOverlayWrapper($context);

        if (!$headingWrapper.length) {
          this.log('Heading wrapper not found');
        } else {
          this.log('Heading wrapper found');
        }

        if (!$overlayWrapper.length) {
          this.log('Overlay wrapper not found');
        } else {
          this.log('Overlay wrapper found');
        }

        if (!$headingWrapper.length || !$overlayWrapper.length) {
          this.log('Cannot initialize: missing required wrappers');
          return;
        }

        // Find the input fields
        const $headingInput = $headingWrapper.find(this.selectors.headingField);
        const $headingFormItem = $headingInput.closest(this.selectors.formItem);
        const $overlayInput = $overlayWrapper.find(this.selectors.overlayField);
        const $overlayFormItem = $overlayInput.closest(this.selectors.formItem);

        if (!$headingInput.length) {
          this.log('Heading input not found');
        } else {
          this.log('Heading input found');
        }

        if (!$overlayInput.length) {
          this.log('Overlay input not found');
        } else {
          this.log('Overlay input found');
        }

        // Set up the form
        this.setupFormValidation($context, $displayModeSelect, $headingInput, $headingFormItem, $overlayInput, $overlayFormItem);
        
        // Update field visibility based on current display mode
        this.updateFieldVisibility($displayModeSelect, $headingWrapper, $overlayWrapper, $headingInput, $headingFormItem, $overlayInput, $overlayFormItem);

        // Update when the display mode changes
        $displayModeSelect.on('change', () => {
          this.updateFieldVisibility($displayModeSelect, $headingWrapper, $overlayWrapper, $headingInput, $headingFormItem, $overlayInput, $overlayFormItem);
        });

        this.log('Initialized successfully');
      } catch (error) {
        console.error('Grand Hero Form: Error during initialization', error);
      }
    },

    /**
     * Find the display mode select element.
     *
     * @param {jQuery} $context - The context element.
     * @return {jQuery} The display mode select element.
     */
    findDisplayModeSelect: function($context) {
      // Try to find by our selectors
      let $select = $context.find(this.selectors.displayMode);
      
      // If not found, try a more aggressive approach
      if (!$select.length) {
        this.log('Trying alternative methods to find display mode select');
        
        // Look for any select that might be the display mode
        $select = $context.find('select').filter(function() {
          const $this = $(this);
          const name = $this.attr('name') || '';
          const id = $this.attr('id') || '';
          const label = $this.closest('.form-item').find('label').text() || '';
          
          return name.includes('display_mode') || 
                 id.includes('display_mode') || 
                 label.toLowerCase().includes('display mode');
        });
      }
      
      return $select;
    },

    /**
     * Find the heading wrapper element.
     *
     * @param {jQuery} $context - The context element.
     * @return {jQuery} The heading wrapper element.
     */
    findHeadingWrapper: function($context) {
      // Try to find by our selectors
      let $wrapper = $context.find(this.selectors.headingField).closest(this.selectors.formWrapper);
      
      // If not found, try a more aggressive approach
      if (!$wrapper.length) {
        this.log('Trying alternative methods to find heading wrapper');
        
        // Look for any field that might be the heading
        $wrapper = $context.find('.field--name-field-heading, .field--name-heading, [data-drupal-selector*="heading"]')
          .closest(this.selectors.formWrapper);
          
        // If still not found, try to find by label text
        if (!$wrapper.length) {
          $wrapper = $context.find('label').filter(function() {
            return $(this).text().toLowerCase().includes('heading');
          }).closest(this.selectors.formWrapper);
        }
      }
      
      return $wrapper;
    },

    /**
     * Find the overlay wrapper element.
     *
     * @param {jQuery} $context - The context element.
     * @return {jQuery} The overlay wrapper element.
     */
    findOverlayWrapper: function($context) {
      // Try to find by our selectors
      const $overlayByInput = $context.find(this.selectors.overlayField).closest(this.selectors.formWrapper);
      const $overlayByLegend = $context.find(this.selectors.overlayLegend).closest(this.selectors.formWrapper);
      
      let $wrapper = $overlayByInput.length ? $overlayByInput : $overlayByLegend;
      
      // If not found, try a more aggressive approach
      if (!$wrapper.length) {
        this.log('Trying alternative methods to find overlay wrapper');
        
        // Look for any field that might be the overlay
        $wrapper = $context.find('.field--name-field-overlay, .field--name-overlay, [data-drupal-selector*="overlay"]')
          .closest(this.selectors.formWrapper);
          
        // If still not found, try to find by label text
        if (!$wrapper.length) {
          $wrapper = $context.find('label').filter(function() {
            return $(this).text().toLowerCase().includes('overlay');
          }).closest(this.selectors.formWrapper);
        }
      }
      
      return $wrapper;
    },

    /**
     * Update field visibility based on display mode.
     *
     * @param {jQuery} $displayModeSelect - The display mode select element.
     * @param {jQuery} $headingWrapper - The heading wrapper element.
     * @param {jQuery} $overlayWrapper - The overlay wrapper element.
     * @param {jQuery} $headingInput - The heading input element.
     * @param {jQuery} $headingFormItem - The heading form item element.
     * @param {jQuery} $overlayInput - The overlay input element.
     * @param {jQuery} $overlayFormItem - The overlay form item element.
     */
    updateFieldVisibility: function($displayModeSelect, $headingWrapper, $overlayWrapper, $headingInput, $headingFormItem, $overlayInput, $overlayFormItem) {
      const selectedValue = $displayModeSelect.val();
      this.log('Updating field visibility for mode: ' + selectedValue);
      
      if (selectedValue === 'text') {
        this.showTextMode($headingWrapper, $overlayWrapper, $headingInput, $headingFormItem, $overlayInput, $overlayFormItem);
      } else if (selectedValue === 'image') {
        this.showImageMode($headingWrapper, $overlayWrapper, $headingInput, $headingFormItem, $overlayInput, $overlayFormItem);
      }
    },

    /**
     * Show text mode.
     *
     * @param {jQuery} $headingWrapper - The heading wrapper element.
     * @param {jQuery} $overlayWrapper - The overlay wrapper element.
     * @param {jQuery} $headingInput - The heading input element.
     * @param {jQuery} $headingFormItem - The heading form item element.
     * @param {jQuery} $overlayInput - The overlay input element.
     * @param {jQuery} $overlayFormItem - The overlay form item element.
     */
    showTextMode: function($headingWrapper, $overlayWrapper, $headingInput, $headingFormItem, $overlayInput, $overlayFormItem) {
      this.log('Showing text mode');
      $headingWrapper.show();
      $overlayWrapper.hide();
      
      // Make heading required when visible
      if ($headingInput.length) {
        this.makeFieldRequired($headingInput, $headingFormItem, $headingWrapper);
        
        // If we have a stored value that's not the override text, restore it
        const storedValue = $headingInput.data('stored-value');
        if (storedValue && storedValue !== this.config.defaultHeadingText) {
          $headingInput.val(storedValue);
        } else if (!$headingInput.val()) {
          // If no stored value and no current value, set the default
          $headingInput.val(this.config.defaultHeadingText);
        }
      }
      
      // Make overlay not required when hidden
      if ($overlayInput.length) {
        this.makeFieldNotRequired($overlayInput, $overlayFormItem, $overlayWrapper);
      }
    },

    /**
     * Show image mode.
     *
     * @param {jQuery} $headingWrapper - The heading wrapper element.
     * @param {jQuery} $overlayWrapper - The overlay wrapper element.
     * @param {jQuery} $headingInput - The heading input element.
     * @param {jQuery} $headingFormItem - The heading form item element.
     * @param {jQuery} $overlayInput - The overlay input element.
     * @param {jQuery} $overlayFormItem - The overlay form item element.
     */
    showImageMode: function($headingWrapper, $overlayWrapper, $headingInput, $headingFormItem, $overlayInput, $overlayFormItem) {
      this.log('Showing image mode');
      $headingWrapper.hide();
      $overlayWrapper.show();
      
      // Store the current heading value before hiding
      if ($headingInput.length) {
        const currentHeadingValue = $headingInput.val();
        
        // Only store the value if it's not the override text
        if (currentHeadingValue && currentHeadingValue !== this.config.defaultHeadingText) {
          $headingInput.data('stored-value', currentHeadingValue);
        }
        
        this.makeFieldNotRequired($headingInput, $headingFormItem, $headingWrapper);
        
        // Set the default value instead of clearing it
        $headingInput.val(this.config.defaultHeadingText);
      }
      
      // Make overlay required when visible
      if ($overlayInput.length) {
        this.makeFieldRequired($overlayInput, $overlayFormItem, $overlayWrapper);
      }
    },

    /**
     * Make a field required.
     *
     * @param {jQuery} $input - The input element.
     * @param {jQuery} $formItem - The form item element.
     * @param {jQuery} $wrapper - The wrapper element.
     */
    makeFieldRequired: function($input, $formItem, $wrapper) {
      // Add all possible required attributes and classes
      $input.prop('required', true);
      $input.attr('required', 'required');
      $input.attr('aria-required', 'true');
      
      // Update all form item classes without duplicating
      $formItem.removeClass('js-form-required form-required');
      $formItem.addClass('js-form-required form-required');
      
      // Also update the wrapper classes
      $wrapper.find(this.selectors.requiredClass).removeClass('js-form-required form-required');
      $wrapper.find(this.selectors.textFormatWrapper).addClass('js-form-required form-required');
      
      // Also update the label to show required indicator
      const $label = $formItem.find('label');
      if ($label.length) {
        // Remove any existing required indicators first
        $label.find(this.selectors.requiredIndicator).remove();
        // Then add a single required indicator
        $label.append('<span class="form-required" title="This field is required." style="display:none;">*</span>');
      }
    },

    /**
     * Make a field not required.
     *
     * @param {jQuery} $input - The input element.
     * @param {jQuery} $formItem - The form item element.
     * @param {jQuery} $wrapper - The wrapper element.
     */
    makeFieldNotRequired: function($input, $formItem, $wrapper) {
      // Remove all possible required attributes and classes
      $input.prop('required', false);
      $input.removeAttr('required');
      $input.removeAttr('aria-required');
      $input.removeClass('error');
      
      // Update all form item classes without duplicating
      $formItem.removeClass('js-form-required form-required has-error');
      
      // Also update the wrapper classes
      $wrapper.find(this.selectors.requiredClass).removeClass('js-form-required form-required');
      
      // Remove required indicator from label
      $formItem.find('label ' + this.selectors.requiredIndicator).remove();
      
      // Also remove any validation messages
      $formItem.find(this.selectors.errorMessage).remove();
    },

    /**
     * Set up form validation.
     *
     * @param {jQuery} $context - The context element.
     * @param {jQuery} $displayModeSelect - The display mode select element.
     * @param {jQuery} $headingInput - The heading input element.
     * @param {jQuery} $headingFormItem - The heading form item element.
     * @param {jQuery} $overlayInput - The overlay input element.
     * @param {jQuery} $overlayFormItem - The overlay form item element.
     */
    setupFormValidation: function($context, $displayModeSelect, $headingInput, $headingFormItem, $overlayInput, $overlayFormItem) {
      const $form = $context.closest('form');
      if (!$form.length) {
        this.log('Form not found for validation setup');
        return;
      }

      this.log('Setting up form validation');

      // Create a hidden field to override validation
      const $overrideField = $('<input>')
        .attr('type', 'hidden')
        .attr('name', 'settings[block_form][field_heading][0][value]')
        .attr('id', 'heading-override-field')
        .addClass('heading-override-field');
      
      // Add the override field to the form
      $form.append($overrideField);
      
      // Handle form submission
      $form.on('submit', () => {
        const selectedValue = $displayModeSelect.val();
        this.log('Form submitted with display mode: ' + selectedValue);
        
        // If in image mode, set the override field value
        if (selectedValue === 'image') {
          // Set a default value for the override field
          $overrideField.val(this.config.defaultHeadingText);
          
          // Remove all possible required attributes and classes from the heading input
          if ($headingInput.length) {
            this.makeFieldNotRequired($headingInput, $headingFormItem, $headingInput.closest(this.selectors.formWrapper));
          }
        } else {
          // In text mode, copy the heading value to the override field
          if ($headingInput.length) {
            const headingValue = $headingInput.val();
            // Always use the heading value, even if it's the default
            $overrideField.val(headingValue);
          }
        }
      });
    },

    /**
     * Attempt to initialize the Grand Hero form with retries if needed.
     * 
     * @param {jQuery} $element - The element to initialize.
     * @param {number} retryCount - Current retry count.
     * @return {boolean} Whether initialization was successful.
     */
    attemptInit: function($element, retryCount = 0) {
      this.log('Attempt ' + (retryCount + 1) + ' of ' + this.config.maxRetries);
      
      // Check if we have the necessary elements
      const hasDisplayMode = $element.find(this.selectors.displayMode).length > 0;
      const hasHeadingField = $element.find(this.selectors.headingField).length > 0;
      const hasOverlayField = $element.find(this.selectors.overlayField).length > 0;
      
      this.log('Element check: displayMode=' + hasDisplayMode + ', headingField=' + hasHeadingField + ', overlayField=' + hasOverlayField);
      
      // If all required elements are present, initialize the form
      if (hasDisplayMode && hasHeadingField && hasOverlayField) {
        this.init($element);
        return true;
      } 
      // If we haven't reached max retries, try again after a delay
      else if (retryCount < this.config.maxRetries) {
        setTimeout(() => {
          this.attemptInit($element, retryCount + 1);
        }, this.config.retryDelay);
        return false;
      }
      
      this.log('Failed to initialize after ' + this.config.maxRetries + ' attempts');
      
      // As a last resort, try to initialize with whatever elements we can find
      if (hasDisplayMode) {
        this.log('Attempting fallback initialization with display mode only');
        this.init($element);
      }
      
      return false;
    }
  };

  // Attach the behavior
  Drupal.behaviors.grandHeroForm = {
    attach: function (context, settings) {
      once('grandHeroForm', 'form', context).forEach(function (form) {
        // Try to initialize immediately
        if (!GrandHeroForm.attemptInit($(form))) {
          // If immediate initialization fails, schedule a retry after DOM is fully loaded
          $(document).ready(function() {
            GrandHeroForm.attemptInit($(form));
          });
        }
      });
    }
  };
  
  // Listen for dialog creation events
  $(document).on('dialog:aftercreate', function(event, dialog, $element, settings) {
    // Check if this is a layout builder dialog
    if ($element.find('.layout-builder-update-block').length) {
      // Check if this is a Grand Hero block
      if ($element.find('select[name*="field_display_mode"]').length) {
        // Use the retry mechanism for dialog initialization
        GrandHeroForm.attemptInit($element);
      }
    }
  });

  /**
   * Listen for AJAX events that might load the Grand Hero block form.
   */
  $(document).on('drupalAjaxComplete', function(event, xhr, settings) {
    // Check if this is a layout builder AJAX request
    if (settings.selector && settings.selector.includes('layout-builder')) {
      // Look for the Grand Hero block form in the response
      const response = xhr.responseText;
      if (response && response.includes('field_display_mode')) {
        // Use the retry mechanism for AJAX-loaded forms
        setTimeout(function() {
          const grandHeroForm = $('select[name="settings[block_form][field_display_mode]"]').closest('form');
          if (grandHeroForm.length) {
            GrandHeroForm.attemptInit(grandHeroForm);
          }
        }, 100);
      }
    }
  });

  // Run initialization on document ready to ensure proper initial state
  $(document).ready(function() {
    // Find any existing Grand Hero forms and initialize them
    $('select[name="settings[block_form][field_display_mode]"]').each(function() {
      const $select = $(this);
      // Ensure the default value is set before initialization
      if (!$select.val()) {
        $select.val(GrandHeroForm.config.defaultDisplayMode);
      }
      GrandHeroForm.attemptInit($select.closest('form'));
    });
  });

})(jQuery, Drupal, once); 