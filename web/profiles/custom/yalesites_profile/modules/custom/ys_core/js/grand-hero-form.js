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

    // Function to update field visibility
    function updateFieldVisibility() {
      const selectedValue = displayModeSelect.val();
      
      // Hide all display mode indicators first
      $(context).find('#display-mode-text, #display-mode-image').hide();
      
      if (selectedValue === 'text') {
        $(context).find('#display-mode-text').show();
        headingWrapper.show();
        overlayWrapper.hide();
      } else if (selectedValue === 'image') {
        $(context).find('#display-mode-image').show();
        headingWrapper.hide();
        overlayWrapper.show();
      }
    }

    // Update on page load
    updateFieldVisibility();

    // Update when the display mode changes
    displayModeSelect.on('change', function() {
      updateFieldVisibility();
    });
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

})(jQuery, Drupal, once); 