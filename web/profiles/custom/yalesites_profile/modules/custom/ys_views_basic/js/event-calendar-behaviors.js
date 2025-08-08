(function ($, Drupal, once) {
  'use strict';

  /**
   * Event Calendar Filter Form behaviors.
   */
  Drupal.behaviors.eventCalendarFilters = {
    attach: function (context, settings) {
      // Find all select elements in the filter form that haven't been processed
      const elements = once('event-calendar-select', '.ys-filter-form select', context);

      elements.forEach(function(selectElement) {
        var $select = $(selectElement);

        // Store current values before any reinitialization
        var currentValues = $select.val();

        // Handle Chosen.js first
        if ($select.hasClass('chosen-enable')) {
          if ($select.data('chosen')) {
            $select.trigger('chosen:updated');
          } else {
            if ($.fn.chosen) {
              $select.chosen();
            }
          }

          // Restore values after chosen initialization
          if (currentValues && currentValues.length > 0) {
            $select.val(currentValues).trigger('chosen:updated');
          }

          // Now handle YDS select message functionality
          var $chosenContainer = $select.next('.chosen-container');
          if ($chosenContainer.length > 0) {
            var $chosenChoices = $chosenContainer.find('.chosen-choices');
            var $existingMessage = $chosenChoices.find('.ys-select-message');

            // Remove existing message span if it exists
            $existingMessage.remove();

            // Add the message span as the first child
            $chosenChoices.prepend('<span class="ys-select-message"></span>');

            // Update the message with current selection count
            var selectedCount = currentValues ? currentValues.length : 0;
            var selectMessage = selectedCount ? `(${selectedCount}) items selected` : '';
            $chosenChoices.find('.ys-select-message').text(selectMessage);

            // Re-bind the change event to update the message
            $select.off('change.yds-select').on('change.yds-select', function(e) {
              var selectedNr = $(e.target).val().length;
              var message = selectedNr ? `(${selectedNr}) items selected` : '';
              $(e.target).next().find('.ys-select-message').text(message);
            });
          }
        }
      });
    }
  };

  /**
   * Custom behavior to handle calendar AJAX updates.
   */
  Drupal.behaviors.eventCalendarAjaxHandler = {
    attach: function (context, settings) {
      // Listen for the custom trigger event from AJAX command
      $(context).on('drupal:attach_behaviors', function() {
        // This will automatically trigger all Drupal behaviors on the updated context
        Drupal.attachBehaviors(this);
      });
    }
  };

})(jQuery, Drupal, once);
