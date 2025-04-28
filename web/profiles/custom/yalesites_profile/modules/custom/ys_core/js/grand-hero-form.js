/**
 * @file
 * JavaScript for the Grand Hero block form.
 */

(function ($, Drupal, once) {
  'use strict';

  /**
   * Initialize the Grand Hero form.
   */
  function initGrandHeroForm(context) {
    // Try different selectors for the display mode select
    const displayModeSelectByName = $(context).find('select[name="settings[block_form][field_display_mode]"]');
    const displayModeSelectById = $(context).find('#edit-settings-block-form-field-display-mode');
    const displayModeSelectByClass = $(context).find('.field--name-field-display-mode select');
    
    // Use the first one that works
    const displayModeSelect = displayModeSelectByName.length ? displayModeSelectByName : 
                             displayModeSelectById.length ? displayModeSelectById : 
                             displayModeSelectByClass.length ? displayModeSelectByClass : null;
    
    if (!displayModeSelect || !displayModeSelect.length) {
      return;
    }

    // Set default value to 'text' if not already set
    if (!displayModeSelect.val()) {
      displayModeSelect.val('text');
    }
    
    // Try different selectors for the heading wrapper
    // Look for the heading field by its input name
    const headingWrapperByInput = $(context).find('input[name*="field_heading"], textarea[name*="field_heading"]').closest('.form-wrapper, .js-form-wrapper');
    
    // Try different selectors for the overlay wrapper
    // Look for the overlay field by its input name
    const overlayWrapperByInput = $(context).find('input[name*="field_overlay"], textarea[name*="field_overlay"]').closest('.form-wrapper, .js-form-wrapper');
    
    // If we can't find by input name, try by fieldset legend
    const overlayWrapperByLegend = $(context).find('legend:contains("Banner Overlay PNG")').closest('.form-wrapper, .js-form-wrapper');
    
    // Use the first one that works
    const headingWrapper = headingWrapperByInput.length ? headingWrapperByInput : null;
    const overlayWrapper = overlayWrapperByInput.length ? overlayWrapperByInput : 
                          overlayWrapperByLegend.length ? overlayWrapperByLegend : null;
    
    if (!headingWrapper || !headingWrapper.length) {
      return;
    }
    
    if (!overlayWrapper || !overlayWrapper.length) {
      return;
    }

    // Find the heading input field to toggle required attribute
    const headingInput = headingWrapper.find('input, textarea');
    const headingFormItem = headingInput.closest('.form-item');
    
    // Find the overlay input field
    const overlayInput = overlayWrapper.find('input, textarea');
    const overlayFormItem = overlayInput.closest('.form-item');
    
    // Function to update field visibility
    function updateFieldVisibility() {
      const selectedValue = displayModeSelect.val();
      
      if (selectedValue === 'text') {
        headingWrapper.show();
        overlayWrapper.hide();
        
        // Make heading required when visible
        if (headingInput.length) {
          // Add all possible required attributes and classes
          headingInput.prop('required', true);
          headingInput.attr('required', 'required');
          headingInput.attr('aria-required', 'true');
          
          // Update all form item classes without duplicating
          headingFormItem.removeClass('js-form-required form-required');
          headingFormItem.addClass('js-form-required form-required');
          
          // Also update the wrapper classes
          headingWrapper.find('.js-form-required, .form-required').removeClass('js-form-required form-required');
          headingWrapper.find('.js-text-format-wrapper').addClass('js-form-required form-required');
          
          // Also update the label to show required indicator
          const headingLabel = headingFormItem.find('label');
          if (headingLabel.length) {
            // Remove any existing required indicators first
            headingLabel.find('.form-required').remove();
            // Then add a single required indicator
            headingLabel.append('<span class="form-required" title="This field is required.">*</span>');
          }
          
          // If we have a stored value that's not the override text, restore it
          const storedValue = headingInput.data('stored-value');
          if (storedValue && storedValue !== 'Image Mode - No Heading Required') {
            headingInput.val(storedValue);
          }
        }
        
        // Make overlay not required when hidden
        if (overlayInput.length) {
          // Remove all possible required attributes and classes
          overlayInput.prop('required', false);
          overlayInput.removeAttr('required');
          overlayInput.removeAttr('aria-required');
          overlayFormItem.removeClass('js-form-required form-required');
          
          // Also update the wrapper classes
          overlayWrapper.find('.js-form-required, .form-required').removeClass('js-form-required form-required');
          
          // Remove required indicator from label
          overlayFormItem.find('label .form-required').remove();
        }
      } else if (selectedValue === 'image') {
        headingWrapper.hide();
        overlayWrapper.show();
        
        // Store the current heading value before hiding
        if (headingInput.length) {
          const currentHeadingValue = headingInput.val();
          
          // Only store the value if it's not the override text
          if (currentHeadingValue && currentHeadingValue !== 'Image Mode - No Heading Required') {
            headingInput.data('stored-value', currentHeadingValue);
          }
          
          // Remove all possible required attributes and classes
          headingInput.prop('required', false);
          headingInput.removeAttr('required');
          headingInput.removeAttr('aria-required');
          headingInput.removeClass('error');
          
          // Update all form item classes without duplicating
          headingFormItem.removeClass('js-form-required form-required has-error');
          
          // Also update the wrapper classes
          headingWrapper.find('.js-form-required, .form-required').removeClass('js-form-required form-required');
          
          // Remove required indicator from label
          headingFormItem.find('label .form-required').remove();
          
          // Also remove any validation messages
          headingFormItem.find('.error-message, .form-error-message').remove();
          
          // Clear the visible value but keep the stored value
          headingInput.val('');
        }
        
        // Make overlay required when visible
        if (overlayInput.length) {
          // Add all possible required attributes and classes
          overlayInput.prop('required', true);
          overlayInput.attr('required', 'required');
          overlayInput.attr('aria-required', 'true');
          
          // Update all form item classes without duplicating
          overlayFormItem.removeClass('js-form-required form-required');
          overlayFormItem.addClass('js-form-required form-required');
          
          // Also update the wrapper classes
          overlayWrapper.find('.js-form-required, .form-required').removeClass('js-form-required form-required');
          overlayWrapper.find('.js-text-format-wrapper').addClass('js-form-required form-required');
          
          // Also update the label to show required indicator
          const overlayLabel = overlayFormItem.find('label');
          if (overlayLabel.length) {
            // Remove any existing required indicators first
            overlayLabel.find('.form-required').remove();
            // Then add a single required indicator
            overlayLabel.append('<span class="form-required" title="This field is required.">*</span>');
          }
        }
      }
    }

    // Update on page load
    updateFieldVisibility();

    // Update when the display mode changes
    displayModeSelect.on('change', function() {
      updateFieldVisibility();
    });
    
    // Add form validation handler
    const form = $(context).closest('form');
    if (form.length) {
      // Create a hidden field to override validation
      const overrideField = $('<input>')
        .attr('type', 'hidden')
        .attr('name', 'settings[block_form][field_heading][0][value]')
        .attr('id', 'heading-override-field')
        .addClass('heading-override-field');
      
      // Add the override field to the form
      form.append(overrideField);
      
      // Handle form submission
      form.on('submit', function(e) {
        const selectedValue = displayModeSelect.val();
        
        // If in image mode, set the override field value
        if (selectedValue === 'image') {
          // Set a default value for the override field
          overrideField.val('Image Mode - No Heading Required');
          
          // Remove all possible required attributes and classes from the heading input
          if (headingInput.length) {
            headingInput.prop('required', false);
            headingInput.removeAttr('required');
            headingInput.removeAttr('aria-required');
            headingInput.removeClass('error');
            
            // Update all form item classes without duplicating
            headingFormItem.removeClass('js-form-required form-required has-error');
            
            // Also update the wrapper classes
            headingWrapper.find('.js-form-required, .form-required').removeClass('js-form-required form-required');
            
            // Remove required indicator from label
            headingFormItem.find('label .form-required').remove();
            
            // Also remove any validation messages
            headingFormItem.find('.error-message, .form-error-message').remove();
          }
        } else {
          // In text mode, copy the heading value to the override field
          if (headingInput.length) {
            const headingValue = headingInput.val();
            // Only use the actual heading value, not the override text
            if (headingValue && headingValue !== 'Image Mode - No Heading Required') {
              overrideField.val(headingValue);
            } else {
              overrideField.val('');
            }
          }
        }
      });
    }
  }

  // Attach the behavior
  Drupal.behaviors.grandHeroForm = {
    attach: function (context, settings) {
      once('grandHeroForm', 'form', context).forEach(function (form) {
        initGrandHeroForm(form);
      });
    }
  };
  
  // Listen for dialog creation events
  $(document).on('dialog:aftercreate', function(event, dialog, $element, settings) {
    // Check if this is a layout builder dialog
    if ($element.find('.layout-builder-update-block').length) {
      // Check if this is a Grand Hero block
      if ($element.find('select[name*="field_display_mode"]').length) {
        initGrandHeroForm($element);
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
        // Wait a bit for the DOM to update
        setTimeout(function() {
          const grandHeroForm = $('select[name="settings[block_form][field_display_mode]"]').closest('form');
          if (grandHeroForm.length) {
            initGrandHeroForm(grandHeroForm);
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
        $select.val('text');
      }
      initGrandHeroForm($select.closest('form'));
    });
  });

})(jQuery, Drupal, once); 