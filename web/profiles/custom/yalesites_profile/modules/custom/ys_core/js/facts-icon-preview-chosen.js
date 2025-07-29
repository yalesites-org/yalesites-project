/**
 * @file
 * Facts and Figures icon preview functionality - Chosen compatible version.
 * 
 * Provides live preview of selected icons in the admin interface
 * to help users make informed choices when selecting icons.
 * 
 * This version is specifically designed to work with Chosen select enhancement.
 */

(function ($, Drupal, once) {
  'use strict';

  /**
   * Behavior for Facts and Figures icon preview (Chosen compatible).
   */
  Drupal.behaviors.factsIconPreviewChosen = {
    attach: function (context, settings) {
      
      // Function to check if a select has icon options
      function hasIconOptions($select) {
        var hasIcons = false;
        $select.find('option').each(function() {
          var value = $(this).val();
          if (value && (
            value.includes('graduation-cap') || 
            value.includes('trophy') || 
            value.includes('globe') || 
            value.includes('book') || 
            value.includes('medal') ||
            value.includes('university') ||
            value.includes('award')
          )) {
            hasIcons = true;
            return false; // break
          }
        });
        return hasIcons;
      }
      
      // Function to initialize preview for a select element
      function initializePreview($select) {
        if (!hasIconOptions($select)) {
          return;
        }
        
        // Find the appropriate container for the preview
        var $container = $select.closest('.form-item');
        var $chosenContainer = $select.siblings('.chosen-container');
        
        // Check if wrapper already exists
        var $wrapper = $container.find('.icon-field-wrapper');
        if ($wrapper.length === 0) {
          // Create wrapper div for horizontal layout
          $wrapper = $('<div class="icon-field-wrapper"></div>');
          
          if ($chosenContainer.length > 0) {
            // Wrap the chosen container
            $chosenContainer.wrap($wrapper);
            $wrapper = $chosenContainer.parent();
          } else {
            // Wrap the select element
            $select.wrap($wrapper);
            $wrapper = $select.parent();
          }
        }
        
        // Create preview container if it doesn't exist
        var $preview = $wrapper.find('.icon-preview');
        if ($preview.length === 0) {
          $preview = $('<div class="icon-preview"><div class="preview-loading">Loading preview...</div></div>');
          $wrapper.append($preview);
        }

        // Function to update preview
        function updatePreview() {
          var selectedValue = $select.val();
          
          // Show loading state
          $preview.html('<div class="preview-loading">Loading preview...</div>')
                  .removeClass('has-icon has-fallback');
          
          // Make AJAX request to get properly rendered icon
          $.ajax({
            url: '/admin/ys_core/facts-icon-preview',
            method: 'GET',
            data: { icon: selectedValue },
            dataType: 'json',
            timeout: 5000
          })
          .done(function(response) {
            if (selectedValue && selectedValue !== '_none') {
              // Create preview element using the server-rendered icon
              var $iconContainer = $('<div class="icon-preview-icon"></div>');
              
              // Use the server-rendered icon HTML
              $iconContainer.html(response.html);
              $preview.html($iconContainer).addClass('has-icon');
            } else {
              $preview.html('<div class="no-icon-selected">No icon selected</div>')
                      .removeClass('has-icon');
            }
          })
          .fail(function(xhr, status, error) {
            console.error('Icon preview AJAX request failed:', status, error);
            
            // Fallback to text-based preview
            if (selectedValue && selectedValue !== '_none') {
              var iconText = selectedValue.replace('-solid', '').replace(/-/g, ' ');
              $preview.html('<div class="preview-fallback">[Icon: ' + iconText + ']</div>')
                      .addClass('has-fallback');
            } else {
              $preview.html('<div class="no-icon-selected">No icon selected</div>')
                      .removeClass('has-fallback');
            }
          });
        }

        // Initial preview update
        setTimeout(updatePreview, 100);

        // Handle regular select change events
        $select.on('change.factsIconPreview', updatePreview);
        
        // Handle Chosen-specific events if Chosen is present
        if ($chosenContainer.length > 0) {
          $select.on('chosen:ready.factsIconPreview', function() {
            setTimeout(updatePreview, 100);
          });
          
          $select.on('chosen:updated.factsIconPreview', function() {
            setTimeout(updatePreview, 100);
          });
        }
      }
      
      // Main processing logic
      function processSelects() {
        var $allSelects = $('select', context);
        
        // Process each select element once
        once('facts-icon-preview-chosen', $allSelects.get(), context).forEach(function (element) {
          var $select = $(element);
          initializePreview($select);
        });
      }
      
      // Process selects immediately
      processSelects();
      
      // Also process selects after a delay to catch any that were enhanced by Chosen after page load
      setTimeout(function() {
        // Look for selects that may have been enhanced by Chosen
        $('select', context).each(function() {
          var $select = $(this);
          var $chosenContainer = $select.siblings('.chosen-container');
          
          // If this select has been enhanced by Chosen but we haven't processed it yet
          if ($chosenContainer.length > 0 && hasIconOptions($select)) {
            once('facts-icon-preview-chosen-delayed', [this], context).forEach(function(element) {
              initializePreview($(element));
            });
          }
        });
      }, 1000);
    }
  };

})(jQuery, Drupal, once);
